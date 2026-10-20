<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\SystemStatus\GetStatus;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('平台状态')]
#[Version('0.1.0')]
#[Instructions('仅查询当前平台连接状态及北京时间。不提供系统命令、文件读取或业务数据。')]
class SystemStatusServer extends Server
{
    protected array $tools = [
        GetStatus::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
