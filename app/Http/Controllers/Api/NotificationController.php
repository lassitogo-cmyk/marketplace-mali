<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Les 50 dernières notifications du compte connecté. */
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->notifications()->whereNull('read_at')->count(),
            'data'   => $user->notifications()
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    /**
     * Sonde légère appelée par l'application toutes les 20 secondes :
     * nombre de non-lues + identifiant de la dernière notification.
     */
    public function count(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'unread'    => $user->notifications()->whereNull('read_at')->count(),
            'latest_id' => (int) $user->notifications()->max('id'),
        ]);
    }

    public function read(Request $request, Notification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);

        if ($notification->read_at === null) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request)
    {
        $request->user()->notifications()->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, Notification $notification)
    {
        abort_if($notification->user_id !== $request->user()->id, 403);
        $notification->delete();

        return response()->json(['ok' => true]);
    }
}
