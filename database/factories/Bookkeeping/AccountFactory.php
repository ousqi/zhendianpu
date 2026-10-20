<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'type' => 'other',
            'sort_order' => 0,
            'is_active' => true,
            'is_default' => null,
        ];
    }
}
