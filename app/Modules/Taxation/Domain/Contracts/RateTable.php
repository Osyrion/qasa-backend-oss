<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Contracts;

/**
 * One country's legislated figures (brackets, credits, minimums) for one tax
 * year — the only place a bare number is allowed to appear. A calculator is
 * a pure function of (input, rate table); adding a new year means adding a
 * new RateTable implementation, never editing the calculator.
 */
interface RateTable
{
    public function year(): int;
}
