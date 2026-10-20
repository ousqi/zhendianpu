<?php

namespace App\Services\Bookkeeping;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
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
    public function __construct(private AccessResolver $accessResolver) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function add(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.add', function (array $data) use ($context, $source): array {
            $entries = [];
            foreach ($data['entries'] as $entry) {
                $entries[] = $this->createEntry($context->user, $entry, $source);
            }

            return ['entries' => array_map(fn (Entry $entry): array => $this->present($entry->load(['category', 'account'])), $entries)];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(AccessContext $context, array $input, string $source = 'chatgpt'): array
    {
        return $this->execute($context, $input, 'bookkeeping.update', function (array $data) use ($context): array {
            $entry = Entry::query()->where('public_id', $data['entry_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $entry) {
                $this->fail('entry_id', '记录不存在或已撤销。');
            }
            if ($entry->revision !== $data['revision']) {
                $this->fail('revision', '记录已被更新，请先查询最新版本。');
            }
            $before = $this->present($entry->load(['category', 'account']));
            $changes = array_filter([
                'direction' => $data['direction'] ?? null,
                'amount' => $data['amount'] ?? null,
                'category_id' => isset($data['category_id']) ? $this->category($data['category_id'], $data['direction'] ?? $entry->direction)->id : null,
                'account_id' => isset($data['account_id']) ? $this->account($data['account_id'])->id : null,
                'occurred_on' => $data['occurred_on'] ?? null,
            ], fn (mixed $value): bool => $value !== null);
            if (array_key_exists('description', $data)) {
                $changes['description'] = $data['description'];
            }
            $direction = $changes['direction'] ?? $entry->direction;
            $category = isset($changes['category_id']) ? Category::findOrFail($changes['category_id']) : $entry->category;
            $this->validateEntry($direction, $changes['amount'] ?? $entry->amount, $category, $changes['occurred_on'] ?? $entry->occurred_on->toDateString());
            $entry->fill([...$changes, 'updated_by' => $context->user->id, 'revision' => $entry->revision + 1])->save();

            return ['before' => $before, 'entry' => $this->present($entry->refresh()->load(['category', 'account']))];
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function void(AccessContext $context, array $input): array
    {
        return $this->execute($context, $input, 'bookkeeping.void', function (array $data) use ($context): array {
            $entry = Entry::query()->where('public_id', $data['entry_id'])->whereNull('voided_at')->lockForUpdate()->first();
            if (! $entry) {
                $this->fail('entry_id', '记录不存在或已撤销。');
            }
            if ($entry->revision !== $data['revision']) {
                $this->fail('revision', '记录已被更新，请先查询最新版本。');
            }
            $before = $this->present($entry->load(['category', 'account']));
            $entry->update(['voided_at' => now(), 'voided_by' => $context->user->id, 'updated_by' => $context->user->id, 'revision' => $entry->revision + 1]);

            return ['before' => $before, 'entry' => $this->present($entry->refresh()->load(['category', 'account']))];
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createEntry(User $user, array $attributes, string $source): Entry
    {
        return DB::transaction(function () use ($user, $attributes, $source): Entry {
            $category = $this->category((int) $attributes['category_id'], $attributes['direction']);
            $account = isset($attributes['account_id']) ? $this->account((int) $attributes['account_id']) : app(BookkeepingAccountService::class)->defaultAccount();
            $occurredOn = $attributes['occurred_on'] ?? now('Asia/Shanghai')->toDateString();
            $this->validateEntry($attributes['direction'], $attributes['amount'], $category, $occurredOn);

            return Entry::create([
                'direction' => $attributes['direction'], 'amount' => $attributes['amount'], 'category_id' => $category->id, 'account_id' => $account->id,
                'occurred_on' => $occurredOn, 'description' => $attributes['description'] ?? null, 'source' => $source,
                'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
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
        return ['id' => $entry->public_id, 'revision' => $entry->revision, 'direction' => $entry->direction,
            'amount' => $entry->amount, 'category_id' => $entry->category_id, 'category_name' => $entry->category->name,
            'account_id' => $entry->account_id, 'account_name' => $entry->account->name,
            'occurred_on' => $entry->occurred_on->toDateString(), 'description' => $entry->description,
            'source' => $entry->source, 'voided_at' => $entry->voided_at?->toAtomString()];
    }

    /** @param array<string, mixed> $input @param Closure(array<string, mixed>): array<string, mixed> $operation @return array<string, mixed> */
    private function execute(AccessContext $context, array $input, string $action, Closure $operation): array
    {
        if (! $this->canWrite($context)) {
            abort(403, 'FORBIDDEN');
        }
        $data = $this->validate($input, $action);
        $workspaceId = $context->workspaces[0]['id'];
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($action, $context, $data, $hash, $operation, $workspaceId): array {
            $previous = DB::table('mcp_operations')->where('workspace_id', $workspaceId)->where('server_id', $context->server->id)
                ->where('user_id', $context->user->id)->where('access_scope_key', 'bookkeeping')->where('idempotency_key', $data['event_id'])->lockForUpdate()->first();
            if ($previous) {
                if ($previous->tool_name !== $action || $previous->parameters_hash !== $hash) {
                    $this->fail('event_id', '同一事件编号已用于不同内容。');
                }

                return [...json_decode($previous->result, true, flags: JSON_THROW_ON_ERROR), 'replayed' => true, 'request_id' => $context->requestId];
            }
            $result = ['ok' => true, ...$operation($data), 'replayed' => false, 'request_id' => $context->requestId];
            $operationId = DB::table('mcp_operations')->insertGetId([
                'public_id' => (string) str()->ulid(), 'workspace_id' => $workspaceId, 'server_id' => $context->server->id, 'user_id' => $context->user->id,
                'module_key' => 'zhendianpu', 'access_scope_key' => 'bookkeeping', 'scope' => json_encode([], JSON_THROW_ON_ERROR), 'tool_name' => $action,
                'idempotency_key' => $data['event_id'], 'parameters_hash' => $hash, 'draft' => json_encode($data, JSON_THROW_ON_ERROR),
                'status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'request_id' => $context->requestId, 'operation_id' => $operationId, 'user_id' => $context->user->id, 'workspace_id' => $workspaceId,
                'server_id' => $context->server->id, 'module_key' => 'zhendianpu', 'scope' => json_encode([], JSON_THROW_ON_ERROR),
                'action' => $action, 'entity_type' => 'bookkeeping_entry', 'entity_id' => $result['entry']['id'] ?? null,
                'before' => isset($result['before']) ? json_encode($result['before'], JSON_THROW_ON_ERROR) : null,
                'after' => json_encode($result['entry'] ?? $result['entries'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'created_at' => now(),
            ]);

            return $result;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validate(array $input, string $action): array
    {
        $rules = match ($action) {
            'bookkeeping.add' => ['event_id' => ['required', 'ulid'], 'entries' => ['required', 'array', 'min:1', 'max:50'], 'entries.*.direction' => ['required', 'in:income,expense'], 'entries.*.amount' => ['required', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'entries.*.category_id' => ['required', 'integer'], 'entries.*.account_id' => ['nullable', 'integer'], 'entries.*.occurred_on' => ['nullable', 'date_format:Y-m-d'], 'entries.*.description' => ['nullable', 'string', 'max:1000']],
            'bookkeeping.update' => ['event_id' => ['required', 'ulid'], 'entry_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1'], 'direction' => ['nullable', 'in:income,expense'], 'amount' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/D', 'gt:0'], 'category_id' => ['nullable', 'integer'], 'account_id' => ['nullable', 'integer'], 'occurred_on' => ['nullable', 'date_format:Y-m-d'], 'description' => ['nullable', 'string', 'max:1000']],
            default => ['event_id' => ['required', 'ulid'], 'entry_id' => ['required', 'ulid'], 'revision' => ['required', 'integer', 'min:1']],
        };
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            $this->fail('arguments', '存在不支持的参数。');
        }

        return Validator::make($input, $rules)->validate();
    }

    private function category(int $categoryId, string $direction): Category
    {
        $category = Category::query()->whereKey($categoryId)->where('is_active', true)->first();
        if (! $category || $category->direction !== $direction) {
            $this->fail('category_id', '分类不存在、已停用或与收支方向不一致。');
        }

        return $category;
    }

    private function account(int $accountId): Account
    {
        $account = Account::query()->whereKey($accountId)->lockForUpdate()->first();
        if (! $account || ! $account->is_active) {
            $this->fail('account_id', '账户不存在或已停用。');
        }

        return $account;
    }

    private function validateEntry(string $direction, string $amount, Category $category, string $occurredOn): void
    {
        if (! in_array($direction, ['income', 'expense'], true) || $category->direction !== $direction || bccomp($amount, '0', 2) !== 1) {
            $this->fail('entry', '收支方向、分类和金额必须有效且一致。');
        }
        if (Carbon::parse($occurredOn, 'Asia/Shanghai')->isAfter(now('Asia/Shanghai')->endOfDay())) {
            $this->fail('occurred_on', '只能记录已经发生的收支，不能使用未来日期。');
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
