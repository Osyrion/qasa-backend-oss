<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use App\Modules\Shared\Domain\Contracts\Actor;
use App\Modules\Shared\Domain\Contracts\ProvidesPlanEntitlements;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
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
    /**
     * Typed as the contracts rather than the edition's User model: the SaaS
     * edition swaps that class through the auth config, and naming it here
     * would flatten onto every tool that uses this trait (deptrac counts a
     * trait's dependencies against its users).
     */
    private function authenticatedUser(Request $request): Actor&ProvidesPlanEntitlements&ProvidesSupplierProfile
    {
        $user = $request->user();

        if (! $user instanceof Actor || ! $user instanceof ProvidesPlanEntitlements || ! $user instanceof ProvidesSupplierProfile) {
            throw new AuthenticationException(__('mcp.unauthenticated'));
        }

        return $user;
    }
}
