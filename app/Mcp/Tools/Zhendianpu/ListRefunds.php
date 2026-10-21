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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_refunds')]
#[Description('列出当前账本的退款记录，可按原收支记录筛选。')]
#[IsReadOnly]
#[IsIdempotent]
class ListRefunds extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        return app(BookkeepingRefundService::class)->list($context, $request->all());
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'entry_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->nullable(), 'include_voided' => $schema->boolean()->nullable()];
    }
}
