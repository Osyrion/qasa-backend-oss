<?php

declare(strict_types=1);

/**
 * Invoicing is OSS core and must stay Stripe-agnostic — the "pay online"
 * button is entirely optional (gated by OnlinePaymentAvailabilityInterface,
 * null-object AlwaysUnavailableOnlinePayment in the OSS build). All Stripe
 * Connect code lives in Integrations (SaaS-only), which is already allowed
 * to depend on Invoicing — never the other way around. See
 * docs/plans/STRIPE_INVOICE_PAYMENTS_PLAN.md.
 */
arch('Invoicing does not import the stripe-php SDK')
    ->expect('App\Modules\Invoicing')
    ->not->toUse('Stripe');

arch('Invoicing does not import the Integrations module')
    ->expect('App\Modules\Invoicing')
    ->not->toUse('App\Modules\Integrations');

arch("PublicInvoiceController doesn't know about Stripe directly")
    ->expect('App\Modules\Invoicing\Presentation\Controllers\PublicInvoiceController')
    ->not->toUse(['Stripe', 'App\Modules\Integrations']);
