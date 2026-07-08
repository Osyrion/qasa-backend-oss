# AGENTS.md

## Core Rules

### Technology Stack
- **PHP**: ^8.4 (with `declare(strict_types=1);` strictly required in all PHP files)
- **Framework**: Laravel ^13.0
- **Database / Eloquent**: PostgreSQL or MySQL, leveraging Eloquent ORM
- **Authentication & Authorization**: Laravel Sanctum, Laravel Socialite, Spatie Laravel Permission
- **Payment & Subscriptions**: Laravel Cashier (Stripe integration)
- **Data Transfer Objects**: Spatie Laravel Data
- **Query Building**: Spatie Laravel Query Builder
- **Testing**: Pest PHP (^3.0), PHPUnit (^11.3)
- **Static Analysis & Code Quality**: PHPStan (^2.0), Psalm (^6.0), Laravel Pint (^1.27)
- **API Documentation**: L5-Swagger (OpenAPI)

### Architecture
- **Modular Monolith**: The codebase is organized into modules located in `@/home/osyrion/projects/qasa_backend/app/Modules:1-8`.
- **Architectural Layers**: Each module is split into strict layers:
  - **Domain**: Entities, Models, Repositories, and core business logic rules.
  - **Application**: DTOs, Actions, Services, Commands, and Handlers.
  - **Infrastructure**: Concrete implementations of external services, integrations, and drivers.
  - **Presentation**: HTTP Controllers, FormRequests, Resources, and API routes.

### Coding Standards & Best Practices
- **Strict Typing**: Every single PHP file must begin with `declare(strict_types=1);`.
- **PSR Standard**: Adhere strictly to PSR-12 and formatting rules enforced by Laravel Pint.
- **Validation**: Never validate parameters manually inside controllers or actions. Always use Laravel `FormRequest` classes or Spatie `Data` DTO validation attributes in the Presentation/Application layers.
- **Type Safety**: Strictly define return types and argument types for all methods and functions. Utilize phpdoc annotations where generic collections or complex types are used to satisfy PHPStan and Psalm.

### Security Guidelines
- **Zero Hardcoding**: Never hardcode API keys, client secrets, passwords, or environments. Use Laravel `config()` helper retrieved from `.env`.
- **SQL Injection Prevention**: Always use Eloquent query builder or parameter binding. Never concatenate input strings inside raw database queries (`DB::raw()`).
- **Authorization**: Ensure all controllers or actions verify permissions and authorization boundaries. Use Spatie Permissions, Laravel Policies, or Route Middlewares to enforce strict authorization.

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
2. **Consult Static Analyzers**: Keep PHPStan, Psalm, and Pint rules in mind when writing or modifying code. Run or suggest dry runs to ensure code meets compliance.
3. **Be Direct and Concise**: Answer directly to the problem, keep comments to a minimum unless necessary, and do not include conversational fluff. Start addressing the issue immediately.
