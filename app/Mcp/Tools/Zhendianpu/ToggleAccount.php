<?php

namespace App\Mcp\Tools\Zhendianpu;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Services\Bookkeeping\BookkeepingAccountService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('toggle_account')]
#[Description('切换所选账本内账户的启用状态；默认账户不能停用；需要记账写入权限。')]
class ToggleAccount extends BookkeepingTool
{
    protected function respond(AccessContext $context, Request $request): array
    {
        $ledger = $this->ledger($request);
        $account = Account::query()->where('ledger_id', $ledger->id)->findOrFail($request->integer('account_id'));
        $account = app(BookkeepingAccountService::class)->update($context, $ledger, $account, ['is_active' => ! $account->is_active]);

        return ['ok' => true, 'account' => $this->present($account), 'request_id' => $context->requestId];
    }

    /** @return array<string, bool|int|string> */
    private function present(Account $account): array
    {
        return ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'opening_balance' => $account->opening_balance, 'sort_order' => $account->sort_order, 'is_active' => $account->is_active, 'is_default' => $account->is_default];
    }

    /** @return array<string, Type> */
    public function schema(JsonSchema $schema): array
    {
        return ['ledger_id' => $schema->string()->pattern('^[0-7][0-9A-HJKMNP-TV-Z]{25}$')->required(), 'account_id' => $schema->integer()->min(1)->required()];
    }
}
