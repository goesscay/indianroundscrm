<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Default password for factory users: "Passw0rd!x"
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Passw0rd!x',
            'role' => 'sales',
            'staff_code' => strtoupper(Str::random(6)),
            'consultant_code' => '',
            'active' => true,
            'account_status' => 'active',
            'token_version' => 0,
            'failed_login_attempts' => 0,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function sales(): static
    {
        return $this->state(fn () => ['role' => 'sales']);
    }

    public function operations(): static
    {
        return $this->state(fn () => ['role' => 'operations']);
    }
}
