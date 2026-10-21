<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use App\Models\User;
use App\Services\Mcp\AccessResolver;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BookkeepingService
{
    public function __construct(private AccessResolver $accessResolver, private LedgerService $ledgers) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function add(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.add', function (array $data, Ledger $ledger) use ($context, $source): array {
            $entries = array_map(fn (array $entry): Entry => $this->createEntry($context->user, $ledger, $entry, $source), $data['entries']);

            return ['entries' => array_map(fn (Entry $entry): array => $this->present($entry->load(['category', 'account'])), $entries)];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.update', function (array $data, Ledger $ledger) use ($context): array {
            $entry = Entry::query()->where('ledger_id', $ledger->id)->where('public_id', $data['entry_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $entry || $entry->revision !== $data['revision']) {
                $this->fail($entry ? 'revision' : 'entry_id', $entry ? '记录已被更新，请先查询最新版本。' : '记录不存在或已撤销。');
            }
            $before = $this->present($entry->load(['category', 'account']));
            $direction = $data['direction'] ?? $entry->direction;
            $category = isset($data['category_id']) ? $this->category($ledger, $data['category_id'], $direction) : $entry->category;
            $account = isset($data['account_id']) ? $this->account($ledger, $data['account_id']) : $entry->account;
            $occurredOn = $data['occurred_on'] ?? $entry->occurred_on->toDateString();
            $this->validateEntry($direction, $data['amount'] ?? $entry->amount, $category, $account, $occurredOn);
            $this->assertRefundsAllowUpdate($entry, $direction, $category, $data['amount'] ?? $entry->amount);
            $changes = array_filter(['direction' => $data['direction'] ?? null, 'amount' => $data['amount'] ?? null, 'category_id' => $category->id, 'account_id' => $account->id, 'occurred_on' => $data['occurred_on'] ?? null], fn (mixed $value): bool => $value !== null);
            if (array_key_exists('description', $data)) {
                $changes['description'] = $data['description'];
            }
            $entry->fill([...$changes, 'updated_by' => $context->user->id, 'revision' => $entry->revision + 1])->save();

            return ['before' => $before, 'entry' => $this->present($entry->refresh()->load(['category', 'account']))];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function void(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.void', function (array $data, Ledger $ledger) use ($context): array {
            $entry = Entry::query()->where('ledger_id', $ledger->id)->where('public_id', $data['entry_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $entry || $entry->revision !== $data['revision']) {
                $this->fail($entry ? 'revision' : 'entry_id', $entry ? '记录已被更新，请先查询最新版本。' : '记录不存在或已撤销。');
            }
            $before = $this->present($entry->load(['category', 'account']));
            if (Refund::query()->where('entry_id', $entry->id)->whereNull('voided_at')->lockForUpdate()->exists()) {
                $this->fail('entry_id', '存在有效退款记录的收支不能撤销。');
            }
            $entry->update(['voided_at' => now(), 'voided_by' => $context->user->id, 'updated_by' => $context->user->id, 'revision' => $entry->revision + 1]);

            return ['before' => $before, 'entry' => $this->present($entry->refresh()->load(['category', 'account']))];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createEntry(User $user, Ledger $ledger, array $attributes, string $source): Entry
    {
        $this->assertActive($ledger);

        return DB::transaction(function () use ($user, $ledger, $attributes, $source): Entry {
            $category = $this->category($ledger, (int) $attributes['category_id'], $attributes['direction']);
            $account = isset($attributes['account_id']) ? $this->account($ledger, (int) $attributes['account_id']) : app(BookkeepingAccountService::class)->defaultAccount($ledger);
            $occurredOn = $attributes['occurred_on'] ?? now($ledger->timezone)->toDateString();
            $this->validateEntry($attributes['direction'], $attributes['amount'], $category, $account, $occurredOn);

            return Entry::create(['ledger_id' => $ledger->id, 'direction' => $attributes['direction'], 'amount' => $attributes['amount'], 'category_id' => $category->id, 'account_id' => $account->id, 'occurred_on' => $occurredOn, 'description' => $attributes['description'] ?? null, 'source' => $source, 'created_by' => $user->id, 'updated_by' => $user->id]);
        }, attempts: 3);
    }

    public function canWrite(AccessContext $context): bool
    {
        return $this->canRead($context) && in_array($context->workspaces[0]['role'], ['writer', 'manager'], true);
    }

    public function canRead(AccessContext $context): bool
    {
        return count($context->workspaces) === 1;
    }

    public function canAccess(User $user): bool
    {
        try {
            return $this->canRead($this->accessResolver->resolve($user, 'zhendianpu'));
        } catch (HttpException) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function present(Entry $entry): array
    {
        $entryData = ['id' => $entry->public_id, 'ledger_id' => $entry->ledger->public_id, 'revision' => $entry->revision, 'direction' => $entry->direction, 'amount' => $entry->amount, 'category_id' => $entry->category_id, 'category_name' => $entry->category->name, 'account_id' => $entry->account_id, 'account_name' => $entry->account->name, 'occurred_on' => $entry->occurred_on->toDateString(), 'description' => $entry->description, 'source' => $entry->source, 'voided_at' => $entry->voided_at?->toAtomString()];
        if (array_key_exists('refunded_amount', $entry->getAttributes())) {
            $refundedAmount = bcadd((string) ($entry->refunded_amount ?? 0), '0', 2);

            return [...$entryData, 'refunded_amount' => $refundedAmount, 'net_amount' => bcsub((string) $entry->amount, $refundedAmount, 2)];
        }

        return $entryData;
    }

    /** @param array<string, mixed> $input @param Closure(array<string, mixed>, Ledger): array<string, mixed> $operation @return array<string, mixed> */
    private function execute(AccessContext $context, array $input, string $action, Closure $operation): array
    {
        abort_unless($this->canWrite($context), 403, 'FORBIDDEN');
        $data = $this->validate($input, $action);
        $ledger = $this->ledgers->active($data['ledger_id']);
        $workspaceId = $context->workspaces[0]['id'];
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($action, $context, $data, $ledger, $hash, $operation, $workspaceId): array {
            $previous = DB::table('mcp_operations')->where('workspace_id', $workspaceId)->where('server_id', $context->server->id)->where('user_id', $context->user->id)->where('access_scope_key', 'bookkeeping:'.$ledger->public_id)->where('idempotency_key', $data['event_id'])->lockForUpdate()->first();
            if ($previous) {
                if ($previous->tool_name !== $action || $previous->parameters_hash !== $hash) {
                    $this->fail('event_id', '同一事件编号已用于不同内容。');
                }

                return [...json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true, 'request_id' => $context->requestId];
            }
            $result = ['ok' => true, ...$operation($data, $ledger), 'replayed' => false, 'request_id' => $context->requestId];
            $operationId = DB::table('mcp_operations')->insertGetId(['public_id' => (string) str()->ulid(), 'workspace_id' => $workspaceId, 'server_id' => $context->server->id, 'user_id' => $context->user->id, 'module_key' => 'zhendianpu', 'access_scope_key' => 'bookkeeping:'.$ledger->public_id, 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'tool_name' => $action, 'idempotency_key' => $data['event_id'], 'parameters_hash' => $hash, 'draft' => json_encode($data, JSON_THROW_ON_ERROR), 'status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'operation_id' => $operationId, 'user_id' => $context->user->id, 'workspace_id' => $workspaceId, 'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'action' => $action, 'entity_type' => 'bookkeeping_entry', 'entity_id' => $result['entry']['id'] ?? null, 'before' => isset($result['before']) ? json_encode($result['before'], JSON_THROW_ON_ERROR) : null, 'after' => json_encode($result['entry'] ?? $result['entries'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validate(array $input, string $action): array
    {
        $common = [
            'ledger_id' => ['required', 'ulid'],
            'event_id' => ['required', 'ulid'],
        ];
        $rules = match ($action) {
            'bookkeeping.add' => [...$common, 'entries' => ['required', 'array', 'min:1', 'max:50'], 'entries.*.direction' => ['required', 'in:income,expense'], 'entries.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'entries.*.category_id' => ['required', 'integer'], 'entries.*.account_id' => ['nullable', 'integer'], 'entries.*.occurred_on' => ['nullable', 'date_format:Y-m-d'], 'entries.*.description' => ['nullable', 'string', 'max:1000']],
            'bookkeeping.update' => [...$common, 'entry_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'direction' => ['nullable', 'in:income,expense'], 'amount' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'category_id' => ['nullable', 'integer'], 'account_id' => ['nullable', 'integer'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            default => [...$common, 'entry_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1']],
        };
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('arguments', '存在不支持的参数。');
        }

        return Validator::make($input, $rules)->validate();
    }

    private function category(Ledger $ledger, int $id, string $direction): Category
    {
        $category = Category::query()->where('ledger_id', $ledger->id)->whereKey($id)->where('is_active', true)->first();
        if (! $category || $category->direction !== $direction || $category->children()->exists()) {
            $this->fail('category_id', '分类不存在、已停用、不是末级分类或与收支方向不一致。');
        }

        return $category;
    }

    private function account(Ledger $ledger, int $id): Account
    {
        $account = Account::query()->where('ledger_id', $ledger->id)->whereKey($id)->lockForUpdate()->first();
        if (! $account || ! $account->is_active) {
            $this->fail('account_id', '账户不存在、已停用或不属于当前账本。');
        }

        return $account;
    }

    private function validateEntry(string $direction, string $amount, Category $category, Account $account, string $occurredOn): void
    {
        if (! in_array($direction, ['income', 'expense'], true) || $category->direction !== $direction || $category->ledger_id !== $account->ledger_id || bccomp($amount, '0', 2) !== 1) {
            $this->fail('entry', '收支方向、分类、账户和金额必须有效且属于同一账本。');
        } if (Carbon::parse($occurredOn, $account->ledger->timezone)->isAfter(now($account->ledger->timezone)->endOfDay())) {
            $this->fail('occurred_on', '只能记录已经发生的收支，不能使用未来日期。');
        }
    }

    private function assertRefundsAllowUpdate(Entry $entry, string $direction, Category $category, string $amount): void
    {
        $refunds = Refund::query()->where('entry_id', $entry->id)->whereNull('voided_at')->lockForUpdate();
        $refunded = $refunds->sum('amount');
        if (bccomp((string) $refunded, $amount, 2) === 1) {
            $this->fail('amount', '收支金额不能低于累计有效退款金额。');
        }
        if (bccomp((string) $refunded, '0.00', 2) === 1 && ($direction !== $entry->direction || $category->id !== $entry->category_id)) {
            $this->fail('entry_id', '存在有效退款记录的收支不能修改方向或分类。');
        }
    }

    private function assertActive(Ledger $ledger): void
    {
        if ($ledger->status !== 'active') {
            $this->fail('ledger_id', '账本已归档，不能写入。');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param array<array-key,mixed> $value @return array<array-key,mixed> */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
