<?php

namespace App\Http\Middleware;

use App\Services\Mcp\AccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureMcpAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        abort_unless($request->user()?->tokenCan('mcp:use'), 403, 'FORBIDDEN');
        $context = app(AccessResolver::class)->resolve($request->user(), $moduleKey);
        $request->attributes->set('mcp.context', $context);
        $response = $next($request);
        DB::table('audit_logs')->insert([
            'request_id' => $context->requestId,
            'user_id' => $context->user->id,
            'server_id' => $context->server->id,
            'workspace_id' => count($context->workspaces) === 1 ? $context->workspaces[0]['id'] : null,
            'scope' => json_encode(['workspace_ids' => array_column($context->workspaces, 'public_id')], JSON_THROW_ON_ERROR),
            'module_key' => $moduleKey,
            'action' => 'mcp.request',
            'after' => json_encode(['status' => $response->getStatusCode()], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $response->headers->set('X-Request-Id', $context->requestId);

        return $response;
    }
}
