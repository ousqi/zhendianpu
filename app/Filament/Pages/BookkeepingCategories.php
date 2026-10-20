<?php

namespace App\Filament\Pages;

use App\Data\Mcp\AccessContext;
use App\Models\Bookkeeping\Category;
use App\Models\Bookkeeping\Ledger;
use App\Models\User;
use App\Services\Bookkeeping\BookkeepingCategoryService;
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

class BookkeepingCategories extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = '分类设置';

    protected static string|UnitEnum|null $navigationGroup = '记账';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.business-table';

    public string $activeDirection = 'expense';

    private function ledger(): Ledger
    {
        $user = auth()->user();

        return app(LedgerService::class)->current($user instanceof User ? $user : null);
    }

    public function updatedActiveDirection(): void
    {
        $this->resetTable();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BookkeepingService::class)->canAccess($user);
    }

    public function table(Table $table): Table
    {
        return $table->query(Category::query()->where('ledger_id', $this->ledger()->id)->where('direction', $this->activeDirection))->defaultSort('sort_order')->columns([
            TextColumn::make('direction')->label('方向')->formatStateUsing(fn (string $state): string => $state === 'income' ? '收入' : '支出'),
            TextColumn::make('name')->label('名称')->sortable(), TextColumn::make('parent.name')->label('父分类')->placeholder('一级分类'), TextColumn::make('sort_order')->label('排序')->sortable(), IconColumn::make('is_active')->label('启用')->boolean(),
        ])->headerActions([$this->categoryAction()])->recordActions([
            $this->categoryAction('editCategory', '编辑'),
            Action::make('toggleCategory')->label(fn (Category $record): string => $record->is_active ? '停用' : '启用')->visible(fn (): bool => $this->canWrite())->action(function (Category $record, BookkeepingCategoryService $service): void {
                $service->toggle($this->context(), $this->ledger(), $record);
                Notification::make()->title('分类状态已更新')->success()->send();
            }),
        ]);
    }

    private function categoryAction(string $name = 'addCategory', string $label = '新增分类'): Action
    {
        return Action::make($name)->label($label)->visible(fn (): bool => $this->canWrite())->schema([
            TextInput::make('name')->label('名称')->required()->maxLength(255),
            Select::make('direction')->label('方向')->options(['income' => '收入', 'expense' => '支出'])->required()->disabled(fn (?Category $record): bool => $record?->entries()->exists() ?? false),
            Select::make('parent_id')->label('父分类')->options(fn (): array => Category::query()->where('ledger_id', $this->ledger()->id)->whereNull('parent_id')->orderBy('sort_order')->pluck('name', 'id')->all())->searchable(), TextInput::make('sort_order')->label('排序')->numeric()->default(0)->required(),
        ])->fillForm(fn (?Category $record): array => $record?->only(['name', 'direction', 'parent_id', 'sort_order']) ?? ['sort_order' => 0])
            ->action(function (array $data, ?Category $record, BookkeepingCategoryService $service): void {
                if ($record) {
                    $service->update($this->context(), $this->ledger(), $record, $data);
                } else {
                    $service->create($this->context(), $this->ledger(), $data);
                }
                Notification::make()->title('分类已保存')->success()->send();
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
