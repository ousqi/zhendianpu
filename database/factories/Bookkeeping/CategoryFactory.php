<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Category;
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
            'name' => fake()->unique()->word(),
            'direction' => 'expense',
            'is_active' => true,
        ];
    }
}
