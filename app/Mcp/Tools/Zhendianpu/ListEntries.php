<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_entries')]
#[Description('按日期、方向、分类或说明查询单账本收支记录，返回稳定记录标识和版本。')]
#[IsReadOnly]
#[IsIdempotent]
class ListEntries extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingQueryService::class)->entries($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return $this->filters($schema, true);
    }

    /** @return array<string, Type> */
    protected function filters(JsonSchema $schema, bool $pagination): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'start_date' => $schema->string()->format('date')->nullable(), 'end_date' => $schema->string()->format('date')->nullable(), 'direction' => $schema->string()->enum(['income', 'expense'])->nullable(),
            'category_id' => $schema->integer()->nullable(), 'account_id' => $schema->integer()->nullable(), 'keyword' => $schema->string()->max(1000)->nullable(), 'include_voided' => $schema->boolean()->nullable(),
            ...($pagination ? ['page' => $schema->integer()->min(1)->nullable(), 'limit' => $schema->integer()->min(1)->max(100)->nullable()] : [])];
    }
}
