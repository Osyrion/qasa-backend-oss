<?php

declare(strict_types=1);

namespace Tests;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Run the seeders after RefreshDatabase migrates so tests always
     * start from the seeded baseline.
     */
    protected bool $seed = true;

    protected string $seeder = DatabaseSeeder::class;
}
