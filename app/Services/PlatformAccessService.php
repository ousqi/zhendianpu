<?php

namespace App\Services;

use App\Models\McpServer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class PlatformAccessService
{
    public function provision(User $user): void
    {
        $workspace = Workspace::firstOrCreate(['name' => '默认工作区']);
        DB::table('workspace_members')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'manager',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (config('mcp_servers') as $key => $module) {
            $server = McpServer::firstOrCreate(['module_key' => $key], ['name' => $module['name']]);
            DB::table('mcp_server_access')->insertOrIgnore([
                'workspace_id' => $workspace->id,
                'server_id' => $server->id,
                'user_id' => $user->id,
                'role' => 'manager',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
