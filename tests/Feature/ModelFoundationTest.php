<?php

namespace Tests\Feature;

use App\Modules\Academic\Models\AcademicLevel;
use App\Modules\Academic\Models\Student;
use App\Modules\Institution\Models\Institution;
use Database\Seeders\AcademicLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_factories_create_related_records(): void
    {
        $student = Student::factory()->create();

        $this->assertNotEmpty($student->public_id);
        $this->assertInstanceOf(Institution::class, $student->university);
        $this->assertSame('UNIVERSITY', $student->university->type);
    }

    public function test_academic_level_seeder_is_repeatable(): void
    {
        $this->seed(AcademicLevelSeeder::class);
        $this->seed(AcademicLevelSeeder::class);

        $this->assertSame(7, AcademicLevel::query()->count());
    }

    public function test_unique_constraints_protect_domain_data(): void
    {
        Institution::factory()->create(['registration_number' => 'UNIQUE-001']);

        $this->expectException(QueryException::class);
        Institution::factory()->create(['registration_number' => 'UNIQUE-001']);
    }
}
