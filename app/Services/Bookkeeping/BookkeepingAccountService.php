<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingAccountService
{
    public function __construct(private BookkeepingService $bookkeeping) {}

    /** @param array<string, mixed> $attributes */
    public function create(AccessContext $context, array $attributes): Account
    {
        $this->authorize($context);
        $data = $this->validate($attributes, false);

        return DB::transaction(function () use ($context, $data): Account {
            $account = Account::create([...$data, 'is_default' => null]);
            $this->audit($context, 'bookkeeping.account.created', $account, null);

            return $account;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(AccessContext $context, Account $account, array $attributes): Account
    {
        $this->authorize($context);
        $data = $this->validate($attributes, true);

        return DB::transaction(function () use ($context, $account, $data): Account {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            $before = $this->present($account);
            if ($account->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => '默认账户不能停用。']);
            }
            $account->update($data);
            $this->audit($context, 'bookkeeping.account.updated', $account, $before);

            return $account;
        }, attempts: 3);
    }

    public function setDefault(AccessContext $context, Account $account): Account
    {
        $this->authorize($context);

        return DB::transaction(function () use ($context, $account): Account {
            $accounts = Account::query()->lockForUpdate()->get();
            $account = $accounts->firstWhere('id', $account->id) ?? throw new ModelNotFoundException;
            if (! $account->is_active) {
                throw ValidationException::withMessages(['account' => '只能将启用账户设为默认账户。']);
            }
            $before = $this->present($account);
            Account::query()->where('is_default', true)->update(['is_default' => null]);
            $account->update(['is_default' => true]);
            $this->audit($context, 'bookkeeping.account.defaulted', $account, $before);

            return $account;
        }, attempts: 3);
    }

    public function delete(AccessContext $context, Account $account): void
    {
        $this->authorize($context);
        DB::transaction(function () use ($context, $account): void {
            $account = Account::query()->lockForUpdate()->findOrFail($account->id);
            if ($account->is_default) {
                throw ValidationException::withMessages(['account' => '默认账户不能删除。']);
            }
            if (Entry::query()->where('account_id', $account->id)->exists() || Transfer::query()->where('source_account_id', $account->id)->orWhere('destination_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['account' => '已被收支或转账引用的账户不能删除。']);
            }
            $before = $this->present($account);
            $account->delete();
            $this->audit($context, 'bookkeeping.account.deleted', $account, $before);
        }, attempts: 3);
    }

    public function defaultAccount(): Account
    {
        return DB::transaction(function (): Account {
            $account = Account::query()->where('is_default', true)->lockForUpdate()->first();
            if ($account) {
                return $account;
            }

            return Account::create(['name' => '默认账户', 'type' => 'other', 'sort_order' => 0, 'is_active' => true, 'is_default' => true]);
        }, attempts: 3);
    }

    public function accountsWithBalances(): Builder
    {
        $entries = Entry::query()->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as entry_balance")
            ->whereNull('voided_at')->groupBy('account_id');
        $outgoing = Transfer::query()->selectRaw('source_account_id, COALESCE(SUM(amount), 0) as outgoing_balance')
            ->whereNull('voided_at')->groupBy('source_account_id');
        $incoming = Transfer::query()->selectRaw('destination_account_id, COALESCE(SUM(amount), 0) as incoming_balance')
            ->whereNull('voided_at')->groupBy('destination_account_id');

        return Account::query()->select('bookkeeping_accounts.*')->leftJoinSub($entries, 'entry_balances', 'entry_balances.account_id', '=', 'bookkeeping_accounts.id')
            ->leftJoinSub($outgoing, 'outgoing_balances', 'outgoing_balances.source_account_id', '=', 'bookkeeping_accounts.id')
            ->leftJoinSub($incoming, 'incoming_balances', 'incoming_balances.destination_account_id', '=', 'bookkeeping_accounts.id')
            ->selectRaw('CAST(opening_balance + COALESCE(entry_balance, 0) - COALESCE(outgoing_balance, 0) + COALESCE(incoming_balance, 0) AS DECIMAL(30, 2)) as balance');
    }

    /** @return array{id: int, name: string, type: string, opening_balance: string, sort_order: int, is_active: bool, is_default: ?bool, balance: string} */
    public function present(Account $account): array
    {
        $balance = $account->getAttribute('balance') ?? $this->accountsWithBalances()->whereKey($account->id)->value('balance');

        return ['id' => $account->id, 'name' => $account->name, 'type' => $account->type, 'opening_balance' => $account->opening_balance, 'sort_order' => $account->sort_order,
            'is_active' => $account->is_active, 'is_default' => $account->is_default,
            'balance' => (string) $balance];
    }

    private function authorize(AccessContext $context): void
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
    }

    /** @param array<string, mixed> $attributes @return array<string, mixed> */
    private function validate(array $attributes, bool $isUpdate): array
    {
        if (! $isUpdate) {
            $attributes += ['opening_balance' => '0', 'sort_order' => 0];
        }

        return Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:'.implode(',', Account::TYPES)],
            'opening_balance' => [$isUpdate ? 'sometimes' : 'required', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D'],
            'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => ['required', 'boolean'],
        ])->validate();
    }

    /** @param array<string, mixed>|null $before */
    private function audit(AccessContext $context, string $action, Account $account, ?array $before): void
    {
        DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'user_id' => $context->user->id, 'workspace_id' => $context->workspaces[0]['id'],
            'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode([], JSON_THROW_ON_ERROR), 'action' => $action,
            'entity_type' => 'bookkeeping_account', 'entity_id' => (string) $account->id,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after' => json_encode($this->present($account), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
