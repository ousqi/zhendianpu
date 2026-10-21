<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingRefundService
{
    public function __construct(private BookkeepingService $bookkeeping, private LedgerService $ledgers) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function list(AccessContext $context, array $input): array
    {
        abort_unless($this->bookkeeping->canRead($context), 403, 'FORBIDDEN');
        $data = Validator::make($input, ['ledger_id' => ['required', 'ulid'], 'entry_id' => ['nullable', 'ulid'], 'include_voided' => ['nullable', 'boolean']])->validate();
        $ledger = $this->ledgers->get($data['ledger_id']);
        $refunds = Refund::query()->where('ledger_id', $ledger->id)->when(empty($data['include_voided']), fn ($query) => $query->whereNull('voided_at'))->when(isset($data['entry_id']), fn ($query) => $query->whereHas('entry', fn ($entry) => $entry->where('public_id', $data['entry_id'])))->with(['ledger', 'entry.category', 'account'])->orderByDesc('occurred_on')->orderByDesc('id')->get();

        return ['ok' => true, 'request_id' => $context->requestId, 'refunds' => $refunds->map(fn (Refund $refund): array => $this->present($refund))->all()];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function add(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.refund.add', function (array $data, Ledger $ledger) use ($context, $source): array {
            $refund = $this->create($context, $ledger, $data, $source);

            return ['refund' => $this->present($refund)];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.refund.update', function (array $data, Ledger $ledger) use ($context): array {
            $refund = Refund::query()->where('ledger_id', $ledger->id)->where('public_id', $data['refund_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $refund) {
                $this->fail('refund_id', '退款记录不存在或已撤销。');
            }
            if ($refund->revision !== $data['revision']) {
                $this->fail('revision', '退款记录已被更新，请先查询最新版本。');
            }
            $entry = $this->entry($ledger, $refund->entry_id);
            $account = isset($data['account_id']) ? $this->account($ledger, $data['account_id']) : $refund->account;
            $occurredOn = $data['occurred_on'] ?? $refund->occurred_on->toDateString();
            $amount = $data['amount'] ?? $refund->amount;
            $this->validateRefund($ledger, $entry, $account, $amount, $occurredOn, $refund->id);
            $before = $this->present($refund->load(['entry.category', 'account', 'ledger']));
            $changes = array_filter(['account_id' => $data['account_id'] ?? null, 'amount' => $data['amount'] ?? null, 'occurred_on' => $data['occurred_on'] ?? null], fn (mixed $value): bool => $value !== null);
            if (array_key_exists('description', $data)) {
                $changes['description'] = $data['description'];
            }
            $refund->fill([...$changes, 'updated_by' => $context->user->id, 'revision' => $refund->revision + 1])->save();

            return ['before' => $before, 'refund' => $this->present($refund->refresh()->load(['entry.category', 'account', 'ledger']))];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function void(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.refund.void', function (array $data, Ledger $ledger) use ($context): array {
            $refund = Refund::query()->where('ledger_id', $ledger->id)->where('public_id', $data['refund_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $refund) {
                $this->fail('refund_id', '退款记录不存在或已撤销。');
            }
            if ($refund->revision !== $data['revision']) {
                $this->fail('revision', '退款记录已被更新，请先查询最新版本。');
            }
            $before = $this->present($refund->load(['entry.category', 'account', 'ledger']));
            $refund->update(['voided_at' => now(), 'voided_by' => $context->user->id, 'updated_by' => $context->user->id, 'revision' => $refund->revision + 1]);

            return ['before' => $before, 'refund' => $this->present($refund->refresh()->load(['entry.category', 'account', 'ledger']))];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(AccessContext $context, Ledger $ledger, array $attributes, string $source): Refund
    {
        return DB::transaction(function () use ($context, $ledger, $attributes, $source): Refund {
            $entry = $this->entry($ledger, $attributes['entry_id']);
            $account = $this->account($ledger, $attributes['account_id']);
            $occurredOn = $attributes['occurred_on'] ?? now($ledger->timezone)->toDateString();
            $this->validateRefund($ledger, $entry, $account, $attributes['amount'], $occurredOn);

            return Refund::create(['ledger_id' => $ledger->id, 'entry_id' => $entry->id, 'account_id' => $account->id, 'amount' => $attributes['amount'], 'occurred_on' => $occurredOn, 'description' => $attributes['description'] ?? null, 'source' => $source, 'created_by' => $context->user->id, 'updated_by' => $context->user->id]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function present(Refund $refund): array
    {
        $entry = $refund->entry;

        return ['id' => $refund->public_id, 'ledger_id' => $refund->ledger->public_id, 'revision' => $refund->revision, 'amount' => $refund->amount, 'occurred_on' => $refund->occurred_on->toDateString(), 'account_id' => $refund->account_id, 'account_name' => $refund->account->name, 'original_entry' => ['id' => $entry->public_id, 'direction' => $entry->direction, 'category_id' => $entry->category_id, 'category_name' => $entry->category->name], 'direction' => $entry->direction, 'description' => $refund->description, 'source' => $refund->source, 'voided_at' => $refund->voided_at?->toAtomString()];
    }

    /** @param array<string, mixed> $input @param Closure(array<string, mixed>, Ledger): array<string, mixed> $operation @return array<string, mixed> */
    private function execute(AccessContext $context, array $input, string $action, Closure $operation): array
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
        $data = $this->validate($input, $action);
        $ledger = $this->ledgers->active($data['ledger_id']);
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($context, $data, $action, $ledger, $hash, $operation): array {
            $workspaceId = $context->workspaces[0]['id'];
            $previous = DB::table('mcp_operations')->where('workspace_id', $workspaceId)->where('server_id', $context->server->id)->where('user_id', $context->user->id)->where('access_scope_key', 'bookkeeping:'.$ledger->public_id)->where('idempotency_key', $data['event_id'])->lockForUpdate()->first();
            if ($previous) {
                if ($previous->tool_name !== $action || $previous->parameters_hash !== $hash) {
                    $this->fail('event_id', '同一事件编号已用于不同内容。');
                }

                return [...json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true, 'request_id' => $context->requestId];
            }
            $result = ['ok' => true, ...$operation($data, $ledger), 'replayed' => false, 'request_id' => $context->requestId];
            $operationId = DB::table('mcp_operations')->insertGetId(['public_id' => (string) str()->ulid(), 'workspace_id' => $workspaceId, 'server_id' => $context->server->id, 'user_id' => $context->user->id, 'module_key' => 'zhendianpu', 'access_scope_key' => 'bookkeeping:'.$ledger->public_id, 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'tool_name' => $action, 'idempotency_key' => $data['event_id'], 'parameters_hash' => $hash, 'draft' => json_encode($data, JSON_THROW_ON_ERROR), 'status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'operation_id' => $operationId, 'user_id' => $context->user->id, 'workspace_id' => $workspaceId, 'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode(['ledger_id' => $ledger->public_id], JSON_THROW_ON_ERROR), 'action' => $action, 'entity_type' => 'bookkeeping_refund', 'entity_id' => $result['refund']['id'], 'before' => isset($result['before']) ? json_encode($result['before'], JSON_THROW_ON_ERROR) : null, 'after' => json_encode($result['refund'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    private function entry(Ledger $ledger, string|int $id): Entry
    {
        $entry = Entry::query()->where('ledger_id', $ledger->id)->where(is_string($id) ? 'public_id' : 'id', $id)->whereNull('voided_at')->lockForUpdate()->first();
        if (! $entry) {
            $this->fail('entry_id', '原收支记录不存在、已撤销或不属于当前账本。');
        }

        return $entry;
    }

    private function account(Ledger $ledger, int $id): Account
    {
        $account = Account::query()->where('ledger_id', $ledger->id)->whereKey($id)->lockForUpdate()->first();
        if (! $account || ! $account->is_active) {
            $this->fail('account_id', '退款账户不存在、已停用或不属于当前账本。');
        }

        return $account;
    }

    private function validateRefund(Ledger $ledger, Entry $entry, Account $account, string $amount, string $occurredOn, ?int $excludingRefundId = null): void
    {
        if ($entry->ledger_id !== $ledger->id || $account->ledger_id !== $ledger->id || bccomp($amount, '0', 2) !== 1) {
            $this->fail('refund', '退款金额、原收支记录和退款账户必须有效且属于同一账本。');
        }
        if (Carbon::parse($occurredOn, $ledger->timezone)->isAfter(now($ledger->timezone)->endOfDay())) {
            $this->fail('occurred_on', '只能记录已经发生的退款，不能使用未来日期。');
        }
        $refunded = Refund::query()->where('entry_id', $entry->id)->whereNull('voided_at')->when($excludingRefundId, fn ($query) => $query->whereKeyNot($excludingRefundId))->lockForUpdate()->sum('amount');
        if (bccomp(bcadd((string) $refunded, $amount, 2), $entry->amount, 2) === 1) {
            $this->fail('amount', '累计有效退款金额不能超过原收支金额。');
        }
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validate(array $input, string $action): array
    {
        $common = ['ledger_id' => ['required', 'ulid'], 'event_id' => ['required', 'ulid']];
        $rules = match ($action) {
            'bookkeeping.refund.add' => [...$common, 'entry_id' => ['required', 'ulid'], 'account_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            'bookkeeping.refund.update' => [...$common, 'refund_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'account_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            default => [...$common, 'refund_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1']],
        };
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('arguments', '存在不支持的参数。');
        }
        $data = Validator::make($input, $rules)->validate();
        if ($action === 'bookkeeping.refund.update' && ! array_key_exists('description', $data) && ! array_filter([$data['account_id'] ?? null, $data['amount'] ?? null, $data['occurred_on'] ?? null], fn (mixed $value): bool => $value !== null)) {
            $this->fail('arguments', '至少提供一个可编辑字段。');
        }

        return $data;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /** @param array<array-key, mixed> $value @return array<array-key, mixed> */
    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        } foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

return $value;
    }
}
