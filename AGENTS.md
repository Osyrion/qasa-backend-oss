# AGENTS.md

## Core Rules

### Technology Stack
- **PHP**: ^8.4 (with `declare(strict_types=1);` strictly required in all PHP files)
- **Framework**: Laravel ^13.0
- **Database / Eloquent**: PostgreSQL (default connection, `pgsql`), leveraging Eloquent ORM. Local stack runs via `docker-compose.yml` (nginx, php, postgres). Tests run against a real Postgres via the `qasa_app`/`qasa_db` containers (`docker exec qasa_app php artisan test`) — host PHP has no DB drivers.
- **Authentication**: Laravel Sanctum ^4.3 (token-based), Laravel Socialite ^5.26 (Google login)
- **Authorization**: No Spatie Permission package in this repo. `Gate::before(...)` in `AuthServiceProvider` auto-grants every ability in `App\Modules\Shared\Authorization\AbilityCatalog` — this is the single-tenant OSS edition; data isolation is enforced by `HasUserScope` + policy `sameAccount()` checks, not by a role/permission matrix.
- **Data Transfer Objects**: Spatie Laravel Data ^4.21
- **Query Building**: Spatie Laravel Query Builder ^7.1
- **Testing**: Pest PHP (^3.0), PHPUnit (^11.3)
- **Static Analysis & Code Quality**: PHPStan ^2.1 via Larastan ^3.9 (level 8, config in `phpstan.neon`), Laravel Pint ^1.27. Psalm is **not** used on this project — do not suggest it or write for it.
- **API Documentation**: L5-Swagger ^11.0 (OpenAPI)
- **Other notable deps**: Laravel Telescope ^5.20 (dev, not discovered by default — see `dont-discover` in `composer.json`), Laravel Debugbar ^4.2, barryvdh/laravel-dompdf ^3.1 (PDF rendering), chillerlan/php-qrcode ^6.0, league/csv ^9.28, sabre/vobject ^4.5 (ICS calendar import/export), smalot/pdfparser ^2.12 (scan-inbox PDF text extraction), thiagoalessio/tesseract_ocr ^2.13 (scan-inbox image OCR), laravel/mcp ^0.7.2 + mcp/sdk ^0.5.0
- **Not in this repo**: Laravel Cashier (Stripe) and Spatie Laravel Permission belong to the closed-source SaaS overlay (`qasa_saas`), not this open-core repository — do not add code that assumes they're installed here. Cross-check versions/deps against `composer.json` before citing them, this list drifts.

### Architecture
- **Modular Monolith**: The codebase is organized into modules under `app/Modules/`. Current modules: `Auth`, `Calendar`, `Clients`, `Invoicing`, `Orders`, `Pricing`, `Shared`, `TimeTracking`. Modules `Admin`, `Saas`, `Subscriptions` and `Team` are **not** in this repo — they live in the closed-source `qasa_saas` overlay, which swaps in `Saas\Domain\Models\User extends CoreUser` via the auth provider config.
- **Architectural Layers**: Each module is split into strict layers:
    - **Domain**: Entities, Models, Repositories, and core business logic rules.
    - **Application**: DTOs, Actions, Services, Commands, and Handlers.
    - **Infrastructure**: Concrete implementations of external services, integrations, and drivers.
    - **Presentation**: HTTP Controllers, FormRequests, Resources, and API routes.

### Coding Standards & Best Practices
- **Strict Typing**: Every single PHP file must begin with `declare(strict_types=1);`.
- **PSR Standard**: Adhere strictly to PSR-12 and formatting rules enforced by Laravel Pint.
- **Validation**: Never validate parameters manually inside controllers or actions. Always use Laravel `FormRequest` classes or Spatie `Data` DTO validation attributes in the Presentation/Application layers. The established pattern here is `SomeData::validateAndCreate($request->all())` — it validates against the DTO's constructor attributes merged with its `static rules()` method, then constructs the typed object. Do **not** call `$request->validated($rules)`: the base `Illuminate\Http\Request` has no `validated()` method (only `FormRequest` does) and it throws `BadMethodCallException` at runtime; use `$request->validate($rules)` (returns the array) or the DTO's `validateAndCreate()`.
- **Type Safety**: Strictly define return types and argument types for all methods and functions. Utilize phpdoc annotations where generic collections or complex types are used to satisfy PHPStan (level 8).

### Localization
- **No hardcoded user-facing strings**: API response messages and domain-exception messages must never be inline string literals. Use `__('module.key')` against `lang/{locale}/{module}.php` files (one file per module, matching the `app/Modules/` names — e.g. `lang/en/auth.php`, `lang/sk/time_tracking.php`). Add both `en` and `sk` entries for every new key.
- **Locale resolution**: `App\Modules\Shared\Presentation\Middleware\SetLocale` (registered globally in `bootstrap/app.php`) sets the request locale, in order: the authenticated user's `locale` column, then the `Accept-Language` header, then `config('app.locale')`. Available locales are declared in `config('qasa.locales.available')`.
- **Interpolated values**: use Laravel's `:placeholder` syntax (`__('invoicing.vat_rate_not_in_catalog', ['rate' => $rate])`), never string interpolation inside the translation call.

### Security Guidelines
- **Zero Hardcoding**: Never hardcode API keys, client secrets, passwords, or environments. Use Laravel `config()` helper retrieved from `.env`.
- **SQL Injection Prevention**: Always use Eloquent query builder or parameter binding. Never concatenate input strings inside raw database queries (`DB::raw()`).
- **Authorization**: Ensure all controllers or actions verify permissions and authorization boundaries. Use Laravel Policies (with `sameAccount()`/`HasUserScope`) or Route Middlewares to enforce strict authorization — this repo has no Spatie Permission package (see Technology Stack).

---

## Agent Roles

### 🧠 Laravel Expert
- **Domain**: Backend architecture, Eloquent relationship optimization, modular monolith code design.
- **Goal**: Maintain architectural integrity across DDD/Clean Architecture layers. Ensure class single responsibility, reusable Actions, and proper database indexing. Avoid N+1 queries by recommending eager-loading.

### 🛡️ Security Auditor
- **Domain**: Input validation, vulnerability patching, authentication scopes, and strict permission verification.
- **Goal**: Review any changes or new features for common OWASP vulnerabilities, mass-assignment issues, unauthorized data access, and improper input cleaning.

---

## Workflow & Context

### Pre-requisites & Rules
1. **Analyze First**: Before editing or adding any code, deeply analyze the existing files, dependencies, and architectural patterns of the target module. Do not duplicate existing utilities or services.
2. **Consult Static Analyzers**: Keep PHPStan (`composer phpstan`, level 8) and Pint rules in mind when writing or modifying code. Run or suggest dry runs to ensure code meets compliance.
3. **Be Direct and Concise**: Answer directly to the problem, keep comments to a minimum unless necessary, and do not include conversational fluff. Start addressing the issue immediately.
