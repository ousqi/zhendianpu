<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#126b50">
    <title>@yield('title', '登录 · '.config('app.shop_name').'管理台')</title>
    @include('layouts.management-styles')
</head>
<body class="management-body">
<div class="guest-shell">
    <header class="guest-topbar"><a class="brand" href="/admin"><span class="brand-mark">点</span><span><span class="brand-name">{{ config('app.shop_name') }}</span><span class="brand-caption">记账工作台</span></span></a><span>简单记账 · 有据可查</span></header>
    <main>
        @if(session('status'))<div class="notice" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</div>
</body>
</html>
