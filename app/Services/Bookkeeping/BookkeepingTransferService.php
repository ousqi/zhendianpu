<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Transfer;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BookkeepingTransferService
{
    public function __construct(private BookkeepingService $bookkeeping) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function add(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.transfer.add', function (array $data) use ($context, $source): array {
            $transfer = $this->create($context, $data, $source);

            return ['transfer' => $this->present($transfer)];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function void(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.transfer.void', function (array $data) use ($context): array {
            $transfer = Transfer::query()->where('public_id', $data['transfer_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $transfer) {
                $this->fail('transfer_id', '转账不存在或已撤销。');
            }
            if ($transfer->revision !== $data['revision']) {
                $this->fail('revision', '转账已被更新，请先查询最新版本。');
            }
            $before = $this->present($transfer);
            $transfer->update([
                'voided_at' => now(),
                'voided_by' => $context->user->id,
                'void_reason' => $data['void_reason'] ?? '未提供撤销原因',
                'updated_by' => $context->user->id,
                'revision' => $transfer->revision + 1,
            ]);

            return ['before' => $before, 'transfer' => $this->present($transfer->refresh())];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.transfer.update', function (array $data) use ($context): array {
            $transfer = Transfer::query()->where('public_id', $data['transfer_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $transfer) {
                $this->fail('transfer_id', '转账不存在或已撤销。');
            }
            if ($transfer->revision !== $data['revision']) {
                $this->fail('revision', '转账已被更新，请先查询最新版本。');
            }
            $before = $this->present($transfer);
            $changes = array_filter([
                'source_account_id' => $data['source_account_id'] ?? null,
                'destination_account_id' => $data['destination_account_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'occurred_on' => $data['occurred_on'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
            if (array_key_exists('description', $data)) {
                $changes['description'] = $data['description'];
            }
            $this->validateTransfer($changes['source_account_id'] ?? $transfer->source_account_id, $changes['destination_account_id'] ?? $transfer->destination_account_id, $changes['amount'] ?? $transfer->amount, $changes['occurred_on'] ?? $transfer->occurred_on->toDateString());
            $transfer->fill([...$changes, 'updated_by' => $context->user->id, 'revision' => $transfer->revision + 1])->save();

            return ['before' => $before, 'transfer' => $this->present($transfer->refresh())];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function create(AccessContext $context, array $attributes, string $source): Transfer
    {
        return DB::transaction(function () use ($context, $attributes, $source): Transfer {
            $occurredOn = $attributes['occurred_on'] ?? now('Asia/Shanghai')->toDateString();
            $this->validateTransfer($attributes['source_account_id'], $attributes['destination_account_id'], $attributes['amount'], $occurredOn);

            return Transfer::create(['source_account_id' => $attributes['source_account_id'], 'destination_account_id' => $attributes['destination_account_id'],
                'amount' => $attributes['amount'], 'occurred_on' => $occurredOn, 'description' => $attributes['description'] ?? null, 'source' => $source,
                'created_by' => $context->user->id, 'updated_by' => $context->user->id]);
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    public function present(Transfer $transfer): array
    {
        return ['id' => $transfer->public_id, 'revision' => $transfer->revision, 'source_account_id' => $transfer->source_account_id,
            'destination_account_id' => $transfer->destination_account_id, 'amount' => $transfer->amount, 'occurred_on' => $transfer->occurred_on->toDateString(),
            'description' => $transfer->description, 'source' => $transfer->source, 'voided_at' => $transfer->voided_at?->toAtomString(), 'void_reason' => $transfer->void_reason];
    }

    /** @param array<string, mixed> $input @param Closure(array<string, mixed>): array<string, mixed> $operation @return array<string, mixed> */
    private function execute(AccessContext $context, array $input, string $action, Closure $operation): array
    {
        abort_unless($this->bookkeeping->canWrite($context), 403, 'FORBIDDEN');
        $data = $this->validate($input, $action);
        $workspaceId = $context->workspaces[0]['id'];
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($action, $context, $data, $hash, $operation, $workspaceId): array {
            $previous = DB::table('mcp_operations')->where('workspace_id', $workspaceId)->where('server_id', $context->server->id)->where('user_id', $context->user->id)
                ->where('access_scope_key', 'bookkeeping')->where('idempotency_key', $data['event_id'])->lockForUpdate()->first();
            if ($previous) {
                if ($previous->tool_name !== $action || $previous->parameters_hash !== $hash) {
                    $this->fail('event_id', '同一事件编号已用于不同内容。');
                }

                return [...json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true, 'request_id' => $context->requestId];
            }
            $result = ['ok' => true, ...$operation($data), 'replayed' => false, 'request_id' => $context->requestId];
            $operationId = DB::table('mcp_operations')->insertGetId(['public_id' => (string) str()->ulid(), 'workspace_id' => $workspaceId, 'server_id' => $context->server->id,
                'user_id' => $context->user->id, 'module_key' => 'zhendianpu', 'access_scope_key' => 'bookkeeping', 'scope' => json_encode([], JSON_THROW_ON_ERROR),
                'tool_name' => $action, 'idempotency_key' => $data['event_id'], 'parameters_hash' => $hash, 'draft' => json_encode($data, JSON_THROW_ON_ERROR),
                'status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $transfer = $result['transfer'];
            DB::table('audit_logs')->insert(['request_id' => $context->requestId, 'operation_id' => $operationId, 'user_id' => $context->user->id, 'workspace_id' => $workspaceId,
                'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode([], JSON_THROW_ON_ERROR), 'action' => $action,
                'entity_type' => 'bookkeeping_transfer', 'entity_id' => $transfer['id'], 'before' => isset($result['before']) ? json_encode($result['before'], JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($transfer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now()]);

            return $result;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validate(array $input, string $action): array
    {
        $rules = match ($action) {
            'bookkeeping.transfer.add' => ['event_id' => ['required', 'ulid'], 'source_account_id' => ['required', 'integer', 'min:1'], 'destination_account_id' => ['required', 'integer', 'min:1'], 'amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            'bookkeeping.transfer.update' => ['event_id' => ['required', 'ulid'], 'transfer_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'source_account_id' => ['nullable', 'integer', 'min:1'], 'destination_account_id' => ['nullable', 'integer', 'min:1'], 'amount' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            default => ['event_id' => ['required', 'ulid'], 'transfer_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'void_reason' => ['nullable', 'string', 'max:1000']],
        };
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('arguments', '存在不支持的参数。');
        }

        $data = Validator::make($input, $rules)->validate();
        if ($action === 'bookkeeping.transfer.update' && ! array_key_exists('description', $data) && ! array_filter([
            $data['source_account_id'] ?? null,
            $data['destination_account_id'] ?? null,
            $data['amount'] ?? null,
            $data['occurred_on'] ?? null,
        ], fn (mixed $value): bool => $value !== null)) {
            $this->fail('arguments', '至少提供一个可编辑字段。');
        }

        return $data;
    }

    private function validateTransfer(int $sourceAccountId, int $destinationAccountId, string $amount, string $occurredOn): void
    {
        if ($sourceAccountId === $destinationAccountId) {
            $this->fail('destination_account_id', '转出和转入账户必须不同。');
        }
        $accounts = Account::query()->whereIn('id', [$sourceAccountId, $destinationAccountId])->orderBy('id')->lockForUpdate()->get();
        if ($accounts->count() !== 2 || $accounts->contains(fn (Account $account): bool => ! $account->is_active) || bccomp($amount, '0', 2) !== 1) {
            $this->fail('transfer', '转账账户必须启用且金额必须大于零。');
        }
        if (Carbon::parse($occurredOn, 'Asia/Shanghai')->isAfter(now('Asia/Shanghai')->endOfDay())) {
            $this->fail('occurred_on', '只能记录已经发生的转账，不能使用未来日期。');
        }
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
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }
}
