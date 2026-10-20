<?php

use App\Mcp\Servers\SystemStatusServer;
use App\Mcp\Servers\ZhendianpuServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::middleware('throttle:oauth-register')->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp/zhendianpu', ZhendianpuServer::class)
    ->middleware(['auth:api', 'mcp.access:zhendianpu', 'throttle:mcp'])
    ->name('mcp.zhendianpu');

Mcp::web('/mcp/system-status', SystemStatusServer::class)
    ->middleware(['auth:api', 'mcp.access:system_status', 'throttle:mcp'])
    ->name('mcp.system_status');
