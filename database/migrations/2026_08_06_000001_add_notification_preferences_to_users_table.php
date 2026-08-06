<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One boolean per NotificationCategory — gates only the in-app `database`
 * channel (InAppNotification::via()), never mail or the automation triggers
 * that decide whether an action runs at all (overdue_digest_enabled,
 * auto_remind_enabled). Lives on users, not a JSON blob, to match every
 * other per-account toggle in this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('notify_invoice_enabled')->default(true)->after('ai_extraction_enabled');
            $table->boolean('notify_quote_enabled')->default(true)->after('notify_invoice_enabled');
            $table->boolean('notify_tax_enabled')->default(true)->after('notify_quote_enabled');
            $table->boolean('notify_billing_enabled')->default(true)->after('notify_tax_enabled');
            $table->boolean('notify_banking_enabled')->default(true)->after('notify_billing_enabled');
            $table->boolean('notify_system_enabled')->default(true)->after('notify_banking_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'notify_invoice_enabled',
                'notify_quote_enabled',
                'notify_tax_enabled',
                'notify_billing_enabled',
                'notify_banking_enabled',
                'notify_system_enabled',
            ]);
        });
    }
};
