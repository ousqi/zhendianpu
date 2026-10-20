<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingTransferService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('add_transfer')]
#[Description('在两个不同的启用账户间记录一笔已经发生的转账。转账不计入收入、支出或期间结余。')]
#[IsIdempotent]
class AddTransfer extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingTransferService::class)->add($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'source_account_id' => $schema->integer()->min(1)->required(), 'destination_account_id' => $schema->integer()->min(1)->required(),
            'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->required(), 'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable()];
    }
}
