<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Category;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_categories')]
#[Description('列出收入和支出分类，包含已停用分类，供分类管理和分类 ID 查询使用。')]
#[IsReadOnly]
#[IsIdempotent]
class ListCategories extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        $direction = $request->get('direction');

        $categories = Category::query()
            ->when($direction !== null, fn ($query) => $query->where('direction', $direction))
            ->orderBy('direction')
            ->orderBy('name')
            ->get(['id', 'name', 'direction', 'is_active'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'direction' => $category->direction,
                'is_active' => $category->is_active,
            ])
            ->all();

        return [
            'ok' => true,
            'categories' => $categories,
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'direction' => $schema->string()->enum(['income', 'expense'])->nullable(),
        ];
    }
}
