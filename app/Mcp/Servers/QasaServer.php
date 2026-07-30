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

#[Name('Qasa Server')]
#[Version('0.0.1')]
#[Instructions(<<<'MARKDOWN'
    Qasa is invoicing and bookkeeping software for freelancers and small
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
