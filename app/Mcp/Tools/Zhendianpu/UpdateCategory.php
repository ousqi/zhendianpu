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

#[Name('update_category')]
#[Description('更新分类名称或方向。已有收支记录的分类不能改变方向；需要记账写入权限。')]
class UpdateCategory extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        $category = Category::query()->findOrFail($request->integer('category_id'));
        $category = app(BookkeepingCategoryService::class)->update($context, $this->ledger($request), $category, $request->only(['name', 'direction', 'parent_id', 'sort_order', 'is_active']));

        return [
            'ok' => true,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'direction' => $category->direction,
                'parent_id' => $category->parent_id,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
            ],
            'request_id' => $context->requestId,
        ];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(),
            'category_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->max(255)->nullable(),
            'direction' => $schema->string()->enum(['income', 'expense'])->nullable(),
            'parent_id' => $schema->integer()->min(1)->nullable(),
            'sort_order' => $schema->integer()->min(0)->nullable(),
            'is_active' => $schema->boolean()->nullable(),
        ];
    }
}
