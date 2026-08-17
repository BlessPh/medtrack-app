<?php

namespace Tests\Unit;

use App\Shared\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_security_headers_are_added_to_every_response(): void
    {
        $response = (new SecurityHeaders)->handle(Request::create('/api/test'), fn () => new Response('ok'));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
    }
}
