<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Refund> */
class RefundFactory extends Factory
{
    public function definition(): array
    {
        return ['entry_id' => Entry::factory(), 'ledger_id' => fn (array $attributes): int => Entry::query()->findOrFail($attributes['entry_id'])->ledger_id,
            'account_id' => fn (array $attributes): int => Account::factory()->create(['ledger_id' => $attributes['ledger_id']])->id,
            'amount' => '1.00', 'occurred_on' => now('Asia/Shanghai')->toDateString(), 'source' => 'admin', 'created_by' => User::factory(), 'updated_by' => User::factory()];
    }
}
