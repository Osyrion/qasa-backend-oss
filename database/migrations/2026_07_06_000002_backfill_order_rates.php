<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Epoch floor: CSV-imported time entries may predate orders.created_at,
     * so the migrated order rate must cover all existing entries.
     */
    private const string EPOCH = '1970-01-01 00:00:00';

    public function up(): void
    {
        $orders = DB::table('orders')
            ->whereNotNull('rate')
            ->whereNull('deleted_at')
            ->get(['id', 'user_id', 'rate', 'currency']);

        foreach ($orders as $order) {
            DB::table('rates')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $order->user_id,
                'level' => 'order',
                'client_id' => null,
                'order_id' => $order->id,
                'rate' => $order->rate,
                'currency' => $order->currency,
                'valid_from' => self::EPOCH,
                'note' => 'Migrované z orders.rate',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('rates')
            ->where('level', 'order')
            ->where('valid_from', self::EPOCH)
            ->delete();
    }
};
