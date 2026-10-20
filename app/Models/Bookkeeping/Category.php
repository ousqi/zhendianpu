<?php

namespace App\Models\Bookkeeping;

use Database\Factories\Bookkeeping\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $table = 'bookkeeping_categories';

    protected $fillable = ['name', 'direction', 'is_active'];

    protected static function booted(): void
    {
        static::updating(function (self $category): void {
            if ($category->isDirty('direction') && $category->entries()->exists()) {
                throw new \LogicException('已使用的分类不能改变收支方向。');
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
