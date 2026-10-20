<?php

namespace Database\Factories\Bookkeeping;

use App\Models\Bookkeeping\Ledger;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ledger> */
class LedgerFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->company(), 'type' => 'business', 'currency' => 'CNY', 'timezone' => 'Asia/Shanghai', 'status' => 'active', 'revision' => 1];
    }
}
