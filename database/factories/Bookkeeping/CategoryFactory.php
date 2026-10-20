<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ledger_id' => Ledger::factory(),
            'name' => fake()->unique()->word(),
            'direction' => 'expense',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
