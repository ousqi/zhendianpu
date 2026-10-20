<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Ledger;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_ledgers')]
#[Description('列出可用账本及其状态。')]
#[IsReadOnly]
#[IsIdempotent]
class ListLedgers extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        abort_unless(app(BookkeepingService::class)->canRead($context), 403, 'FORBIDDEN');
        if ($request->all() !== []) {
            abort(422, 'INVALID_ARGUMENT');
        }

        return ['ok' => true, 'ledgers' => app(LedgerService::class)->list(true)->map(fn (Ledger $ledger): array => ['id' => $ledger->public_id, 'name' => $ledger->name, 'type' => $ledger->type, 'status' => $ledger->status, 'currency' => $ledger->currency, 'timezone' => $ledger->timezone])->all(), 'request_id' => $context->requestId];
    }
}
