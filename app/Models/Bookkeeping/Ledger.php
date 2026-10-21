<?php

namespace App\Models\Bookkeeping;

use Database\Factories\Bookkeeping\LedgerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ledger extends Model
{
    /** @use HasFactory<LedgerFactory> */
    use HasFactory, HasUlids;

    public const array TYPES = ['business', 'personal', 'other'];

    protected $table = 'bookkeeping_ledgers';

    protected $fillable = ['name', 'type', 'currency', 'timezone', 'status', 'revision'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }
}
