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

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.business-table';

    public string $activeDirection = 'expense';

    public ?int $selectedTopLevelCategoryId = null;

    private function ledger(): Ledger
    {
        $user = auth()->user();

        return app(LedgerService::class)->current($user instanceof User ? $user : null);
    }

    public function updatedActiveDirection(): void
    {
        $this->selectedTopLevelCategoryId = null;
        $this->resetTable();
    }

    public function showSubcategories(Category $category): void
    {
        $category = Category::query()->where('ledger_id', $this->ledger()->id)->where('direction', $this->activeDirection)->whereNull('parent_id')->whereKey($category->id)->first();
        if (! $category) {
            return;
        }

        $this->selectedTopLevelCategoryId = $category->id;
        $this->resetTable();
    }

    public function clearSelectedTopLevelCategory(): void
    {
        $this->selectedTopLevelCategoryId = null;
        $this->resetTable();
    }

    public function selectedTopLevelCategory(): ?Category
    {
        if ($this->selectedTopLevelCategoryId === null) {
            return null;
        }

        $category = Category::query()->where('ledger_id', $this->ledger()->id)->where('direction', $this->activeDirection)->whereNull('parent_id')->whereKey($this->selectedTopLevelCategoryId)->first();
        if (! $category) {
            $this->selectedTopLevelCategoryId = null;
        }

        return $category;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(BookkeepingService::class)->canAccess($user);
    }

    public function table(Table $table): Table
    {
        $selectedCategory = $this->selectedTopLevelCategory();

        return $table->query(Category::query()->where('ledger_id', $this->ledger()->id)->where('direction', $this->activeDirection)->with('parent:id,name,direction')->when($selectedCategory, fn ($query) => $query->where('parent_id', $selectedCategory->id), fn ($query) => $query->whereNull('parent_id')))->defaultSort('sort_order')->columns([
            TextColumn::make('direction')->label('方向')->formatStateUsing(fn (string $state): string => $state === 'income' ? '收入' : '支出'),
            TextColumn::make('name')->label('名称')->formatStateUsing(fn (string $state, Category $record): string => $record->displayName())->searchable()->sortable(), TextColumn::make('parent.name')->label('父分类')->formatStateUsing(fn (string $state, Category $record): string => $record->parent?->displayName() ?? '一级分类')->placeholder('一级分类')->visible(fn (): bool => $selectedCategory !== null), TextColumn::make('sort_order')->label('排序')->sortable(), IconColumn::make('is_active')->label('启用')->boolean(),
        ])->headerActions([$this->categoryAction()])->recordActions([
            $this->categoryAction('editCategory', '编辑'),
            Action::make('viewSubcategories')->label('查看子分类')->visible(fn (Category $record): bool => $record->parent_id === null)->action(fn (Category $record) => $this->showSubcategories($record)),
            Action::make('toggleCategory')->label(fn (Category $record): string => $record->is_active ? '停用' : '启用')->visible(fn (): bool => $this->canWrite())->action(function (Category $record, BookkeepingCategoryService $service): void {
                $service->toggle($this->context(), $this->ledger(), $record);
                Notification::make()->title('分类状态已更新')->success()->send();
            }),
            Action::make('deleteCategory')->label('删除')->color('danger')->requiresConfirmation()->visible(fn (): bool => $this->canWrite())->action(function (Category $record, BookkeepingCategoryService $service): void {
                $service->delete($this->context(), $this->ledger(), $record);
                Notification::make()->title('分类已删除')->success()->send();
            }),
        ]);
    }

    private function categoryAction(string $name = 'addCategory', string $label = '新增分类'): Action
    {
        return Action::make($name)->label($label)->visible(fn (): bool => $this->canWrite())->schema([
            TextInput::make('name')->label('名称')->required()->maxLength(255),
            Select::make('direction')->label('方向')->options(['income' => '收入', 'expense' => '支出'])->required()->disabled(fn (?Category $record): bool => $record?->entries()->exists() ?? false),
            Select::make('parent_id')->label('父分类')->options(fn (): array => Category::query()->where('ledger_id', $this->ledger()->id)->whereNull('parent_id')->orderBy('sort_order')->get(['id', 'name', 'direction'])->mapWithKeys(fn (Category $category): array => [$category->id => $category->displayName()])->all())->searchable(), TextInput::make('sort_order')->label('排序')->numeric()->default(0)->required(),
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
