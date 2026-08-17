<?php

namespace Tests\Unit;

use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HasPublicIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_id_is_generated_by_laravel(): void
    {
        $user = User::factory()->create();

        $this->assertNotEmpty($user->public_id);
        $this->assertSame('public_id', $user->getRouteKeyName());
    }
}
