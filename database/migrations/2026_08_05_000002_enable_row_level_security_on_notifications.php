<?php

declare(strict_types=1);

use App\Modules\Shared\Support\TenantPolicy;
use Illuminate\Database\Migrations\Migration;

/**
 * Keyed on user_id (the account owner), not notifiable_id (the recipient):
 * the policy answers "which account may touch this row", and per-recipient
 * visibility inside an account is the NotificationPolicy's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantPolicy::own('notifications');
    }

    public function down(): void
    {
        TenantPolicy::drop('notifications');
    }
};
