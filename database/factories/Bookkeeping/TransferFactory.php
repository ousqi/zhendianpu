<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_account_id' => Account::factory(),
            'destination_account_id' => Account::factory(),
            'amount' => '10.00',
            'occurred_on' => now('Asia/Shanghai')->toDateString(),
            'source' => 'admin',
            'created_by' => User::factory(),
            'updated_by' => User::factory(),
        ];
    }
}
