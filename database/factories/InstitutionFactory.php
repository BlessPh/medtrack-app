<?php

namespace Database\Factories;

use App\Modules\Institution\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class InstitutionFactory extends Factory
{
    protected $model = Institution::class;

    public function definition(): array
    {
        return [
            'public_id' => (string) Str::uuid(),
            'type' => fake()->randomElement(['UNIVERSITY', 'HOSPITAL', 'CLINIC']),
            'name' => fake()->company(),
            'registration_number' => fake()->unique()->bothify('INST-#####'),
            'status' => 'ACTIVE',
        ];
    }
}
