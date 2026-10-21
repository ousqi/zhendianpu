<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingRefundService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update_refund')]
#[Description('使用修订版本更新当前账本的一笔退款记录。')]
#[IsIdempotent]
class UpdateRefund extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingRefundService::class)->update($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'refund_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'revision' => $schema->integer()->min(1)->required(), 'account_id' => $schema->integer()->min(1)->nullable(), 'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->nullable(), 'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable()];
    }
}
