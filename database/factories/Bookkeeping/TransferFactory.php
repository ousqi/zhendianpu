<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ledger_id' => Ledger::factory(),
            'source_account_id' => fn (array $attributes): int => Account::factory()->create(['ledger_id' => $attributes['ledger_id']])->id,
            'destination_account_id' => fn (array $attributes): int => Account::factory()->create(['ledger_id' => $attributes['ledger_id']])->id,
            'amount' => '10.00',
            'occurred_on' => now('Asia/Shanghai')->toDateString(),
            'source' => 'admin',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
