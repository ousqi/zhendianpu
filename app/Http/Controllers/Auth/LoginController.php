<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['username' => '用户名或密码不正确。']);
        }
        $request->session()->regenerate();

        return redirect()->intended(Filament::getDefaultPanel()->getUrl() ?? url(Filament::getDefaultPanel()->getPath()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function password(Request $request): RedirectResponse
    {
        $input = $request->validate(['current_password' => ['required', 'current_password:web'], 'password' => ['required', 'string', 'min:7', 'max:255', 'confirmed']]);
        $request->user()->update(['password' => $input['password']]);
        $request->session()->regenerate();

        return redirect()->to(Filament::getDefaultPanel()->getUrl() ?? url(Filament::getDefaultPanel()->getPath()))->with('status', '密码已更新。');
    }
}
