<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\Student;
use App\Modules\Auth\Models\User;
use App\Modules\Institution\Models\Institution;
use App\Shared\Enums\InstitutionRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportingSecurityQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_institution_dashboard_is_scoped_and_has_a_query_budget(): void
    {
        $user = User::factory()->create();
        $own = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $other = Institution::factory()->create(['type' => 'UNIVERSITY']);
        $this->assignInstitutionRole($user, $own, InstitutionRole::AcademicManager->value);
        Student::factory()->count(3)->create(['university_id' => $own->id]);
        Student::factory()->count(5)->create(['university_id' => $other->id]);

        DB::enableQueryLog();
        $this->actingAs($user)->getJson("/api/v1/reporting/dashboard?institution_id={$own->public_id}")
            ->assertOk()->assertJsonPath('data.students', 3)->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertLessThanOrEqual(15, count(DB::getQueryLog()));
        $this->getJson("/api/v1/reporting/dashboard?institution_id={$other->public_id}")->assertForbidden();
    }

    public function test_search_is_paginated_scoped_and_csv_export_is_protected(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $this->assignInstitutionRole($user, $institution, InstitutionRole::AcademicManager->value);
        Student::factory()->count(3)->create(['university_id' => $institution->id]);

        $url = "/api/v1/reporting/search?institution_id={$institution->public_id}&type=students&per_page=2";
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($user)->getJson($url)->assertOk()->assertJsonPath('data.per_page', 2)->assertJsonCount(2, 'data.data');
        $this->get("/api/v1/reporting/export?institution_id={$institution->public_id}&type=students")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_all_non_public_api_routes_require_authentication(): void
    {
        $allowed = ['health', 'auth/login', 'auth/password/forgot', 'auth/password/reset', 'auth/student-registration/check', 'auth/student-registration', 'auth/institution-registration', 'auth/member-invitations/{token}', 'auth/member-invitations/{token}/register', 'finance/callbacks/maishapay'];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/') || in_array('GET', $route->methods()) && str_ends_with($route->uri(), '/health')) {
                continue;
            }
            $relative = substr($route->uri(), 7);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertTrue(in_array('auth:api', $middleware, true) || in_array('Illuminate\\Auth\\Middleware\\Authenticate:api', $middleware, true), "Route privée non protégée : {$route->uri()}");
        }
    }

    public function test_private_storage_has_no_framework_serve_route(): void
    {
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'storage/{path}'));
    }

    public function test_dashboard_read_budget_on_repeated_requests(): void
    {
        $user = User::factory()->create();
        $institution = Institution::factory()->create();
        $this->assignInstitutionRole($user, $institution, InstitutionRole::AcademicManager->value);
        Student::factory()->count(100)->create(['university_id' => $institution->id]);
        $startedAt = microtime(true);
        for ($iteration = 0; $iteration < 20; $iteration++) {
            $this->actingAs($user)->getJson("/api/v1/reporting/dashboard?institution_id={$institution->public_id}")->assertOk();
        }

        $this->assertLessThan(5.0, microtime(true) - $startedAt, 'Le budget local de 20 lectures est dépassé.');
    }
}
