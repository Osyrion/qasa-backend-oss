# Qasa Backend — OSS Core Edition

Modular monolith Laravel API pre CRM a time tracking. Postavený na Clean Architecture princípoch s vrstvami: Domain, Application, Infrastructure, Presentation.

## Development Setup

### Prerequisites
- PHP ^8.4
- Docker & Docker Compose (pre lokálny stack)
- Composer

### Rýchly start

```bash
# Clone & install
git clone <repo>
cd qasa_backend
composer install

# Environment
cp .env.example .env
php artisan key:generate

# Docker
docker-compose up -d

# Database
php artisan migrate
php artisan db:seed --class="App\Modules\Admin\Infrastructure\Seeders\AdminUserSeeder"
```

### Development Commands

```bash
# Spustenie — všetko v docker qasa_app containeri:
docker-compose exec qasa_app php artisan serve

# Testing — Pest
composer pest
# alebo vnútri containera
docker-compose exec qasa_app php artisan pest

# Static analysis — PHPStan (level 8)
composer phpstan
# alebo
docker-compose exec qasa_app php artisan phpstan

# Code formatting — Laravel Pint
composer pint
# alebo len check bez zmien
composer pint:check
```

## Architecture

Projekt je rozdelený do modulov pod `app/Modules/`:
- **Admin** — admin panel, system config
- **Auth** — user authentication, Sanctum tokens
- **Clients** — klientske profily
- **Invoicing** — faktúry, faktúračný cyklus
- **Orders** — objednávky
- **Pricing** — cenové plány, subscriptions (Stripe)
- **Saas** — SaaS vrstva (Team, Organization — odpojená v OSS)
- **Shared** — zdieľané utilities, middleware
- **Subscriptions** — billing, Cashier integracia
- **Team** — team management, permissions (Spatie)
- **TimeTracking** — time entries, projektový tracking

Každý modul má vrstvy:
- **Domain**: Entities, Models, Repositories, business rules
- **Application**: DTOs, Actions, Services, Commands
- **Infrastructure**: External integrations, drivers
- **Presentation**: Controllers, FormRequests, API Resources

## Code Standards

- ✅ **Strict typing**: `declare(strict_types=1);` povinný v každom PHP súbore
- ✅ **PSR-12**: Formátovanie kontrolované Laravel Pint
- ✅ **PHPStan level 8**: Via Larastan; výsledky v `phpstan.neon`
- ✅ **Validation**: Cez FormRequest alebo Spatie Data DTOs, nikdy manuálne v controlleroch
- ✅ **Localization**: Žiadne hardcoded stringy, vždy `__('module.key')` s `lang/{locale}/` súbormi
- ✅ **Security**: Žiadne hardcoded credentials (`.env`), SQL injection prevention (Eloquent), auth middleware

## Testing

```bash
# Spustiť všetky testy
composer pest

# Konkrétny test
docker-compose exec qasa_app php artisan pest tests/Feature/AuthTest.php

# S coverage
composer pest -- --coverage
```

Testy sú v `tests/` a pomocou **Pest PHP** (^3.0) + **PHPUnit** (^11.3).

## API Documentation

OpenAPI docs sú vygenerované z L5-Swagger (^11.0) a dostupné na `/api/documentation` po spustení.

## Key Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| Laravel | ^13.0 | Framework |
| PostgreSQL | default | Database (Eloquent ORM) |
| Laravel Sanctum | ^4.3 | API authentication |
| Laravel Cashier | ^16.5 | Stripe subscriptions |
| Spatie Laravel Data | ^4.21 | DTOs & validation |
| Spatie Laravel Query Builder | ^7.1 | Advanced query building |
| Spatie Laravel Permission | ^7.2 | Role-based access |
| Larastan / PHPStan | ^3.9 / ^2.1 | Static analysis |
| Laravel Pint | ^1.27 | Code formatting |
| Pest PHP | ^3.0 | Testing framework |

## Database

Default: **PostgreSQL** (`pgsql` connection). Ako konfigurované v `.env`:

```env
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=qasa
DB_USERNAME=qasa
DB_PASSWORD=secret
```

## Contributing

Pozri CLAUDE.md pre presné architektonické pravidlá, bezpečnostné pokyny, a workflow.

Stručne:
1. Analyzuj existujúce kódy pred inštaláciou
2. Drž sa PHPStan (level 8) a Pint (formátovanie)
3. Lokalizuj všetky user-facing stringy (aj error messages)
4. Validation vždy skrz FormRequest/DTO, nikdy manuálne
