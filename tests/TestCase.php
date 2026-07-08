<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run the edition's seeders after RefreshDatabase migrates — SaaS
     * policies depend on the spatie role/permission catalog existing.
     */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;
}
