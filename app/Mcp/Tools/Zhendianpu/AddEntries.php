<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('add_entries')]
#[Description('原子添加一笔或多笔已经发生的收入/支出。日期省略时使用北京时间当天；同一event_id重试须保持完全相同的参数。')]
#[IsIdempotent]
class AddEntries extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingService::class)->add($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'entries' => $schema->array()->min(1)->max(50)->items($schema->object([
            'direction' => $schema->string()->enum(['income', 'expense'])->required(), 'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->required(),
            'category_id' => $schema->integer()->min(1)->required(), 'account_id' => $schema->integer()->min(1)->nullable(), 'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable(),
        ])->withoutAdditionalProperties())->required()];
    }
}
