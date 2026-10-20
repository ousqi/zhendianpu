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

#[Name('update_entry')]
#[Description('按记录ID和revision修改已发生收支；版本不匹配时不会覆盖更新。')]
#[IsIdempotent]
class UpdateEntry extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingService::class)->update($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'entry_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'revision' => $schema->integer()->min(1)->required(),
            'direction' => $schema->string()->enum(['income', 'expense'])->nullable(), 'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->nullable(), 'category_id' => $schema->integer()->min(1)->nullable(), 'account_id' => $schema->integer()->min(1)->nullable(),
            'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable()];
    }
}
