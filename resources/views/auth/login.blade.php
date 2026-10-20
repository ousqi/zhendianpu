@extends('layouts.platform')
@section('title', '登录 · '.config('app.shop_name').'记账平台')
@section('content')
<div class="login-layout">
    <section class="login-story">
        <span class="eyebrow">小店经营，账目清晰</span>
        <h1>用心做好每一份。<br><em>清楚记下每一笔。</em></h1>
        <p>把已经发生的收入和支出记清楚，按期间查看收支与结余。简单记账，随时可查。</p>
        <div class="welcome-art" aria-hidden="true"><div class="rice-mark"><span>点</span></div><span class="art-label">把小店的日常，打理好。</span></div>
    </section>
    <section class="card login-card">
        <h2>登录记账平台</h2>
        <p class="muted">欢迎回来。使用你的账号，进入{{ config('app.shop_name') }}记账工作台。</p>
        <form method="POST" action="{{ route('login.store') }}">
            @csrf
            <label for="username">用户名</label><input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" placeholder="输入用户名" required autofocus>
            <label for="password">登录密码</label><input id="password" name="password" type="password" autocomplete="current-password" placeholder="输入登录密码" required>
            <button type="submit">登录工作台<x-management-icon name="arrow" /></button>
        </form>
        <p class="login-footnote">连接 ChatGPT 时，也使用此账号完成授权。</p>
    </section>
</div>
@endsection
