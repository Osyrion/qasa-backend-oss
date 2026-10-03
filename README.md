# Zoad Core

Open-source invoicing and CRM API for freelancers and small businesses in
Slovakia and Czechia. Laravel modular monolith on Clean Architecture layers:
Domain, Application, Infrastructure, Presentation.

Licensed under **AGPL-3.0-only** — see `LICENSE`.

> This repository is **generated**. It is built from the Zoad SaaS repository
> by removing the premium modules, and every build overwrites it — changes
> committed here directly will be lost. Please open issues rather than pull
> requests.

## What's in the core

Clients and contacts · orders and order items · invoices, proforma and
corrective invoices · quotes with public accept/reject links · recurring
invoice templates (manual "generate now"; the daily cron is premium) ·
supplier invoices · manual payment recording, expenses and exchange rates ·
bank accounts with payment QR codes (PayBySquare / SPAYD / EPC) · CSV invoice
export · invoice inbox with OCR + AI field extraction (regex by default,
bring-your-own-key Anthropic extraction included) · VAT rate catalogue and VAT
recap · SK/CZ tax residency, reverse charge, VAT control statement and EU
sales list · activity log · account export (GDPR) · 2FA and scoped API
tokens.

Not included: multi-user teams and roles, subscriptions and billing, time
tracking, price lists and rate history, calendar, webhooks and Stripe Connect,
reporting dashboards, admin tooling, bank statement import and bulk payment
orders (ABO/SEPA), Pohoda/Omega/ISDOC accounting-software exports, automatic
invoice-reminder and recurring-invoice cron jobs.

## Requirements

- PHP 8.5+
- PostgreSQL 13+
- Composer, Docker (optional, for the local stack)

The application serves requests as a second, unprivileged database role, so
that PostgreSQL's own row-level policies decide which account a query can
see. `DB_APP_USERNAME` and `DB_APP_PASSWORD` configure it; the first
migration creates the role from them, running as the owner in `DB_USERNAME`.
Set a real password before deploying — `.env.example` ships a development
default. Point both at the same account and every policy stops applying,
silently.

`migrate` and `db:seed` pick the owner connection themselves, so no extra
flag is needed. Everything else — requests, the scheduler, `queue:work` —
runs as the unprivileged role.

One consequence worth knowing before an upgrade: a queued job carries the
account it was dispatched for in its payload, because a worker has no request
to get it from. Jobs already sitting in the queue from an older release do
not, and will fail once they run. Drain the queue before deploying, or
`queue:retry` what fails afterwards — a retry builds the payload afresh.

Client and order search is diacritics-insensitive, which is built on the
`pg_trgm` and `unaccent` extensions. The first migration creates them, so
nothing needs installing by hand — but it does mean the database user has to
be allowed to `CREATE EXTENSION`. Both are trusted extensions from
PostgreSQL 13 on, so the database owner can do it without superuser rights,
and every managed Postgres worth using permits them.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate

docker-compose up -d      # nginx + php + postgres
php artisan migrate

# There is no public registration in this edition — create the first account:
php artisan qasa:user
```

## Development

```bash
php artisan test          # Pest
composer phpstan          # PHPStan level 8
./vendor/bin/pint         # code style
```

## Editions

`config/qasa.php` carries an `edition` flag, defaulting to `oss`. Core modules
never reference premium ones: optional behaviour goes through a contract with
a no-op default, an event, or a config-resolved value. That is what makes this
repository a mechanical subset rather than a fork.
