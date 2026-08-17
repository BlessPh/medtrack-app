<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiFoundationTest extends TestCase
{
    public function test_each_module_route_is_loaded(): void
    {
        $modules = [
            'auth', 'institutions', 'academic', 'admissions', 'internships',
            'scheduling', 'assessments', 'finance', 'documents', 'notifications',
        ];

        foreach ($modules as $module) {
            $this->getJson("/api/v1/{$module}/health")
                ->assertOk()
                ->assertJsonPath('data.status', 'ok')
                ->assertHeader('X-Request-ID');
        }
    }

    public function test_a_valid_request_id_is_preserved(): void
    {
        $this->withHeader('X-Request-ID', 'request-test-123')
            ->getJson('/api/v1/auth/health')
            ->assertHeader('X-Request-ID', 'request-test-123');
    }

    public function test_an_invalid_request_id_is_replaced(): void
    {
        $response = $this->withHeader('X-Request-ID', '<unsafe>')
            ->getJson('/api/v1/auth/health');

        $response->assertHeader('X-Request-ID');
        $this->assertNotSame('<unsafe>', $response->headers->get('X-Request-ID'));
    }
}
