<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Account;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingAccountService;
use App\Services\Bookkeeping\BookkeepingService;
use App\Services\Bookkeeping\LedgerService;
use App\Services\Mcp\AccessResolver;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class BookkeepingAccounts extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '账户设置';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.bookkeeping-accounts';

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

    public function table(Table $table): Table
    {
        return $table->query(app(BookkeepingAccountService::class)->accountsWithBalances($this->ledger()))->defaultSort('name')->columns([
            TextColumn::make('name')->label('名称')->sortable(), TextColumn::make('type')->label('类型')->sortable(), TextColumn::make('opening_balance')->label('期初余额')->money($this->ledger()->currency)->sortable()->visibleFrom('md'), TextColumn::make('balance')->label('当前余额')->money($this->ledger()->currency)->sortable(),
            IconColumn::make('is_default')->label('默认')->boolean()->visibleFrom('md'), IconColumn::make('is_active')->label('启用')->boolean()->visibleFrom('md'),
        ])->headerActions([$this->accountAction()])->recordActions([$this->accountAction('editAccount', '编辑'),
            Action::make('setDefault')->label('设为默认')->visible(fn (Account $record): bool => $this->canWrite() && ! $record->is_default)->action(function (Account $record, BookkeepingAccountService $service): void {
                $service->setDefault($this->context(), $this->ledger(), $record);
                Notification::make()->title('默认账户已更新')->success()->send();
            }),
            Action::make('deleteAccount')->label('删除')->color('danger')->requiresConfirmation()->visible(fn (): bool => $this->canWrite())->action(function (Account $record, BookkeepingAccountService $service): void {
                $service->delete($this->context(), $this->ledger(), $record);
                Notification::make()->title('账户已删除')->success()->send();
            }),
        ]);
    }

    private function accountAction(string $name = 'addAccount', string $label = '新增账户'): Action
    {
        return Action::make($name)->label($label)->visible(fn (): bool => $this->canWrite())->schema([
            TextInput::make('name')->label('名称')->required()->maxLength(255), Select::make('type')->label('类型')->options(['wechat' => '微信', 'alipay' => '支付宝', 'bank_card' => '银行卡', 'cash' => '现金', 'other' => '其他'])->required(),
            TextInput::make('opening_balance')->label('期初余额')->helperText('不计入收入或支出，用于录入该账户已有余额。')->numeric()->default(0)->required(),
            Select::make('is_active')->label('状态')->options([true => '启用', false => '停用'])->required(),
        ])->fillForm(fn (?Account $record): array => $record?->only(['name', 'type', 'opening_balance', 'is_active']) ?? ['opening_balance' => 0, 'is_active' => true])->action(function (array $data, ?Account $record, BookkeepingAccountService $service): void {
            $record ? $service->update($this->context(), $this->ledger(), $record, $data) : $service->create($this->context(), $this->ledger(), $data);
            Notification::make()->title('账户已保存')->success()->send();
        });
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
