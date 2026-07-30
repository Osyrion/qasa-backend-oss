<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Modules\Auth\Domain\Models\User;
use Illuminate\Auth\AuthenticationException;
use Laravel\Mcp\Request;

/**
 * MCP tools have no HTTP route middleware pipeline of their own — every
 * tool call goes through the same JSON-RPC endpoint — so each tool resolves
 * the acting user here and authorizes via Gate::authorize(), same as the
 * `authorizeResource()` / `permission:*` middleware the equivalent HTTP
 * controllers and routes use. Laravel\Mcp\Server\Methods\CallTool catches
 * both AuthenticationException and AuthorizationException and turns them
 * into a normal tool error response, so callers here just throw.
 */
trait AuthorizesTool
{
    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException(__('mcp.unauthenticated'));
        }

        return $user;
    }
}
