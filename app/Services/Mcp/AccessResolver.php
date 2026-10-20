<?php

namespace App\Services\Mcp;

use App\Data\Mcp\AccessContext;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccessResolver
{
    public function resolve(User $user, string $moduleKey): AccessContext
    {
        $configuration = config('mcp_servers.'.$moduleKey);
        abort_unless($configuration && $configuration['enabled'], 403, 'MODULE_DISABLED');
        $server = McpServer::query()->where('module_key', $moduleKey)->where('enabled', true)->first();
        abort_unless($server, 403, 'MODULE_DISABLED');
        $workspaces = DB::table('mcp_server_access as access')
            ->join('workspaces', 'workspaces.id', '=', 'access.workspace_id')
            ->join('workspace_members as members', function (JoinClause $join) use ($user): void {
                $join->on('members.workspace_id', '=', 'access.workspace_id')->where('members.user_id', $user->id);
            })
            ->where('access.user_id', $user->id)->where('access.server_id', $server->id)
            ->where('workspaces.enabled', true)
            ->whereIn('access.role', ['reader', 'writer', 'manager'])
            ->whereIn('members.role', ['reader', 'writer', 'manager'])
            ->orderBy('workspaces.id')
            ->get(['workspaces.id', 'workspaces.public_id', 'workspaces.name', 'access.role'])
            ->map(fn (object $workspace): array => (array) $workspace)->all();
        abort_if($workspaces === [], 403, 'FORBIDDEN');

        return new AccessContext($user, $server, $workspaces, (string) Str::ulid());
    }
}
