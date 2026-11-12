<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $titre }}</title>
</head>
<body style="margin:0;padding:0;background:#F3F4F6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#111827">
  <div style="max-width:560px;margin:0 auto;padding:18px">
    <div style="background:#15803D;color:#fff;border-radius:14px 14px 0 0;padding:16px 20px">
      <div style="font-size:20px;font-weight:800">🛍️ Marketplace Mali</div>
      <div style="font-size:13px;opacity:.9">Bamako — marché en ligne</div>
    </div>

    <div style="background:#fff;padding:20px;border-radius:0 0 14px 14px">
      <h2 style="margin:0 0 10px;font-size:18px;color:#111827">{{ $titre }}</h2>

      @if($corps)
        <p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#374151">{!! nl2br(e($corps)) !!}</p>
      @endif

      @if($order)
        <table style="width:100%;border-collapse:collapse;font-size:14px;margin:12px 0">
          <tr>
            <td style="padding:6px 0;color:#6B7280">Référence</td>
            <td style="padding:6px 0;text-align:right;font-weight:700">{{ $order->reference }}</td>
          </tr>
          <tr>
            <td style="padding:6px 0;color:#6B7280">Total</td>
            <td style="padding:6px 0;text-align:right;font-weight:700">{{ number_format($order->total, 0, ',', ' ') }} FCFA</td>
          </tr>
          <tr>
            <td style="padding:6px 0;color:#6B7280">Livraison</td>
            <td style="padding:6px 0;text-align:right">{{ $order->address }}</td>
          </tr>
          <tr>
            <td style="padding:6px 0;color:#6B7280">Téléphone</td>
            <td style="padding:6px 0;text-align:right">{{ $order->phone }}</td>
          </tr>
        </table>
      @endif

      <p style="margin:18px 0 0;font-size:13px;color:#6B7280">
        Connectez-vous à Marketplace Mali pour suivre la commande.
      </p>
    </div>

    <p style="text-align:center;font-size:12px;color:#9CA3AF;margin:14px 0">
      Marketplace Mali · Bamako
    </p>
  </div>
</body>
</html>
