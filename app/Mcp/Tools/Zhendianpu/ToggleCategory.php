<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Category;
use App\Services\Bookkeeping\BookkeepingCategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('toggle_category')]
#[Description('切换分类的启用状态。停用后不能用于新增收支；需要记账写入权限。')]
class ToggleCategory extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        $category = Category::query()->findOrFail($request->integer('category_id'));
        $category = app(BookkeepingCategoryService::class)->toggle($context, $category);

        return [
            'ok' => true,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'direction' => $category->direction,
                'is_active' => $category->is_active,
            ],
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->integer()->min(1)->required(),
        ];
    }
}
