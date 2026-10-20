<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingCategoryService
{
    public function __construct(private BookkeepingService $bookkeeping) {}

    /** @param array<string,mixed> $attributes */
    public function create(AccessContext $context, Ledger $ledger, array $attributes): Category
    {
        $this->authorize($context, $ledger);
        $data = $this->validate($attributes, false);

        return DB::transaction(function () use ($context, $ledger, $data): Category {
            Ledger::query()->whereKey($ledger->id)->lockForUpdate()->firstOrFail();
            $this->validateParent($ledger, $data['direction'], $data['parent_id'] ?? null);
            $this->assertUniqueName($ledger, $data['direction'], $data['parent_id'] ?? null, $data['name']);
            $category = Category::create([...$data, 'ledger_id' => $ledger->id, 'sort_order' => $data['sort_order'] ?? 0, 'is_active' => $data['is_active'] ?? true]);
            $this->audit($context, $ledger, 'bookkeeping.category.create', $category, null);

            return $category;
        }, attempts: 3);
    }

    /** @param array<string,mixed> $attributes */
    public function update(AccessContext $context, Ledger $ledger, Category $category, array $attributes): Category
    {
        $this->authorize($context, $ledger);
        $data = $this->validate($attributes, true);

        return DB::transaction(function () use ($context, $ledger, $category, $data): Category {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->owned($ledger, $category);
            $direction = $data['direction'] ?? $category->direction;
            $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id;
            if ($category->direction !== $direction && $category->entries()->exists()) {
                throw ValidationException::withMessages(['direction' => '已使用的分类不能改变收支方向。']);
            }
            if ($category->children()->lockForUpdate()->exists() && ($parentId !== null || $direction !== $category->direction)) {
                throw ValidationException::withMessages(['parent_id' => '含有子分类的父分类不能成为子分类或改变方向。']);
            }
            $this->validateParent($ledger, $direction, $parentId, $category->id);
            $this->assertUniqueName($ledger, $direction, $parentId, $data['name'] ?? $category->name, $category->id);
            $before = $category->only(['name', 'direction', 'parent_id', 'sort_order', 'is_active']);
            $category->update($data);
            $category = $category->refresh();
            $this->audit($context, $ledger, 'bookkeeping.category.update', $category, $before);

            return $category;
        }, attempts: 3);
    }

    public function toggle(AccessContext $context, Ledger $ledger, Category $category): Category
    {
        $this->authorize($context, $ledger);

        return DB::transaction(function () use ($context, $ledger, $category): Category {
            $category = Category::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $this->owned($ledger, $category);
            $before = $category->only(['is_active']);
            $category->update(['is_active' => ! $category->is_active]);
            $category = $category->refresh();
            $this->audit($context, $ledger, 'bookkeeping.category.toggle', $category, $before);

            return $category;
        }, attempts: 3);
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function validate(array $attributes, bool $update): array
    {
        return Validator::make($attributes, ['name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'], 'direction' => [$update ? 'sometimes' : 'required', 'in:income,expense'], 'parent_id' => ['sometimes', 'nullable', 'integer'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['sometimes', 'boolean']])->validate();
    }

    private function authorize(AccessContext $context, Ledger $ledger): void
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
        if ($ledger->status !== 'active') {
            throw ValidationException::withMessages(['ledger_id' => '账本已归档，不能写入。']);
        }
    }

    private function owned(Ledger $ledger, Category $category): void
    {
        if ($category->ledger_id !== $ledger->id) {
            throw ValidationException::withMessages(['category' => '分类不属于当前账本。']);
        }
    }

    private function validateParent(Ledger $ledger, string $direction, ?int $parentId, ?int $categoryId = null): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = Category::query()->where('ledger_id', $ledger->id)->whereKey($parentId)->lockForUpdate()->first();
        if (! $parent || $parent->direction !== $direction || $parent->parent_id !== null || $parent->id === $categoryId) {
            throw ValidationException::withMessages(['parent_id' => '父分类必须属于当前账本、方向一致且为一级分类。']);
        }
    }

    private function assertUniqueName(Ledger $ledger, string $direction, ?int $parentId, string $name, ?int $exceptId = null): void
    {
        $query = Category::query()->where('ledger_id', $ledger->id)->where('direction', $direction)->where('name', $name)->when($parentId === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $parentId))->lockForUpdate();
        if ($query->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->exists()) {
            throw ValidationException::withMessages(['name' => '同一层级中已存在同名分类。']);
        }
    }

    /** @param array<string, mixed>|null $before */
    private function audit(AccessContext $context, Ledger $ledger, string $action, Category $category, ?array $before): void
    {
        DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'user_id' => $context->user->id, 'workspace_id' => $context->workspaces[0]['id'], 'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'action' => $action, 'entity_type' => 'bookkeeping_category', 'entity_id' => (string) $category->id, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($category->only(['id', 'name', 'direction', 'parent_id', 'sort_order', 'is_active']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
