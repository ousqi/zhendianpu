<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use App\Services\Mcp\AccessResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class BookkeepingLedgers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '账本管理';

    protected static ?string $navigationLabel = '账本管理';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.bookkeeping-ledgers';

    public ?string $currentLedgerId = null;

    public function mount(): void
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $this->currentLedgerId = app(LedgerService::class)->current($user)->public_id;
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BookkeepingService::class)->canAccess($user);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Ledger::query())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('名称')->searchable()->sortable(),
                TextColumn::make('type')->label('类型')->badge()->formatStateUsing(fn (string $state): string => match ($state) {
                    'business' => '经营',
                    'personal' => '个人',
                    default => '其他',
                }),
                TextColumn::make('currency')->label('币种')->sortable(),
                TextColumn::make('timezone')->label('时区')->visibleFrom('md'),
                TextColumn::make('status')->label('状态')->badge()->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')->formatStateUsing(fn (string $state): string => $state === 'active' ? '启用' : '已归档'),
                TextColumn::make('current_ledger')->label('当前使用')->badge()->state(fn (Ledger $record): string => $record->public_id === $this->currentLedgerId ? '当前账本' : '')->color('primary')->placeholder('—'),
                TextColumn::make('updated_at')->label('更新时间')->dateTime('Y-m-d H:i')->sortable()->visibleFrom('md'),
            ])
            ->headerActions([$this->createLedgerAction()])
            ->recordActions([
                Action::make('selectCurrentLedger')
                    ->label('切换到此账本')
                    ->color('primary')
                    ->visible(fn (Ledger $record): bool => $record->status === 'active' && $record->public_id !== $this->currentLedgerId)
                    ->action(function (Ledger $record, LedgerService $service): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        $service->selectCurrent($user, $record);
                        $this->currentLedgerId = $record->public_id;
                        $this->resetTable();
                        Notification::make()->title('已切换到此账本')->success()->send();
                    }),
                $this->editLedgerAction(),
                Action::make('archiveLedger')
                    ->label('归档')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('归档后账本及其数据将仅可查看，不能恢复写入。')
                    ->visible(fn (Ledger $record): bool => $this->canWrite() && $record->status === 'active')
                    ->action(function (Ledger $record, LedgerService $service): void {
                        $this->assertCanWriteActive($record);

                        $service->archive($record);
                        if ($record->public_id === $this->currentLedgerId) {
                            $user = auth()->user();
                            $this->currentLedgerId = $user instanceof User ? $service->current($user)->public_id : null;
                        }
                        Notification::make()->title('账本已归档')->success()->send();
                    }),
            ]);
    }

    private function createLedgerAction(): Action
    {
        return Action::make('createLedger')
            ->label('新增账本')
            ->visible(fn (): bool => $this->canWrite())
            ->schema([
                TextInput::make('name')->label('名称')->required()->maxLength(255),
                Select::make('type')->label('类型')->options(['business' => '经营', 'personal' => '个人', 'other' => '其他'])->required(),
                TextInput::make('currency')->label('币种')->required()->length(3)->default('CNY'),
                TextInput::make('timezone')->label('时区')->required()->maxLength(64)->default('Asia/Shanghai'),
            ])
            ->action(function (array $data, LedgerService $service): void {
                $this->assertCanWrite();

                $service->create($data);
                Notification::make()->title('账本已创建')->success()->send();
            });
    }

    private function editLedgerAction(): Action
    {
        return Action::make('editLedger')
            ->label('编辑')
            ->visible(fn (Ledger $record): bool => $this->canWrite() && $record->status === 'active')
            ->schema([
                TextInput::make('name')->label('名称')->required()->maxLength(255),
                TextInput::make('currency')->label('币种')->required()->length(3),
                TextInput::make('timezone')->label('时区')->required()->maxLength(64),
            ])
            ->fillForm(fn (Ledger $record): array => $record->only(['name', 'currency', 'timezone']))
            ->action(function (array $data, Ledger $record, LedgerService $service): void {
                $this->assertCanWriteActive($record);

                $service->update($record, $data);
                Notification::make()->title('账本已保存')->success()->send();
            });
    }

    private function canWrite(): bool
    {
        return app(BookkeepingService::class)->canWrite($this->context());
    }

    private function assertCanWrite(): void
    {
        abort_unless($this->canWrite(), 403, 'FORBIDDEN');
    }

    private function assertCanWriteActive(Ledger $ledger): void
    {
        $this->assertCanWrite();
        abort_unless($ledger->status === 'active', 403, 'LEDGER_ARCHIVED');
    }

    private function context(): AccessContext
    {
        return app(AccessResolver::class)->resolve(auth()->user(), 'zhendianpu');
    }
}
