<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Refund;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingRefundService;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use App\Services\Mcp\AccessResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class BookkeepingRefunds extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '退款记录';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.business-table';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BookkeepingService::class)->canAccess($user);
    }

    private function ledger(): Ledger
    {
        $user = auth()->user();

        return app(LedgerService::class)->current($user instanceof User ? $user : null);
    }

    private function refunds(): Builder
    {
        $refundTable = (new Refund)->getTable();

        return Refund::query()
            ->where('ledger_id', $this->ledger()->id)
            ->with(['entry.category', 'account'])
            ->select("{$refundTable}.*")
            ->selectSub(
                Refund::query()
                    ->from('bookkeeping_refunds as valid_refunds')
                    ->selectRaw('COALESCE(SUM(valid_refunds.amount), 0)')
                    ->whereColumn('valid_refunds.ledger_id', "{$refundTable}.ledger_id")
                    ->whereColumn('valid_refunds.entry_id', "{$refundTable}.entry_id")
                    ->whereNull('valid_refunds.voided_at'),
                'valid_refunds_total',
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->refunds())
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('entry.occurred_on')->label('原记录日期')->date('Y-m-d')->sortable(),
                TextColumn::make('entry.direction')->label('原方向')->badge()->formatStateUsing(fn (string $state): string => $state === 'income' ? '收入' : '支出'),
                TextColumn::make('entry.category.name')->label('原分类')->formatStateUsing(fn (string $state, Refund $record): string => $record->entry->category->displayName()),
                TextColumn::make('entry.amount')->label('原金额')->money($this->ledger()->currency),
                TextColumn::make('entry.description')->label('原说明')->placeholder('—')->wrap()->visibleFrom('lg'),
                TextColumn::make('occurred_on')->label('退款日期')->date('Y-m-d')->sortable(),
                TextColumn::make('amount')->label('退款金额')->money($this->ledger()->currency)->sortable(),
                TextColumn::make('account.name')->label('退款账户'),
                TextColumn::make('description')->label('退款说明')->placeholder('—')->wrap()->searchable(query: function (Builder $query, string $search): void {
                    $query->where('bookkeeping_refunds.description', 'like', "%{$search}%")
                        ->orWhereHas('entry', fn (Builder $query): Builder => $query->where('description', 'like', "%{$search}%")
                            ->orWhereHas('category', fn (Builder $query): Builder => $query->where('name', 'like', "%{$search}%")));
                }),
                TextColumn::make('status')->label('状态')->badge()->state(fn (Refund $record): string => $record->voided_at === null ? '有效' : '已撤销')->color(fn (string $state): string => $state === '有效' ? 'success' : 'gray'),
                TextColumn::make('valid_refunds_total')->label('有效退款合计')->money($this->ledger()->currency),
                TextColumn::make('net_amount')->label('净额')->state(fn (Refund $record): string => bcsub($record->entry->amount, (string) $record->valid_refunds_total, 2))->money($this->ledger()->currency),
            ])
            ->filters([
                Filter::make('occurred_on')->label('退款日期范围')->schema([
                    DatePicker::make('start')->label('开始日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d'),
                    DatePicker::make('end')->label('结束日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d'),
                ])->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['start'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_on', '>=', $date))
                    ->when($data['end'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_on', '<=', $date))),
                SelectFilter::make('direction')->label('原方向')->options(['income' => '收入', 'expense' => '支出'])
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $direction): Builder => $query->whereHas('entry', fn (Builder $query): Builder => $query->where('direction', $direction)))),
                SelectFilter::make('account_id')->label('退款账户')->options(fn (): array => Account::query()->where('ledger_id', $this->ledger()->id)->orderBy('sort_order')->pluck('name', 'id')->all())->searchable()->preload(),
                SelectFilter::make('voided')->label('退款状态')->options(['all' => '全部', 'valid' => '仅有效', 'voided' => '仅已撤销'])->default('all')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? 'all') {
                        'valid' => $query->whereNull('voided_at'),
                        'voided' => $query->whereNotNull('voided_at'),
                        default => $query,
                    }),
            ], layout: FiltersLayout::Modal)
            ->recordActions([
                Action::make('editRefund')
                    ->label('编辑退款')
                    ->visible(fn (Refund $record): bool => $this->canWrite() && $record->voided_at === null)
                    ->fillForm(fn (Refund $record): array => $record->only(['amount', 'account_id', 'occurred_on', 'description']))
                    ->schema([
                        TextInput::make('amount')->label('退款金额')->numeric()->minValue(0.01)->required(),
                        Select::make('account_id')->label('退款账户')->options(fn (): array => $this->accountOptions())->required(),
                        DatePicker::make('occurred_on')->label('退款日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d')->required(),
                        Textarea::make('description')->label('说明')->maxLength(1000),
                    ])
                    ->action(function (array $data, Refund $record, BookkeepingRefundService $service): void {
                        $service->update($this->context(), [
                            'ledger_id' => $this->ledger()->public_id,
                            'event_id' => (string) Str::ulid(),
                            'refund_id' => $record->public_id,
                            'revision' => $record->revision,
                            ...$data,
                        ]);

                        Notification::make()->title('退款记录已更新')->success()->send();
                    }),
                Action::make('voidRefund')
                    ->label('撤销退款')
                    ->color('danger')
                    ->visible(fn (Refund $record): bool => $this->canWrite() && $record->voided_at === null)
                    ->requiresConfirmation()
                    ->action(function (Refund $record, BookkeepingRefundService $service): void {
                        $service->void($this->context(), [
                            'ledger_id' => $this->ledger()->public_id,
                            'event_id' => (string) Str::ulid(),
                            'refund_id' => $record->public_id,
                            'revision' => $record->revision,
                        ]);

                        Notification::make()->title('退款记录已撤销')->success()->send();
                    }),
            ]);
    }

    /** @return array<int, string> */
    private function accountOptions(): array
    {
        return Account::query()
            ->where('ledger_id', $this->ledger()->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->all();
    }

    private function context(): AccessContext
    {
        return app(AccessResolver::class)->resolve(auth()->user(), 'zhendianpu');
    }

    private function canWrite(): bool
    {
        return app(BookkeepingService::class)->canWrite($this->context()) && $this->ledger()->status === 'active';
    }
}
