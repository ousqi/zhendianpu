<?php

namespace App\Mcp\Tools\SystemStatus;

use App\Data\Mcp\AccessContext;
use App\Mcp\Tools\ReadTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_status')]
#[Description('返回本服务状态、北京时间及请求编号；不访问臻点铺业务数据。')]
#[IsReadOnly]
#[IsIdempotent]
class GetStatus extends ReadTool
{
    /**
     * Handle the tool request.
     */
    protected function read(AccessContext $context, Request $request): array
    {
        return ['ok' => true, 'module' => 'system_status', 'version' => config('mcp_servers.system_status.version'), 'status' => 'available', 'server_time' => now('Asia/Shanghai')->toIso8601String(), 'request_id' => $context->requestId];
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
