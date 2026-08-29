<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /** Hashed once per run — bcrypt is deliberately slow, and this is not what is under test. */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'job_title' => fake()->jobTitle(),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /** Switched off by an administrator: keeps their work, loses their access. */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    public function denied(array $permissions): static
    {
        return $this->state(fn (array $attributes) => [
            'denied_permissions' => $permissions,
        ]);
    }
}
