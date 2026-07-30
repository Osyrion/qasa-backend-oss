<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;

/**
 * Phase 4 for the core tables (docs/plans/POSTGRES_RLS_PLAN.md).
 *
 * Phases 2 and 3 covered the documents and their line items. What was left is
 * everything else an account owns: its bank accounts, its payment orders, its
 * recurring templates, its VAT catalog, its inbox, its BYOK credentials, its
 * activity log. Each of those is as much tenant data as an invoice is, and
 * until now the only thing standing between them and another account was the
 * Eloquent global scope that 105 call sites already opt out of.
 *
 * The plan's list was wrong in two places, both found by asking the database
 * rather than reading the code:
 *
 * - vat_rates.user_id is NOT NULL, not nullable. It carries no shared system
 *   rows, so it is an ordinary owned table. exchange_rates is the one that
 *   genuinely mixes shared rows with per-account overrides.
 *
 * payment_orders and payment_order_items are not here: the Banking module
 * owns that schema, so their policies live in its own migration.
 *
 * order_attachments and order_notes carry a user_id that is the *author*, not
 * the owner, so they go through orders like any other child table — a note
 * written by a team member belongs to the account, not to them.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const OWNED = [
        'activity_log',
        'ai_credentials',
        'bank_accounts',
        'contribution_payments',
        'idempotency_keys',
        'invoice_inbox_items',
        'recurring_invoice_templates',
        'vat_rates',
    ];

    /**
     * @var array<string, array{0: string, 1: string}> table => [parent, foreign key]
     */
    private const VIA_PARENT = [
        'order_attachments' => ['orders', 'order_id'],
        'order_notes' => ['orders', 'order_id'],
        'recurring_invoice_template_items' => ['recurring_invoice_templates', 'template_id'],
    ];

    /**
     * @var list<string>
     */
    private const SHARED = [
        'exchange_rates',
    ];

    public function up(): void
    {
        foreach (self::OWNED as $table) {
            TenantPolicy::own($table);
        }

        foreach (self::VIA_PARENT as $table => [$parent, $foreignKey]) {
            TenantPolicy::viaParent($table, $parent, $foreignKey);
        }

        foreach (self::SHARED as $table) {
            TenantPolicy::ownOrShared($table);
        }
    }

    public function down(): void
    {
        foreach ([...self::OWNED, ...array_keys(self::VIA_PARENT), ...self::SHARED] as $table) {
            TenantPolicy::drop($table);
        }
    }
};
