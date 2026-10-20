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

#[Name('void_transfer')]
#[Description('按转账ID和revision撤销转账，不物理删除历史。')]
#[IsIdempotent]
class VoidTransfer extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingTransferService::class)->void($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'event_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'transfer_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'revision' => $schema->integer()->min(1)->required(), 'void_reason' => $schema->string()->max(1000)];
    }
}
