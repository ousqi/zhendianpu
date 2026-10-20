<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Validation\ValidationException;

class UsernameLogin extends Login
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('username')
            ->label('用户名')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus();
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.username' => '用户名或密码不正确。',
        ]);
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'username' => $data['username'],
            'password' => $data['password'],
        ];
    }
}
