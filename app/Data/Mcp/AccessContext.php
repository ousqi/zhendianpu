<?php

namespace App\Data\Mcp;

use App\Models\McpServer;
use App\Models\User;

class AccessContext
{
    /** @param array<int, array{id: int, public_id: string, name: string, role: string}> $workspaces */
    public function __construct(
        public readonly User $user,
        public readonly McpServer $server,
        public readonly array $workspaces,
        public readonly string $requestId,
    ) {}

    /** @return list<int> */
    public function workspaceIds(): array
    {
        return array_column($this->workspaces, 'id');
    }
}
