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
        $category = app(BookkeepingCategoryService::class)->create($context, $request->all());

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
            'name' => $schema->string()->max(255)->required(),
            'direction' => $schema->string()->enum(['income', 'expense'])->required(),
        ];
    }
}
