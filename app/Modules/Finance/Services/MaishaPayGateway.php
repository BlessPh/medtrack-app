<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\PaymentGateway;

class MaishaPayGateway implements PaymentGateway
{
    public function create(string $reference, string $amount, string $currency): array
    {
        return ['reference' => $reference, 'status' => 'PENDING'];
    }

    public function validSignature(string $payload, ?string $signature): bool
    {
        $secret = (string) config('services.maishapay.webhook_secret');

        return $secret !== '' && is_string($signature) && hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    public function refund(string $reference, string $amount): array
    {
        return ['reference' => 'refund-'.$reference, 'status' => 'PROCESSED'];
    }
}
