<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingAccountService
{
    public function __construct(private BookkeepingService $bookkeeping, private LedgerService $ledgers) {}

    /** @param array<string,mixed> $attributes */
    public function create(AccessContext $context, Ledger $ledger, array $attributes): Account
    {
        $this->authorize($context, $ledger);
        $data = $this->validate($attributes, false);

        return DB::transaction(function () use ($context, $ledger, $data): Account {
            Ledger::query()->whereKey($ledger->id)->lockForUpdate()->firstOrFail();
            $account = Account::create([...$data, 'ledger_id' => $ledger->id, 'is_default' => null]);
            $this->audit($context, $ledger, 'bookkeeping.account.create', $account, null);

            return $account;
        }, attempts: 3);
    }

    /** @param array<string,mixed> $attributes */
    public function update(AccessContext $context, Ledger $ledger, Account $account, array $attributes): Account
    {
        $this->authorize($context, $ledger);
        $data = $this->validate($attributes, true);

        return DB::transaction(function () use ($context, $ledger, $account, $data): Account {
            $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($ledger, $account);
            if ($account->is_default && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => '默认账户不能停用。']);
            }
            $before = $account->only(['name', 'type', 'opening_balance', 'sort_order', 'is_active', 'is_default']);
            $account->update($data);
            $account = $account->refresh();
            $this->audit($context, $ledger, 'bookkeeping.account.update', $account, $before);

            return $account;
        }, attempts: 3);
    }

    public function setDefault(AccessContext $context, Ledger $ledger, Account $account): Account
    {
        $this->authorize($context, $ledger);

        return DB::transaction(function () use ($context, $ledger, $account): Account {
            $accounts = Account::query()->where('ledger_id', $ledger->id)->lockForUpdate()->get();
            $account = $accounts->firstWhere('id', $account->id) ?? throw ValidationException::withMessages(['account' => '账户不属于当前账本。']);
            if (! $account->is_active) {
                throw ValidationException::withMessages(['account' => '只能将启用账户设为默认账户。']);
            }
            Account::query()->where('ledger_id', $ledger->id)->where('is_default', true)->update(['is_default' => null]);
            $account->update(['is_default' => true]);
            $this->audit($context, $ledger, 'bookkeeping.account.set_default', $account, null);

            return $account->refresh();
        });
    }

    public function delete(AccessContext $context, Ledger $ledger, Account $account): void
    {
        $this->authorize($context, $ledger);
        DB::transaction(function () use ($context, $ledger, $account): void {
            $account = Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $this->assertOwned($ledger, $account);
            if ($account->is_default) {
                throw ValidationException::withMessages(['account' => '默认账户不能删除。']);
            }
            if (Entry::query()->where('ledger_id', $ledger->id)->where('account_id', $account->id)->lockForUpdate()->exists() || Transfer::query()->where('ledger_id', $ledger->id)->where(fn (Builder $q) => $q->where('source_account_id', $account->id)->orWhere('destination_account_id', $account->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['account' => '已被收支或转账引用的账户不能删除。']);
            }
            $before = $account->toArray();
            $account->delete();
            $this->audit($context, $ledger, 'bookkeeping.account.delete', $account, $before);
        }, attempts: 3);
    }

    public function defaultAccount(Ledger $ledger): Account
    {
        $this->assertActive($ledger);

        return DB::transaction(function () use ($ledger): Account {
            return Account::query()->where('ledger_id', $ledger->id)->where('is_default', true)->lockForUpdate()->first() ?? Account::create(['ledger_id' => $ledger->id, 'name' => '默认账户', 'type' => 'other', 'sort_order' => 0, 'is_active' => true, 'is_default' => true]);
        });
    }

    public function accountsWithBalances(Ledger $ledger): Builder
    {
        $entries = Entry::query()->where('ledger_id', $ledger->id)->selectRaw("account_id, COALESCE(SUM(CASE WHEN direction = 'income' THEN amount ELSE -amount END), 0) as entry_balance")->whereNull('voided_at')->groupBy('account_id');
        $outgoing = Transfer::query()->where('ledger_id', $ledger->id)->selectRaw('source_account_id, COALESCE(SUM(amount), 0) as outgoing_balance')->whereNull('voided_at')->groupBy('source_account_id');
        $incoming = Transfer::query()->where('ledger_id', $ledger->id)->selectRaw('destination_account_id, COALESCE(SUM(amount), 0) as incoming_balance')->whereNull('voided_at')->groupBy('destination_account_id');

        return Account::query()->where('bookkeeping_accounts.ledger_id', $ledger->id)->select('bookkeeping_accounts.*')->leftJoinSub($entries, 'entry_balances', 'entry_balances.account_id', '=', 'bookkeeping_accounts.id')->leftJoinSub($outgoing, 'outgoing_balances', 'outgoing_balances.source_account_id', '=', 'bookkeeping_accounts.id')->leftJoinSub($incoming, 'incoming_balances', 'incoming_balances.destination_account_id', '=', 'bookkeeping_accounts.id')->selectRaw('CAST(opening_balance + COALESCE(entry_balance, 0) - COALESCE(outgoing_balance, 0) + COALESCE(incoming_balance, 0) AS DECIMAL(30, 2)) as balance');
    }

    private function authorize(AccessContext $context, Ledger $ledger): void
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
        $this->assertActive($ledger);
    }

    private function assertOwned(Ledger $ledger, Account $account): void
    {
        if ($account->ledger_id !== $ledger->id) {
            throw ValidationException::withMessages(['account' => '账户不属于当前账本。']);
        }
    }

    private function assertActive(Ledger $ledger): void
    {
        if ($ledger->status !== 'active') {
            throw ValidationException::withMessages(['ledger_id' => '账本已归档，不能写入。']);
        }
    }

    /** @param array<string,mixed> $attributes @return array<string,mixed> */
    private function validate(array $attributes, bool $update): array
    {
        if (! $update) {
            $attributes += ['opening_balance' => '0', 'sort_order' => 0];
        }

        return Validator::make($attributes, ['name' => [$update ? 'sometimes' : 'required', 'string', 'max:255'], 'type' => [$update ? 'sometimes' : 'required', 'in:'.implode(',', Account::TYPES)], 'opening_balance' => [$update ? 'sometimes' : 'required', 'regex:/^-?\d{1,12}(?:\.\d{1,2})?$/D'], 'sort_order' => ['sometimes', 'integer', 'min:0'], 'is_active' => [$update ? 'sometimes' : 'required', 'boolean']])->validate();
    }

    /** @param array<string, mixed>|null $before */
    private function audit(AccessContext $context, Ledger $ledger, string $action, Account $account, ?array $before): void
    {
        DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'user_id' => $context->user->id, 'workspace_id' => $context->workspaces[0]['id'], 'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'action' => $action, 'entity_type' => 'bookkeeping_account', 'entity_id' => (string) $account->id, 'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($account->only(['id', 'name', 'type', 'opening_balance', 'sort_order', 'is_active', 'is_default']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
}
