<?php

declare(strict_types=1);

use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Invoicing\Domain\Models\ExchangeRate;
use App\Modules\Invoicing\Domain\Models\Expense;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\InvoicePayment;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Taxation\Application\Services\TaxIncomeAggregator;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Models\ContributionPayment;

it('aggregates business income, expenses and contributions for the year, in the filing currency', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-01-10', 'total' => 1000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 600, 'paid_at' => '2026-03-05']);

    SupplierInvoice::factory()->create([
        'user_id' => $user->id, 'currency' => 'EUR', 'total' => 150,
        'status' => 'paid', 'paid_at' => '2026-04-01',
    ]);

    Expense::factory()->create(['user_id' => $user->id, 'currency' => 'EUR', 'amount' => 40, 'date' => '2026-05-01']);

    ContributionPayment::factory()->for($user)->create(['type' => 'social', 'currency' => 'EUR', 'amount' => 90, 'paid_at' => '2026-02-01']);
    ContributionPayment::factory()->for($user)->create(['type' => 'health', 'currency' => 'EUR', 'amount' => 70, 'paid_at' => '2026-02-01']);
    ContributionPayment::factory()->for($user)->create(['type' => 'income_tax_advance', 'currency' => 'EUR', 'amount' => 200, 'paid_at' => '2026-02-01']);

    $data = app(TaxIncomeAggregator::class)->aggregate($user, 2026, TaxResidency::Sk);

    expect($data->currency->value)->toBe('EUR')
        ->and($data->businessIncome)->toBe(600.0)
        ->and($data->supplierInvoiceExpenses)->toBe(150.0)
        ->and($data->otherExpenses)->toBe(40.0)
        ->and($data->socialContributionsPaid)->toBe(90.0)
        ->and($data->healthContributionsPaid)->toBe(70.0)
        ->and($data->incomeTaxAdvancesPaid)->toBe(200.0)
        ->and($data->totalActualExpenses())->toBe(350.0);
});

it('converts amounts in a foreign currency into the filing currency using the daily rate', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    ExchangeRate::factory()->system()->create([
        'base_currency' => 'CZK', 'target_currency' => 'EUR', 'rate' => 0.04, 'date' => '2026-03-04',
    ]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'CZK',
        'issued_at' => '2026-01-10', 'total' => 25000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 25000, 'paid_at' => '2026-03-05']);

    $data = app(TaxIncomeAggregator::class)->aggregate($user, 2026, TaxResidency::Sk);

    expect($data->businessIncome)->toBe(1000.0)
        ->and($data->unconvertedAmounts)->toBe([]);
});

it('flags an amount as unconverted rather than silently dropping it when no exchange rate is available', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'CZK',
        'issued_at' => '2026-01-10', 'total' => 25000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 25000, 'paid_at' => '2026-03-05']);

    $data = app(TaxIncomeAggregator::class)->aggregate($user, 2026, TaxResidency::Sk);

    expect($data->businessIncome)->toBe(0.0)
        ->and($data->unconvertedAmounts)->toBe([['currency' => 'CZK', 'amount' => 25000.0, 'date' => '2026-03-05']]);
});

it('nets a credit note against the same year collected income', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-01-10', 'total' => 1000,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 1000, 'paid_at' => '2026-02-01']);

    $creditNote = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'credit_note', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-06-10', 'total' => -200,
    ]);
    // A refund against the credit note is recorded as a negative payment.
    InvoicePayment::factory()->create(['invoice_id' => $creditNote->id, 'amount' => -200, 'paid_at' => '2026-06-15']);

    $data = app(TaxIncomeAggregator::class)->aggregate($user, 2026, TaxResidency::Sk);

    expect($data->businessIncome)->toBe(800.0);
});

it('scopes payments strictly to the calendar year — a payment on New Year\'s Eve counts, the next day does not', function (): void {
    $user = createUser(['country' => 'SK']);
    $client = Client::factory()->create(['user_id' => $user->id]);

    $invoice = Invoice::factory()->create([
        'user_id' => $user->id, 'client_id' => $client->id,
        'type' => 'invoice', 'status' => 'sent', 'currency' => 'EUR',
        'issued_at' => '2026-12-20', 'total' => 500,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300, 'paid_at' => '2026-12-31']);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 200, 'paid_at' => '2027-01-01']);

    $data2026 = app(TaxIncomeAggregator::class)->aggregate($user, 2026, TaxResidency::Sk);
    $data2027 = app(TaxIncomeAggregator::class)->aggregate($user, 2027, TaxResidency::Sk);

    expect($data2026->businessIncome)->toBe(300.0)
        ->and($data2027->businessIncome)->toBe(200.0);
});
