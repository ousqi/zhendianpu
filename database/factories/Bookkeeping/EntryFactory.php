<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'direction' => 'expense',
            'amount' => '10.00',
            'category_id' => Category::factory(),
            'account_id' => Account::factory(),
            'occurred_on' => now('Asia/Shanghai')->toDateString(),
            'source' => 'admin',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
