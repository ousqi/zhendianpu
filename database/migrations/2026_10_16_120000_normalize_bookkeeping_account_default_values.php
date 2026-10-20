<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL UNIQUE permits multiple NULL values, but only one true default.
        DB::table('bookkeeping_accounts')->where('is_default', false)->update(['is_default' => null]);
    }

    public function down(): void
    {
        // NULL is the canonical representation for non-default accounts.
    }
};
