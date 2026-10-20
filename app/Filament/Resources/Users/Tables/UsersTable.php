<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('username')->label('用户名')->searchable()->sortable(),
                TextColumn::make('name')->label('姓名')->searchable()->sortable(),
                TextColumn::make('email')->label('联系邮箱')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('created_at')->label('创建时间')->date('Y-m-d')->sortable()->visibleFrom('md'),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }
}
