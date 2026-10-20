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

#[Name('update_transfer')]
#[Description('按转账ID和revision修改已发生转账；版本不匹配时不会覆盖更新，转账不计入收入、支出或期间结余。')]
#[IsIdempotent]
class UpdateTransfer extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingTransferService::class)->update($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'transfer_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'revision' => $schema->integer()->min(1)->required(),
            'source_account_id' => $schema->integer()->min(1)->nullable(), 'destination_account_id' => $schema->integer()->min(1)->nullable(), 'amount' => $schema->string()->pattern('^\\d{1,10}(?:\\.\\d{1,2})?$')->nullable(),
            'occurred_on' => $schema->string()->format('date')->nullable(), 'description' => $schema->string()->max(1000)->nullable()];
    }
}
