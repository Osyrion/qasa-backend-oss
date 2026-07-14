<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. The core edition has nothing to
     * seed; edition modules contribute seeders via config('qasa.seeders').
     */
    public function run(): void
    {
        /** @var list<class-string<Seeder>> $seeders */
        $seeders = config('qasa.seeders', []);

        $this->call($seeders);
    }
}
