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

#[Name('add_refund')]
#[Description('为已发生的原收支新增退款记录；退款方向由原收支自动推断。')]
#[IsIdempotent]
class AddRefund extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingRefundService::class)->add($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'entry_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'account_id' => $schema->integer()->min(1)->required(), 'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->required(), 'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable()];
    }
}
