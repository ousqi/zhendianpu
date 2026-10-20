<?php

namespace App\Services\Bookkeeping;

use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    /** @return Collection<int, Ledger> */
    public function list(bool $includeArchived = false): Collection
    {
        return Ledger::query()->when(! $includeArchived, fn ($query) => $query->where('status', 'active'))->orderBy('name')->get();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Ledger
    {
        $data = Validator::make($attributes, ['name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:business,personal,other'], 'currency' => ['nullable', 'string', 'size:3'], 'timezone' => ['nullable', 'timezone']])->validate();

        return DB::transaction(function () use ($data): Ledger {
            $ledger = Ledger::create([...$data, 'currency' => strtoupper($data['currency'] ?? 'CNY'), 'timezone' => $data['timezone'] ?? 'Asia/Shanghai', 'status' => 'active']);
            $this->initializeTemplate($ledger);

            return $ledger;
        });
    }

    public function get(string $publicId): Ledger
    {
        return Ledger::query()->where('public_id', $publicId)->firstOrFail();
    }

    public function active(string $publicId): Ledger
    {
        $ledger = $this->get($publicId);
        if ($ledger->status !== 'active') {
            throw ValidationException::withMessages(['ledger_id' => '账本已归档，不能写入。']);
        }

        return $ledger;
    }

    /** @param array<string, mixed> $attributes */
    public function update(Ledger $ledger, array $attributes): Ledger
    {
        $this->assertActive($ledger);
        $data = Validator::make($attributes, ['name' => ['sometimes', 'required', 'string', 'max:255'], 'currency' => ['sometimes', 'string', 'size:3'], 'timezone' => ['sometimes', 'timezone']])->validate();
        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }
        $ledger->update([...$data, 'revision' => $ledger->revision + 1]);

        return $ledger->refresh();
    }

    public function archive(Ledger $ledger): Ledger
    {
        $this->assertActive($ledger);
        $ledger->update(['status' => 'archived', 'revision' => $ledger->revision + 1]);

        return $ledger->refresh();
    }

    public function fallback(): Ledger
    {
        return Ledger::query()->where('status', 'active')->where('type', 'business')->first()
            ?? Ledger::query()->where('status', 'active')->firstOrFail();
    }

    public function current(?User $user): Ledger
    {
        if (! $user instanceof User) {
            return $this->fallback();
        }

        $sessionKey = $this->currentLedgerSessionKey($user);
        $publicId = session($sessionKey);
        $ledger = is_string($publicId)
            ? Ledger::query()->where('public_id', $publicId)->where('status', 'active')->first()
            : null;

        if ($ledger instanceof Ledger) {
            return $ledger;
        }

        $ledger = $this->fallback();
        session([$sessionKey => $ledger->public_id]);

        return $ledger;
    }

    public function selectCurrent(User $user, Ledger $ledger): void
    {
        $this->assertActive($ledger);

        session([$this->currentLedgerSessionKey($user) => $ledger->public_id]);
    }

    public function legacyBusinessLedger(): Ledger
    {
        return Ledger::query()->where('name', '经营账本')->where('type', 'business')->orderBy('id')->firstOrFail();
    }

    private function assertActive(Ledger $ledger): void
    {
        if ($ledger->status !== 'active') {
            throw ValidationException::withMessages(['ledger_id' => '账本已归档，不能写入。']);
        }
    }

    private function currentLedgerSessionKey(User $user): string
    {
        return 'bookkeeping.current_ledger_id.'.$user->id;
    }

    private function initializeTemplate(Ledger $ledger): void
    {
        $template = match ($ledger->type) {
            'business' => [
                'expense' => [
                    '食材采购' => ['面粉', '酱料', '肉类', '蔬菜', '鸡蛋', '米饭', '其他食材'],
                    '包装耗材' => ['包装盒', '塑料袋', '餐具', '纸巾', '其他耗材'],
                    '设备工具' => ['厨具', '设备购置', '设备维修', '清洁工具', '其他工具'],
                    '水电费用' => ['水费', '电费', '燃气费', '网络费'],
                    '房租费用' => ['房租', '物业费'],
                    '其他支出' => ['人工工资', '交通物流', '平台服务费', '营销推广', '税费', '其他'],
                ],
                'income' => [
                    '酱香饼' => ['堂食', '外卖', '团购'],
                    '饭团' => ['堂食', '外卖', '团购'],
                    '其他收入' => ['饮料小食', '退款收入', '其他'],
                ],
            ],
            'personal' => [
                'expense' => ['餐饮' => ['早餐', '午餐', '晚餐'], '交通' => ['公交地铁', '打车'], '购物' => ['日用品', '服饰'], '住房' => ['房租', '水电'], '通讯' => ['话费', '网络'], '医疗' => ['药品', '就医'], '教育' => ['书籍', '课程'], '娱乐' => ['电影', '游戏'], '旅行' => ['住宿', '门票'], '人情' => ['礼物', '红包'], '保险' => ['保险费'], '其他支出' => ['其他']],
                'income' => ['工资' => ['基本工资', '奖金'], '兼职' => ['兼职收入'], '投资' => ['利息', '理财'], '红包' => ['红包收入'], '报销' => ['报销收入'], '退款' => ['退款'], '其他收入' => ['其他']],
            ],
            default => ['expense' => ['其他支出' => ['其他']], 'income' => ['其他收入' => ['其他']]],
        };

        foreach ($template as $direction => $parents) {
            foreach ($parents as $name => $children) {
                $parent = Category::create(['ledger_id' => $ledger->id, 'name' => $name, 'direction' => $direction, 'sort_order' => 0]);
                foreach ($children as $index => $child) {
                    Category::create(['ledger_id' => $ledger->id, 'parent_id' => $parent->id, 'name' => $child, 'direction' => $direction, 'sort_order' => $index]);
                }
            }
        }
        Account::create(['ledger_id' => $ledger->id, 'name' => '默认账户', 'type' => 'other', 'is_active' => true, 'is_default' => true]);
    }
}
