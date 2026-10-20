<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Ledger;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('archive_ledger')]
#[Description('归档账本。归档后账本不能再写入；需要记账写入权限。')]
class ArchiveLedger extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        abort_unless(app(BookkeepingService::class)->canWrite($context), 403, 'FORBIDDEN');
        $ledger = app(LedgerService::class)->archive($this->ledger($request));

        return ['ok' => true, 'ledger' => $this->present($ledger), 'request_id' => $context->requestId];
    }

    /** @return array<string, string|int> */
    private function present(Ledger $ledger): array
    {
        return ['id' => $ledger->public_id, 'name' => $ledger->name, 'type' => $ledger->type, 'status' => $ledger->status, 'currency' => $ledger->currency, 'timezone' => $ledger->timezone, 'revision' => $ledger->revision];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required()];
    }
}
