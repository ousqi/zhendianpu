@extends('layouts.platform')
@section('title', '授权连接 · '.config('app.shop_name').'记账平台')
@section('content')
<section class="card narrow">
    <span class="badge"><x-management-icon name="shield" />账号关联与授权</span>
    <h1>授权 {{ $client->name }}</h1>
    <p>当前登录账号：<strong>{{ $user->email }}</strong></p>
    <p class="muted">此连接只能访问你获准使用的服务和数据，具体操作仍受你的角色和业务权限限制。</p>
    <ul>@foreach($scopes as $scope)<li>{{ $scope->id === 'mcp:use' ? '使用已授权的服务' : $scope->description }}</li>@endforeach</ul>
    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="state" value="{{ $request->input('state') }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">允许连接</button>
        </form>
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->input('state') }}">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="secondary">取消</button>
        </form>
    </div>
</section>
@endsection
