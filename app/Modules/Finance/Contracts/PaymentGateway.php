<?php

namespace App\Modules\Finance\Contracts;

interface PaymentGateway
{
    public function create(string $reference, string $amount, string $currency): array;

    public function validSignature(string $payload, ?string $signature): bool;

    public function refund(string $reference, string $amount): array;
}
