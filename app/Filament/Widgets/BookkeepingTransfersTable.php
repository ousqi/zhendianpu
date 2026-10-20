<?php

namespace App\Filament\Widgets;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Ledger;
use App\Models\Bookkeeping\Transfer;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\BookkeepingTransferService;
use App\Services\Bookkeeping\LedgerService;
use App\Services\Mcp\AccessResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BookkeepingTransfersTable extends TableWidget
{
    protected static bool $isDiscovered = false;

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BookkeepingService::class)->canAccess($user);
    }

    private function ledger(): Ledger
    {
        $user = auth()->user();

        return app(LedgerService::class)->current($user instanceof User ? $user : null);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('转账记录')
            ->query(fn (): Builder => Transfer::query()->where('ledger_id', $this->ledger()->id)->whereNull('voided_at')->with(['sourceAccount:id,name', 'destinationAccount:id,name']))
            ->defaultSort('occurred_on', 'desc')
            ->columns([
                TextColumn::make('occurred_on')->label('日期')->date('Y-m-d')->sortable(),
                TextColumn::make('sourceAccount.name')->label('转出账户'),
                TextColumn::make('destinationAccount.name')->label('转入账户')
                    ->icon(fn (Transfer $record): ?Heroicon => filled($record->description) ? Heroicon::OutlinedDocumentText : null)
                    ->iconPosition(IconPosition::After)
                    ->iconColor('info')
                    ->tooltip(fn (Transfer $record): ?string => $record->description)
                    ->action(
                        Action::make('viewDescription')
                            ->visible(fn (Transfer $record): bool => filled($record->description))
                            ->modalHeading('转账说明')
                            ->fillForm(fn (Transfer $record): array => ['description' => $record->description])
                            ->schema([Textarea::make('description')->label('说明')->rows(8)->disabled()])
                            ->modalSubmitAction(false)
                            ->modalCancelActionLabel('关闭'),
                    ),
                TextColumn::make('amount')->label('金额')->money('CNY')->sortable(),
            ])
            ->headerActions([
                $this->transferAction(),
            ])
            ->recordActions([
                $this->transferAction('editTransfer', '编辑')->visible(fn (Transfer $record): bool => $this->canWrite() && $record->voided_at === null),
                Action::make('deleteTransfer')
                    ->label('删除')
                    ->color('danger')
                    ->modalHeading('删除转账记录')
                    ->modalDescription('删除将撤销该转账，不会物理删除历史记录；撤销后不再计入账户余额。')
                    ->schema([
                        Textarea::make('void_reason')->label('删除原因')->maxLength(1000)->required(),
                    ])
                    ->requiresConfirmation()
                    ->visible(fn (Transfer $record): bool => $this->canWrite() && $record->voided_at === null)
                    ->action(function (array $data, Transfer $record, BookkeepingTransferService $service): void {
                        $service->void($this->context(), [
                            'ledger_id' => $this->ledger()->public_id,
                            'event_id' => (string) Str::ulid(),
                            'transfer_id' => $record->public_id,
                            'revision' => $record->revision,
                            'void_reason' => $data['void_reason'],
                        ]);

                        Notification::make()->title('转账记录已删除并撤销')->success()->send();
                    }),
            ]);
    }

    private function transferAction(string $name = 'addTransfer', string $label = '转账'): Action
    {
        return Action::make($name)->label($label)->visible(fn (): bool => $this->canWrite())->schema([
            Select::make('source_account_id')->label('转出账户')->options($this->accountOptions())
                ->disableOptionWhen(fn (string $value, Get $get): bool => (int) $value === (int) $get('destination_account_id'))->live()->required(),
            Select::make('destination_account_id')->label('转入账户')->options($this->accountOptions())
                ->disableOptionWhen(fn (string $value, Get $get): bool => (int) $value === (int) $get('source_account_id'))->live()->helperText('不能与转出账户相同。')->required(),
            TextInput::make('amount')->label('金额')->numeric()->minValue(0.01)->required(),
            DatePicker::make('occurred_on')->label('日期')->native(false)->displayFormat('Y-m-d')->format('Y-m-d')->default(now('Asia/Shanghai')->toDateString())->required(),
            Textarea::make('description')->label('说明')->maxLength(1000),
        ])->fillForm(fn (?Transfer $record): array => $record?->only(['source_account_id', 'destination_account_id', 'amount', 'occurred_on', 'description']) ?? [])
            ->action(function (array $data, ?Transfer $record, BookkeepingTransferService $service): void {
                if ($record) {
                    $service->update($this->context(), ['ledger_id' => $this->ledger()->public_id,
                        'event_id' => (string) Str::ulid(), 'transfer_id' => $record->public_id, 'revision' => $record->revision, ...$data]);
                } else {
                    $service->add($this->context(), ['ledger_id' => $this->ledger()->public_id,
                        'event_id' => (string) Str::ulid(), ...$data], 'admin');
                }

                Notification::make()->title('转账已保存')->success()->send();
            });
    }

    /** @return array<int, string> */
    private function accountOptions(): array
    {
        return Account::query()->where('ledger_id', $this->ledger()->id)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    private function canWrite(): bool
    {
        return app(BookkeepingService::class)->canWrite($this->context()) && $this->ledger()->status === 'active';
    }

    private function context(): AccessContext
    {
        return app(AccessResolver::class)->resolve(auth()->user(), 'zhendianpu');
    }
}
