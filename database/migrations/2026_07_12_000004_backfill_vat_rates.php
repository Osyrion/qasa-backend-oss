<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users')->whereNull('deleted_at')->get(['id', 'country']);

        foreach ($users as $user) {
            $country = strtoupper((string) ($user->country ?: 'SK'));
            $rates = config("taxation.{$country}.vat_rates", config('taxation.SK.vat_rates', []));
            $defaultRate = config("taxation.{$country}.default_vat_rate", config('taxation.SK.default_vat_rate'));

            foreach ($rates as $rate) {
                DB::table('vat_rates')->insert([
                    'id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'code' => sprintf('%s-%s', $country, (string) $rate),
                    'country' => $country,
                    'rate' => $rate,
                    'label' => null,
                    'is_default' => (float) $rate === (float) $defaultRate,
                    'valid_from' => null,
                    'valid_to' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('vat_rates')->delete();
    }
};
