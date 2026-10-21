<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Entry;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingQueryService;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
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

class BookkeepingEntries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '收支明细';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.bookkeeping-entries';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(BookkeepingService::class)->canAccess($user);
    }

    protected function ledger(): Ledger
    {
        $user = auth()->user();

        return app(LedgerService::class)->current($user instanceof User ? $user : null);
    }

    protected function entries(): Builder
    {
        return Entry::query()->where('ledger_id', $this->ledger()->id)->with(['category', 'account']);
    }

    public function table(Table $table): Table
    {
        return $table->query($this->entries())->defaultSort('occurred_on', 'desc')->columns([
            TextColumn::make('occurred_on')->label('日期')->date('Y-m-d')->sortable(), TextColumn::make('direction')->label('方向')->badge()->formatStateUsing(fn (string $state): string => $state === 'income' ? '收入' : '支出'),
            TextColumn::make('category.name')->label('分类')->formatStateUsing(fn (string $state, Entry $record): string => $record->category->displayName())
                ->icon(fn (Entry $record): ?Heroicon => filled($record->description) ? Heroicon::OutlinedDocumentText : null)
                ->iconPosition(IconPosition::After)
                ->iconColor('info')
                ->tooltip(fn (Entry $record): ?string => $record->description)
                ->action(
                    Action::make('viewDescription')
                        ->visible(fn (Entry $record): bool => filled($record->description))
                        ->modalHeading('收支说明')
                        ->fillForm(fn (Entry $record): array => ['description' => $record->description])
                        ->schema([Textarea::make('description')->label('说明')->rows(8)->disabled()])
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('关闭'),
                ),
            TextColumn::make('account.name')->label('账户')->visibleFrom('md'), TextColumn::make('amount')->label('金额')->money($this->ledger()->currency)->prefix(fn (Entry $entry): string => $entry->direction === 'income' ? '+' : '-'),
        ])->filters([
            Filter::make('occurred_on')->label('日期范围')->schema([
                DatePicker::make('start')->label('开始日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d'),
                DatePicker::make('end')->label('结束日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d'),
            ])->query(function (Builder $query, array $data): Builder {
                return $query
                    ->when($data['start'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_on', '>=', $date))
                    ->when($data['end'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_on', '<=', $date));
            }),
            SelectFilter::make('direction')->label('方向')->options(['income' => '收入', 'expense' => '支出']),
            SelectFilter::make('category_id')->label('分类')->options(function (): array {
                $ledgerId = $this->ledger()->id;

                return [
                    '一级分类' => Category::query()->where('ledger_id', $ledgerId)->whereNull('parent_id')->orderBy('name')->get(['id', 'name', 'direction'])->mapWithKeys(fn (Category $category): array => [$category->id => $category->displayName()])->all(),
                    '二级分类' => Category::query()->where('ledger_id', $ledgerId)->whereNotNull('parent_id')->with('parent:id,name,direction')->orderBy('name')->get()->mapWithKeys(fn (Category $category): array => [$category->id => $category->parent->displayName().' / '.$category->displayName()])->all(),
                ];
            })->searchable()->preload(),
            SelectFilter::make('account_id')->label('账户')->relationship('account', 'name'),
            SelectFilter::make('voided')->label('作废记录')->options(['without' => '不含作废', 'with' => '包含作废', 'only' => '仅作废'])->default('without')
                ->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? 'without') {
                        'with' => $query,
                        'only' => $query->whereNotNull('voided_at'),
                        default => $query->whereNull('voided_at'),
                    };
                }),
            Filter::make('keyword')->label('关键词')->schema([TextInput::make('value')->label('说明关键词')])
                ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $query, string $value): Builder => $query->where('description', 'like', '%'.$value.'%'))),
        ], layout: FiltersLayout::Modal)->toolbarActions([$this->recordAction()])->recordActions([
            $this->recordAction('editEntry', '编辑'),
            Action::make('voidEntry')->label('撤销')->color('danger')->visible(fn (): bool => $this->canWrite())->requiresConfirmation()->action(function (Entry $record, BookkeepingService $service): void {
                $service->void($this->context(), ['ledger_id' => $this->ledger()->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $record->public_id, 'revision' => $record->revision]);
                Notification::make()->title('收支记录已撤销')->success()->send();
            }),
        ]);
    }

    private function recordAction(string $name = 'addEntry', string $label = '记一笔'): Action
    {
        return Action::make($name)->label($label)->visible(fn (): bool => $this->canWrite())->schema([
            Select::make('direction')->label('方向')->options(['income' => '收入', 'expense' => '支出'])->required()->live(),
            TextInput::make('amount')->label('金额')->numeric()->minValue(0.01)->required(),
            Select::make('category_id')->label('分类')->options(fn (Get $get): array => Category::query()->where('ledger_id', $this->ledger()->id)->where('is_active', true)->doesntHave('children')->where('direction', $get('direction'))->orderBy('name')->get(['id', 'name', 'direction'])->mapWithKeys(fn (Category $category): array => [$category->id => $category->displayName()])->all())
                ->searchable()->preload()->searchPrompt('输入分类名称快速选择')->required(),
            Select::make('account_id')->label('账户')->options(fn (): array => Account::query()->where('ledger_id', $this->ledger()->id)->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())->default(fn (): ?int => Account::query()->where('ledger_id', $this->ledger()->id)->where('is_default', true)->value('id')),
            DatePicker::make('occurred_on')->label('日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d')->default(fn (): string => now($this->ledger()->timezone)->toDateString())->required(), Textarea::make('description')->label('说明')->maxLength(1000),
        ])->fillForm(fn (?Entry $record): array => $record ? $record->only(['direction', 'amount', 'category_id', 'account_id', 'occurred_on', 'description']) : [])
            ->extraModalFooterActions(fn (?Entry $record): array => $name === 'editEntry' && $record !== null ? [$this->refundAction()] : [])
            ->action(function (array $data, ?Entry $record, BookkeepingService $service): void {
                if ($record) {
                    $service->update($this->context(), ['ledger_id' => $this->ledger()->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $record->public_id, 'revision' => $record->revision, ...$data], 'admin');
                } else {
                    $service->add($this->context(), ['ledger_id' => $this->ledger()->public_id, 'event_id' => (string) Str::ulid(), 'entries' => [$data]], 'admin');
                }
                Notification::make()->title('收支记录已保存')->success()->send();
            });
    }

    private function refundAction(): Action
    {
        return Action::make('addRefund')
            ->label('新增退款')
            ->color('warning')
            ->visible(fn (Entry $record): bool => $this->canWrite() && $record->voided_at === null)
            ->schema([
                TextInput::make('amount')->label('退款金额')->numeric()->minValue(0.01)->required(),
                Select::make('account_id')->label('退款账户')->options(fn (): array => Account::query()->where('ledger_id', $this->ledger()->id)->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')->all())->required(),
                DatePicker::make('occurred_on')->label('退款日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d')->default(fn (): string => now($this->ledger()->timezone)->toDateString())->required(),
                Textarea::make('description')->label('说明')->maxLength(1000),
            ])
            ->action(function (array $data, Entry $record, BookkeepingRefundService $service): void {
                $service->add($this->context(), ['ledger_id' => $this->ledger()->public_id, 'event_id' => (string) Str::ulid(), 'entry_id' => $record->public_id, ...$data], 'admin');
                Notification::make()->title('退款记录已保存')->success()->send();
            })
            ->cancelParentActions();
    }

    private function context(): AccessContext
    {
        return app(AccessResolver::class)->resolve(auth()->user(), 'zhendianpu');
    }

    /** @return array{income: string, expense: string, balance: string} */
    public function summary(): array
    {
        return app(BookkeepingQueryService::class)->summaryFor($this->ledger(), [
            'start_date' => $this->getTableFilterState('occurred_on')['start'] ?? null,
            'end_date' => $this->getTableFilterState('occurred_on')['end'] ?? null,
            'direction' => $this->getTableFilterState('direction')['value'] ?? null,
            'category_id' => $this->getTableFilterState('category_id')['value'] ?? null,
            'account_id' => $this->getTableFilterState('account_id')['value'] ?? null,
            'keyword' => $this->getTableFilterState('keyword')['value'] ?? null,
        ]);
    }

    private function canWrite(): bool
    {
        return app(BookkeepingService::class)->canWrite($this->context()) && $this->ledger()->status === 'active';
    }
}
