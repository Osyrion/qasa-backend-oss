<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;

/**
 * A scoped personal access token may only exercise the abilities it was
 * granted — AuthServiceProvider's Gate::before enforces that, but only for
 * endpoints that actually ask the Gate something. Routes with no Policy and
 * no `permission:` middleware never ask, so a token narrowed to
 * `clients.view` used to read the account's whole financial picture:
 * statistics, VAT reports, expenses, exchange rates.
 *
 * RoutePermissionCoverageTest's allowlist waives those routes on tenancy
 * grounds ("own-account aggregate, no foreign id"), which is true and beside
 * the point — this file covers the half that allowlist never spoke to.
 *
 * Deliberately edition-agnostic, via createEntitledOwner(): token scoping is
 * core behaviour and holds in the OSS edition too — the token callback is
 * registered ahead of the OSS blanket grant precisely so a deny wins over it
 * — so this belongs in the suite both editions run. The role-matrix half of
 * the same fix needs spatie roles and is therefore SaaS-only; it lives in
 * tests/Feature/Team/ExpensePermissionTest.php, deleted by the OSS build
 * along with that module.
 */
it('denies an endpoint outside the token scope', function (string $path): void {
    $owner = createEntitledOwner(['country' => 'SK']);

    Sanctum::actingAs($owner, ['clients.view']);

    $this->getJson($path)->assertForbidden();
})->with([
    '/api/v1/statistics/overview',
    '/api/v1/statistics/receivables',
    '/api/v1/statistics/partners',
    '/api/v1/statistics/tables',
    '/api/v1/statistics/health',
    '/api/v1/reports/eu-sales-list?country=SK&year=2026&quarter=1',
    '/api/v1/reports/vat-control-statement?country=SK&year=2026',
    '/api/v1/reports/vat-control-statement/xml?country=SK&year=2026',
    '/api/v1/expenses',
    '/api/v1/exchange-rates',
]);

it('allows the same endpoint once the token carries the ability', function (string $ability, string $path): void {
    $owner = createEntitledOwner(['country' => 'SK']);

    Sanctum::actingAs($owner, [$ability]);

    $this->getJson($path)->assertOk();
})->with([
    ['reports.view', '/api/v1/statistics/overview'],
    ['reports.view', '/api/v1/reports/eu-sales-list?country=SK&year=2026&quarter=1'],
    ['invoices.view', '/api/v1/expenses'],
    ['invoices.view', '/api/v1/exchange-rates'],
]);

it('denies a write outside the token scope', function (): void {
    $owner = createEntitledOwner(['country' => 'SK']);

    Sanctum::actingAs($owner, ['invoices.view']);

    $this->postJson('/api/v1/expenses', [
        'description' => 'toner',
        'category' => 'office',
        'amount' => 42,
        'currency' => 'EUR',
        'date' => '2026-01-01',
    ])->assertForbidden();

    $this->postJson('/api/v1/exchange-rates', [
        'base_currency' => 'EUR',
        'target_currency' => 'CZK',
        'rate' => 25.1,
        'date' => '2026-01-02',
    ])->assertForbidden();
});

it('leaves a full-access login token untouched', function (): void {
    $owner = createEntitledOwner(['country' => 'SK']);

    // Login/2FA tokens are minted without an abilities argument, which in
    // Sanctum means the '*' wildcard. Spelled out here because
    // Sanctum::actingAs() defaults to [] — an empty ability set, the most
    // restricted token there is, not the least.
    Sanctum::actingAs($owner, ['*']);

    $this->getJson('/api/v1/statistics/overview')->assertOk();
    $this->getJson('/api/v1/expenses')->assertOk();
});
