<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Envoi de SMS.
 *
 * Fournisseur par défaut : "log" → le message est écrit dans les journaux
 * (aucun compte opérateur nécessaire, utile pour tester).
 *
 * Pour envoyer de vrais SMS au Mali, renseigner dans .env / Render :
 *   SMS_DRIVER=orange
 *   ORANGE_SMS_CLIENT_ID=...
 *   ORANGE_SMS_CLIENT_SECRET=...
 *   ORANGE_SMS_SENDER=Marketplace
 * (contrat Orange SMS API — https://developer.orange.com)
 */
class Sms
{
    public function send(?string $phone, string $message): bool
    {
        $phone = $this->normalize($phone);

        if ($phone === null) {
            return false;
        }

        $driver = config('services.sms.driver', 'log');

        try {
            return match ($driver) {
                'orange' => $this->orange($phone, $message),
                default  => $this->log($phone, $message),
            };
        } catch (\Throwable $e) {
            Log::warning('SMS non envoyé : '.$e->getMessage());

            return false;
        }
    }

    /**
     * Numéro au format international malien : 223XXXXXXXX.
     */
    public function normalize(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 8) {
            $digits = '223'.$digits;
        }

        return strlen($digits) >= 8 ? $digits : null;
    }

    protected function log(string $phone, string $message): bool
    {
        Log::info("SMS → +{$phone} : {$message}");

        return true;
    }

    protected function orange(string $phone, string $message): bool
    {
        $id     = config('services.sms.orange.client_id');
        $secret = config('services.sms.orange.client_secret');
        $sender = config('services.sms.orange.sender', 'Marketplace');

        if (! $id || ! $secret) {
            return $this->log($phone, $message);
        }

        $token = Http::asForm()
            ->withBasicAuth($id, $secret)
            ->post('https://api.orange.com/oauth/v3/token', [
                'grant_type' => 'client_credentials',
            ])
            ->throw()
            ->json('access_token');

        Http::withToken($token)
            ->post('https://api.orange.com/smsmessaging/v1/outbound/'.rawurlencode('tel:+'.$sender).'/requests', [
                'outboundSMSMessageRequest' => [
                    'address'                => 'tel:+'.$phone,
                    'senderAddress'          => 'tel:+'.$sender,
                    'outboundSMSTextMessage' => ['message' => mb_substr($message, 0, 320)],
                ],
            ])
            ->throw();

        return true;
    }
}
