<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListInvoicesTool;
use App\Mcp\Tools\SearchClientsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Tool;
use OpenApi\Attributes as OA;

#[Name('Zoad Server')]
#[Version('0.0.1')]
#[Instructions(<<<'MARKDOWN'
    Zoad is invoicing and bookkeeping software for freelancers and small
    businesses in Slovakia/Czechia. These tools operate on the data of the
    single authenticated account making the request — there is no way to
    read or act on another account's data.

    All tools here are read-only. Amounts, dates, invoice numbers, and
    client details must always come from a tool call — never invent or
    estimate them. Money values are plain numbers in the invoice's own
    currency (CZK/EUR/USD); a report spanning multiple currencies returns
    one series per currency rather than converting between them.
    MARKDOWN
)]
#[OA\Tag(
    name: 'MCP',
    description: 'Model Context Protocol server — JSON-RPC 2.0 over the Streamable HTTP transport, not a REST resource'
)]
#[OA\Post(
    path: '/api/v1/mcp/qasa',
    summary: 'MCP JSON-RPC endpoint',
    description: <<<'MARKDOWN'
        Single Streamable HTTP entry point for the Zoad MCP server. The body is a
        JSON-RPC 2.0 envelope, so the operation performed is `method`, not the HTTP
        verb — call `initialize` first, then `tools/list` to discover what this
        deployment exposes. The tool set differs between editions, so never assume a
        tool exists without listing it.

        Every tool is read-only and runs as the account the Sanctum token belongs to,
        under the same global scope as the REST endpoints; there is no way to reach
        another account's data through it.

        A request carrying an `id` is answered with `200` and a JSON-RPC result. A
        notification (no `id`) has no reply and is answered with `202`. Set `Accept`
        to `text/event-stream` to receive a streamed reply instead of a single JSON
        body. Protocol semantics beyond this are the MCP specification's, not ours:
        https://modelcontextprotocol.io/specification
        MARKDOWN,
    security: [['sanctum' => []]],
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['jsonrpc', 'method'],
            properties: [
                new OA\Property(property: 'jsonrpc', type: 'string', enum: ['2.0'], example: '2.0'),
                new OA\Property(
                    property: 'id',
                    description: 'Request correlation id. Omit it to send a notification, which is answered with 202 and no body.',
                    oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'integer')],
                    nullable: true,
                ),
                new OA\Property(property: 'method', type: 'string', example: 'tools/list'),
                new OA\Property(property: 'params', type: 'object', nullable: true),
            ],
            type: 'object',
        )
    ),
    tags: ['MCP'],
    parameters: [
        new OA\Parameter(
            name: 'MCP-Session-Id',
            description: 'Session id returned by a previous response. Omit it on `initialize`.',
            in: 'header',
            required: false,
            schema: new OA\Schema(type: 'string')
        ),
    ],
    responses: [
        new OA\Response(
            response: 200,
            description: 'JSON-RPC result',
            headers: [
                new OA\Header(header: 'MCP-Session-Id', description: 'Session id to send with subsequent calls', schema: new OA\Schema(type: 'string')),
            ],
            content: [
                new OA\MediaType(
                    mediaType: 'application/json',
                    schema: new OA\Schema(
                        properties: [
                            new OA\Property(property: 'jsonrpc', type: 'string', example: '2.0'),
                            new OA\Property(property: 'id', oneOf: [new OA\Schema(type: 'string'), new OA\Schema(type: 'integer')]),
                            new OA\Property(property: 'result', type: 'object'),
                            new OA\Property(
                                property: 'error',
                                description: 'Present instead of `result` when the call failed. Protocol-level failures are reported here with HTTP 200.',
                                properties: [
                                    new OA\Property(property: 'code', type: 'integer', example: -32601),
                                    new OA\Property(property: 'message', type: 'string', example: 'Method not found'),
                                ],
                                type: 'object',
                            ),
                        ],
                        type: 'object',
                    )
                ),
                new OA\MediaType(
                    mediaType: 'text/event-stream',
                    schema: new OA\Schema(type: 'string', description: 'SSE frames, each `data:` line carrying one JSON-RPC message')
                ),
            ]
        ),
        new OA\Response(response: 202, description: 'Notification accepted; no JSON-RPC reply'),
        new OA\Response(response: 401, description: 'Unauthenticated'),
        new OA\Response(response: 429, description: 'Rate limited'),
    ]
)]
#[OA\Get(
    path: '/api/v1/mcp/qasa',
    summary: 'Not supported (server-initiated SSE stream)',
    description: 'The MCP specification lets a client open a server-to-client stream here. This server does not, and always answers 405 with `Allow: POST`.',
    tags: ['MCP'],
    responses: [
        new OA\Response(
            response: 405,
            description: 'Method not allowed',
            headers: [new OA\Header(header: 'Allow', schema: new OA\Schema(type: 'string', example: 'POST'))]
        ),
    ]
)]
#[OA\Delete(
    path: '/api/v1/mcp/qasa',
    summary: 'Not supported (session termination)',
    description: 'The MCP specification lets a client end its session here. This server does not, and always answers 405 with `Allow: POST`.',
    tags: ['MCP'],
    responses: [
        new OA\Response(
            response: 405,
            description: 'Method not allowed',
            headers: [new OA\Header(header: 'Allow', schema: new OA\Schema(type: 'string', example: 'POST'))]
        ),
    ]
)]
class QasaServer extends Server
{
    /**
     * Core tools plus whatever the premium modules registered — a tool that
     * needs a premium module has to ship with it, or the OSS build breaks.
     *
     * @var array<int, class-string<Tool>|Tool>
     */
    protected array $tools = [];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);

        $this->tools = [
            SearchClientsTool::class,
            ListInvoicesTool::class,
            ...array_values((array) config('qasa.mcp.tools', [])),
        ];
    }

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
