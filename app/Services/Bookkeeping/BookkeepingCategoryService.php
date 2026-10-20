<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingCategoryService
{
    public function __construct(private BookkeepingService $bookkeeping) {}

    /** @param array<string, mixed> $attributes */
    public function create(AccessContext $context, array $attributes): Category
    {
        $this->authorize($context);
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'in:income,expense'],
        ])->validate();

        return DB::transaction(function () use ($context, $data): Category {
            $category = Category::create($data)->refresh();
            $this->audit($context, 'bookkeeping.category.created', $category, null, $this->present($category));

            return $category;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(AccessContext $context, Category $category, array $attributes): Category
    {
        $this->authorize($context);
        $data = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'direction' => ['required', 'in:income,expense'],
        ])->validate();

        return DB::transaction(function () use ($context, $category, $data): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);
            if ($category->direction !== $data['direction'] && $category->entries()->exists()) {
                throw ValidationException::withMessages(['direction' => '已使用的分类不能改变收支方向。']);
            }
            $before = $this->present($category);
            $category->update($data);
            $this->audit($context, 'bookkeeping.category.updated', $category, $before, $this->present($category));

            return $category;
        });
    }

    public function toggle(AccessContext $context, Category $category): Category
    {
        $this->authorize($context);

        return DB::transaction(function () use ($context, $category): Category {
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->present($category);
            $category->update(['is_active' => ! $category->is_active]);
            $this->audit($context, 'bookkeeping.category.toggled', $category, $before, $this->present($category));

            return $category;
        });
    }

    private function authorize(AccessContext $context): void
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
    }

    /** @return array{id: int, name: string, direction: string, is_active: bool} */
    private function present(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'direction' => $category->direction,
            'is_active' => $category->is_active,
        ];
    }

    /** @param array<string, mixed>|null $before @param array<string, mixed> $after */
    private function audit(AccessContext $context, string $action, Category $category, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'request_id' => $context->requestId,
            'user_id' => $context->user->id,
            'workspace_id' => $context->workspaces[0]['id'],
            'server_id' => $context->server->id,
            'module_key' => 'zhendianpu',
            'scope' => json_encode([], JSON_THROW_ON_ERROR),
            'action' => $action,
            'entity_type' => 'bookkeeping_category',
            'entity_id' => (string) $category->id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after' => json_encode($after, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
