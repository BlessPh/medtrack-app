<?php

namespace Database\Factories;

use App\Modules\Academic\Models\Student;
use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'university_id' => Institution::factory()->state(['type' => 'UNIVERSITY']),
            'national_reference' => fake()->unique()->bothify('NAT-########'),
            'student_number' => fake()->unique()->bothify('STU-######'),
            'status' => 'ACTIVE',
        ];
    }
}
