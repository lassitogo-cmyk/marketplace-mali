<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(protected Notifier $notifier) {}

    /**
     * Commandes du client connecté.
     */
    public function index(Request $request)
    {
        $orders = $request->user()->orders()
            ->with('items:id,order_id,product_name,variant,qty,unit_price,subtotal')
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json($orders);
    }

    /**
     * Passer commande à partir d'un panier.
     * body : { address, phone, note?, items: [{ product_id, qty, variant? }] }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'address'            => ['required', 'string', 'min:5', 'max:255'],
            'phone'              => ['required', 'string', 'min:6', 'max:30'],
            'note'               => ['nullable', 'string', 'max:500'],
            'items'              => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty'        => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.variant'    => ['nullable', 'string', 'max:120'],
        ]);

        $items = collect($data['items']);

        $products = Product::where('is_active', true)
            ->whereIn('id', $items->pluck('product_id')->unique()->all())
            // On ne peut commander que chez un vendeur dont l'abonnement est actif.
            ->whereHas('store.user.subscriptions', function ($q) {
                $q->where('status', 'actif')->where('ends_at', '>', now());
            })
            ->get()
            ->keyBy('id');

        $lines = [];
        $total = 0;
        $stockNeeded = [];

        foreach ($items as $line) {
            $product = $products->get((int) $line['product_id']);

            if ($product === null) {
                return response()->json(['message' => "Un produit du panier n'est plus disponible."], 422);
            }

            $qty = (int) $line['qty'];
            $stockNeeded[$product->id] = ($stockNeeded[$product->id] ?? 0) + $qty;

            if ($product->stock < $stockNeeded[$product->id]) {
                return response()->json([
                    'message' => "Stock insuffisant pour « {$product->name} » (reste {$product->stock}).",
                ], 422);
            }

            $subtotal  = $product->price * $qty;
            $total    += $subtotal;
            $lines[]   = [
                'product'  => $product,
                'qty'      => $qty,
                'variant'  => $line['variant'] ?? null,
                'subtotal' => $subtotal,
            ];
        }

        $order = $request->user()->orders()->create([
            'reference' => 'CMD-' . now()->format('ymdHis') . '-' . random_int(10, 99),
            'status'    => 'en_attente',
            'total'     => $total,
            'address'   => $data['address'],
            'phone'     => $data['phone'],
            'note'      => $data['note'] ?? null,
        ]);

        foreach ($lines as $line) {
            $product = $line['product'];

            $order->items()->create([
                'product_id'   => $product->id,
                'store_id'     => $product->store_id,
                'product_name' => $product->name,
                'variant'      => $line['variant'],
                'qty'          => $line['qty'],
                'unit_price'   => $product->price,
                'subtotal'     => $line['subtotal'],
            ]);
        }

        foreach ($stockNeeded as $productId => $qty) {
            Product::whereKey($productId)->decrement('stock', $qty);
        }

        $order->load('items.store:id,name,user_id,phone');

        $this->notifier->toMany(
            $this->vendorsOf($order),
            'order_created',
            'Nouvelle commande 📥',
            "{$request->user()->name} a commandé {$order->items->count()} article(s) pour ".number_format($order->total, 0, ',', ' ')." FCFA. Confirmez la commande dans votre espace.",
            $order,
            ['order_id' => $order->id]
        );

        $this->notifier->toMany(
            $this->notifier->admins(),
            'order_created',
            'Nouvelle commande sur le marché',
            "Commande {$order->reference} de {$request->user()->name} — ".number_format($order->total, 0, ',', ' ').' FCFA.',
            $order
        );

        return response()->json($order->load('items'), 201);
    }

    /**
     * Le client déclare son paiement Orange Money (identifiant de transaction).
     * body : { transaction_id }
     */
    public function payment(Request $request, Order $order)
    {
        $user = $request->user();

        if ($order->user_id !== $user->id) {
            abort(403, 'Cette commande ne vous appartient pas.');
        }

        abort_if($order->status === 'annulee', 422, 'Cette commande a été annulée.');

        $data = $request->validate([
            'transaction_id' => ['required', 'string', 'min:4', 'max:60'],
        ], [
            'transaction_id.required' => 'Indiquez l\'identifiant de la transaction Orange Money.',
            'transaction_id.min'      => 'L\'identifiant de transaction est trop court.',
        ]);

        $order->update([
            'payment_status' => 'declare',
            'payment_ref'    => $data['transaction_id'],
        ]);

        $this->notifier->toMany(
            $this->vendorsOf($order),
            'payment_declared',
            'Paiement Orange Money déclaré 💰',
            "Le client {$user->name} a payé la commande {$order->reference} (".number_format($order->total, 0, ',', ' ')." FCFA). ID transaction : {$data['transaction_id']}. Vérifiez votre compte puis confirmez le paiement.",
            $order,
            ['order_id' => $order->id]
        );

        $this->notifier->toMany(
            $this->notifier->admins(),
            'payment_declared',
            'Paiement à vérifier',
            "Commande {$order->reference} — ID transaction {$data['transaction_id']}.",
            $order
        );

        return response()->json([
            'message' => 'Paiement déclaré. Le vendeur va vérifier et confirmer.',
            'order'   => $order->fresh(['items']),
        ]);
    }

    /** Les vendeurs concernés par la commande. */
    protected function vendorsOf(Order $order)
    {
        $ids = $order->items()
            ->with('store:id,user_id')
            ->get()
            ->pluck('store.user_id')
            ->filter()
            ->unique()
            ->values();

        return User::whereIn('id', $ids)->get();
    }

    /**
     * Détail d'une commande (uniquement la sienne).
     */
    public function show(Request $request, Order $order)
    {
        if ($order->user_id !== $request->user()->id) {
            abort(403, 'Cette commande ne vous appartient pas.');
        }

        return response()->json($order->load(['items.store:id,name,phone']));
    }

    /**
     * Signaler un problème à propos d'une commande (litige avec un vendeur).
     * Le responsable du marché voit le signalement et peut intervenir.
     */
    public function report(Request $request, Order $order)
    {
        $user = $request->user();

        if ($order->user_id !== $user->id) {
            abort(403, 'Cette commande ne vous appartient pas.');
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $storeId = $order->items()->whereNotNull('store_id')->value('store_id');

        $report = Report::create([
            'user_id'  => $user->id,
            'order_id' => $order->id,
            'store_id' => $storeId,
            'message'  => $data['message'],
            'status'   => 'nouveau',
        ]);

        return response()->json([
            'message' => 'Signalement envoyé au responsable du marché.',
            'report'  => $report,
        ], 201);
    }
}
