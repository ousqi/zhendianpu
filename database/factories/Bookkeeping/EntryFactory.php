<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entry>
 */
class EntryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ledger_id' => Ledger::factory(),
            'direction' => 'expense',
            'amount' => '10.00',
            'category_id' => fn (array $attributes): int => Category::factory()->create(['ledger_id' => $attributes['ledger_id']])->id,
            'account_id' => fn (array $attributes): int => Account::factory()->create(['ledger_id' => $attributes['ledger_id']])->id,
            'occurred_on' => now('Asia/Shanghai')->toDateString(),
            'source' => 'admin',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
