<?php

namespace App\Models\Bookkeeping;

use Database\Factories\Bookkeeping\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $table = 'bookkeeping_categories';

    protected $fillable = ['ledger_id', 'parent_id', 'name', 'direction', 'sort_order', 'is_active'];

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
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    public function icon(): string
    {
        return self::iconFor($this->name, $this->direction);
    }

    public function displayName(): string
    {
        return self::displayNameFor($this->name, $this->direction);
    }

    public static function displayNameFor(string $name, string $direction): string
    {
        return self::iconFor($name, $direction).' '.$name;
    }

    public static function iconFor(string $name, string $direction): string
    {
        foreach ([
            '↩️' => ['退款', '退货'],
            '🍜' => ['食材', '面粉', '酱料', '肉类', '蔬菜', '鸡蛋', '米饭', '餐饮', '早餐', '午餐', '晚餐', '堂食', '外卖', '酱香饼', '饭团', '饮料', '小食'],
            '📦' => ['包装', '耗材', '包装盒', '塑料袋', '餐具', '纸巾'],
            '🛠️' => ['设备', '厨具', '工具', '维修', '清洁'],
            '💡' => ['水费', '电费', '燃气', '网络', '话费', '通讯'],
            '🏠' => ['房租', '物业', '住房', '住宿'],
            '👥' => ['人工', '员工', '工资', '薪资', '奖金'],
            '🚇' => ['交通', '物流', '公交', '地铁', '打车'],
            '💳' => ['财务', '金融', '手续费'],
            '📣' => ['平台', '服务费', '营销', '推广', '团购'],
            '🛍️' => ['购物', '日用品', '服饰'],
            '🏥' => ['医疗', '药品', '就医'],
            '📚' => ['教育', '书籍', '课程'],
            '🎮' => ['娱乐', '电影', '游戏'],
            '🧾' => ['税费', '保险'],
            '✈️' => ['旅行', '门票'],
            '🎁' => ['礼物', '红包'],
            '📈' => ['投资', '利息', '理财'],
            '💼' => ['兼职', '报销'],
        ] as $icon => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($name, $keyword)) {
                    return $icon;
                }
            }
        }

        return $direction === 'income' ? '💰' : '💸';
    }

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }
}
