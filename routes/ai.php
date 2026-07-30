<?php

declare(strict_types=1);

use App\Mcp\Servers\QasaServer;
use Laravel\Mcp\Facades\Mcp;

// Web transport, scoped to the calling account via Sanctum — MCP tools read
// auth()->user() the same way HTTP controllers do, so every tool call is
// bound to whoever the token belongs to. There is no local/stdio server:
// that transport has no HTTP request, so auth()->user() is always null and
// none of this account-scoped data would be reachable.
Mcp::web('api/v1/mcp/qasa', QasaServer::class)->middleware(['auth:sanctum', 'throttle:api']);
