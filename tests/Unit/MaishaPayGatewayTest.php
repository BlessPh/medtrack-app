<?php

namespace Tests\Unit;

use App\Modules\Finance\Services\MaishaPayGateway;
use Tests\TestCase;

class MaishaPayGatewayTest extends TestCase
{
    public function test_it_accepts_only_the_expected_hmac_signature(): void
    {
        config()->set('services.maishapay.webhook_secret', 'test-secret');
        $payload = '{"reference":"PAY-001","status":"PAID"}';
        $gateway = new MaishaPayGateway;

        $this->assertTrue($gateway->validSignature($payload, hash_hmac('sha256', $payload, 'test-secret')));
        $this->assertFalse($gateway->validSignature($payload, 'invalid'));
        $this->assertFalse($gateway->validSignature($payload.'changed', hash_hmac('sha256', $payload, 'test-secret')));
    }

    public function test_it_rejects_callbacks_when_no_secret_is_configured(): void
    {
        config()->set('services.maishapay.webhook_secret', '');

        $this->assertFalse((new MaishaPayGateway)->validSignature('{}', hash('sha256', '{}')));
    }

    public function test_local_adapter_returns_stable_payment_and_refund_contracts(): void
    {
        $gateway = new MaishaPayGateway;

        $this->assertSame(['reference' => 'PAY-001', 'status' => 'PENDING'], $gateway->create('PAY-001', '100.00', 'USD'));
        $this->assertSame(['reference' => 'refund-PAY-001', 'status' => 'PROCESSED'], $gateway->refund('PAY-001', '20.00'));
    }
}
