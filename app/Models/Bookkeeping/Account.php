<?php

namespace App\Models\Bookkeeping;

use Database\Factories\Bookkeeping\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public const array TYPES = ['wechat', 'alipay', 'bank_card', 'cash', 'other'];

    protected $table = 'bookkeeping_accounts';

    protected $fillable = ['ledger_id', 'name', 'type', 'opening_balance', 'sort_order', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return ['opening_balance' => 'decimal:2', 'sort_order' => 'integer', 'is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'source_account_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'destination_account_id');
    }
}
