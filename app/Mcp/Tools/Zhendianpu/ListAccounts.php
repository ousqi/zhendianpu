<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Services\Bookkeeping\BookkeepingAccountService;
use App\Services\Bookkeeping\BookkeepingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_accounts')]
#[Description('列出账本内的全部账户及余额，包含已停用账户。')]
#[IsReadOnly]
#[IsIdempotent]
class ListAccounts extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        abort_unless(app(BookkeepingService::class)->canRead($context), 403, 'FORBIDDEN');
        $accounts = app(BookkeepingAccountService::class)->accountsWithBalances($this->ledger($request))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => $this->present($account))
            ->all();

        return ['ok' => true, 'accounts' => $accounts, 'request_id' => $context->requestId];
    }

    /** @return array<string, bool|int|string> */
    private function present(Account $account): array
    {
        return ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'opening_balance' => $account->opening_balance, 'balance' => (string) $account->balance, 'sort_order' => $account->sort_order, 'is_active' => $account->is_active, 'is_default' => $account->is_default];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required()];
    }
}
