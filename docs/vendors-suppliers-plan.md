# Plan: Suppliers / Vendors (dodávatelia) on the Clients module

## Context

A company/freelancer needs to track **vendors** (dodávatelia — parties they *receive* invoices from) in addition to **customers** (clients they *issue* invoices to). Some entities are both at once (e.g. a partner IT firm you bill for dev and buy hosting from). Rather than a separate table, we follow the competitor model: **one entity = one row** in the existing `clients` table, distinguished by two boolean flags:

- `is_customer` — you invoice them (default `true`, so all existing rows stay customers).
- `is_vendor` — you receive invoices from them (default `false`).

Both can be `true`. ARES/RPO sync, contact persons, VAT verification and history are already shared because they live on the single `clients` row — no duplication needed.

The main **Klienti** menu keeps showing `is_customer=1` by default; a future separate *Dodavatelé* view will request `is_vendor=1` via the same endpoint. Employees / collaborating persons (HR module) are **explicitly out of scope** here.

### Decisions (confirmed with user)
- **List filter API:** single `?role=customer|vendor|all` query param on `GET /api/v1/clients`. Omitted → `customer` (i.e. `is_customer=1`). `vendor` → `is_vendor=1`. `all` → no flag filter.
- **Create/update role rules:** if neither flag is sent, default `is_customer=true, is_vendor=false`. Reject with 422 if **both** are explicitly `false` (a client must be at least one role).

## Changes

### 1. Migration — add flags
New file: `database/migrations/2026_07_09_000001_add_customer_vendor_flags_to_clients.php` (follow the `add_vat_id_to_clients` pattern).
- `is_customer` boolean `default(true)` after `is_vat_payer`.
- `is_vendor` boolean `default(false)` after `is_customer`.
- Add indexes for the two future views: `$table->index(['user_id', 'is_customer'])` and `$table->index(['user_id', 'is_vendor'])`.
- `down()` drops the two indexes then the two columns.
- Existing rows: the `default(true)`/`default(false)` on column add backfills every current client as customer-only — no data migration needed.

### 2. Model — `app/Modules/Clients/Domain/Models/Client.php`
- Add `'is_customer'`, `'is_vendor'` to `$fillable`.
- Add both to `casts()` as `'boolean'`.
- Update the `@property bool $is_customer` / `@property bool $is_vendor` and `whereIsCustomer/whereIsVendor` docblock lines (match existing style).
- Add helper methods `isCustomer(): bool` and `isVendor(): bool` returning the respective flags (mirrors existing `isCompany()` etc.).

### 3. DTO — `app/Modules/Clients/Application/DTOs/ClientData.php`
- Add constructor props `public readonly bool $is_customer` and `public readonly bool $is_vendor` **with defaults** `= true` / `= false` so an omitted flag falls back correctly (Spatie Data uses the default when key absent).
- In `rules()` add `'is_customer' => ['boolean']`, `'is_vendor' => ['boolean']`.
- The "at least one role" check is a domain rule → enforce in the actions (below), not in `rules()`, consistent with how name/company validation is done in `CreateClientAction::validate()`.

### 4. Actions — `CreateClientAction.php` & `UpdateClientAction.php`
- Add `'is_customer' => $data->is_customer` and `'is_vendor' => $data->is_vendor` to the repository `create`/`update` arrays.
- In each `validate(ClientData $data)`: `if (! $data->is_customer && ! $data->is_vendor) throw DomainException::because(__('clients.role_required'));`

### 5. Repository — `EloquentClientRepository::paginate()` + interface
- Interpret a new `role` filter key:
  - `'vendor'` → `$query->where('is_vendor', true)`
  - `'all'` → no flag filter
  - default / `'customer'` / anything else → `$query->where('is_customer', true)`
- No interface signature change (still `array $filters`); just document the new key.

### 6. Controller — `ClientController.php`
- `index()`: add `'role'` to `$request->only([...])`.
- Add an `OA\Parameter` for `role` (enum `customer, vendor, all`, default `customer`) on the `index` OpenAPI block.
- Add `is_customer` / `is_vendor` `OA\Property` entries (boolean) to the `store` and `update` request-body schemas.

### 7. Resource — `ClientResource.php`
- Add `'is_customer' => $this->resource->is_customer` and `'is_vendor' => $this->resource->is_vendor` to `toArray()`.
- Add matching `OA\Property` boolean entries to the `#[OA\Schema('Client')]` block.

### 8. Localization — `lang/en/clients.php` & `lang/sk/clients.php`
- Add key `role_required`:
  - en: `'A client must be a customer, a vendor, or both.'`
  - sk: `'Klient musí byť odberateľ, dodávateľ alebo oboje.'`

### 9. Factory — `database/factories/.../ClientFactory.php`
- Add `'is_customer' => true, 'is_vendor' => false` to `definition()`.
- Add a `vendor()` state (`is_customer=false, is_vendor=true`) and a `both()` state (both true) for tests, mirroring existing `company()`/`individual()` states.

## Out of scope (noted, not implemented)
- No separate `/vendors` endpoint or controller — the `role` filter covers the future Dodavatelé view.
- No enforcement that invoices/orders may only target `is_customer` rows (Invoicing/Orders untouched). Can be added later if desired.
- Employees / collaborating persons → future HR module.

## Verification
1. `composer phpstan` (level 8) and `./vendor/bin/pint --dirty` must pass on all touched files.
2. Run migration against the docker Postgres: `php artisan migrate` and confirm existing clients have `is_customer=true, is_vendor=false`.
3. Add/extend Pest feature tests under `tests/Feature/Clients/` (a `ClientCrudTest.php` — none exists yet):
   - Creating with no flags → persisted `is_customer=true, is_vendor=false`.
   - Creating with `is_vendor=1` only → `is_customer=false, is_vendor=true`.
   - Creating with both flags false → **422** with the `role_required` message.
   - `GET /clients` (no `role`) returns only customers; `?role=vendor` returns only vendors; `?role=all` returns both. Use `Client::factory()->vendor()` / `->both()` for fixtures.
   - `ClientResource` payload includes `is_customer` / `is_vendor`.
4. Run the suite: `php artisan test --filter=Client` (or `./vendor/bin/pest tests/Feature/Clients`).
