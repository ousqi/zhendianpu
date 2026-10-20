<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingCategoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_category')]
#[Description('新增一个收入或支出分类。需要记账写入权限。')]
class AddCategory extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        $category = app(BookkeepingCategoryService::class)->create($context, $this->ledger($request), $request->all());

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
            'name' => $schema->string()->max(255)->required(),
            'direction' => $schema->string()->enum(['income', 'expense'])->required(),
            'parent_id' => $schema->integer()->min(1)->nullable(),
            'sort_order' => $schema->integer()->min(0)->nullable(),
        ];
    }
}
