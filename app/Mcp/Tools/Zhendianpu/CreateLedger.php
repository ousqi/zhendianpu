<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_ledger')]
#[Description('创建账本，并初始化对应的分类和默认账户模板。')]
class CreateLedger extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        abort_unless(app(BookkeepingService::class)->canWrite($context), 403, 'FORBIDDEN');
        $ledger = app(LedgerService::class)->create($request->all());

        return ['ok' => true, 'ledger' => ['id' => $ledger->public_id, 'name' => $ledger->name, 'type' => $ledger->type, 'status' => $ledger->status], 'request_id' => $context->requestId];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->max(255)->required(), 'type' => $schema->string()->enum(['business', 'personal', 'other'])->required(), 'currency' => $schema->string()->min(3)->max(3)->nullable(), 'timezone' => $schema->string()->max(64)->nullable()];
    }
}
