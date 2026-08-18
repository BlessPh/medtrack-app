<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_portable_domain_schema_is_created(): void
    {
        $tables = [
            'users', 'user_profiles', 'password_reset_tokens',
            'institutions', 'institution_units', 'institution_addresses', 'institution_contacts', 'institution_memberships',
            'academic_programs', 'academic_levels', 'academic_years', 'promotions', 'students', 'enrollments', 'campaigns', 'campaign_promotions', 'campaign_hospitals',
            'applications', 'capacity_pools', 'capacity_reservations', 'admissions',
            'path_templates', 'path_steps', 'internships', 'rotations', 'rotation_extensions',
            'schedules', 'schedule_entries', 'biometric_devices', 'attendance_records', 'attendance_corrections',
            'evaluation_templates', 'evaluations', 'evaluation_disputes', 'academic_decisions',
            'financial_obligations', 'financial_obligation_items', 'payment_transactions', 'payment_allocations', 'payment_refunds',
            'documents', 'notifications',
            // Infrastructure Laravel nécessaire à QUEUE_CONNECTION=database.
            'jobs', 'job_batches', 'failed_jobs',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }

        $this->assertFalse(Schema::hasTable('outbox_messages'));
        $this->assertFalse(Schema::hasTable('media_versions'));
    }
}
