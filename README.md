# Qasa Backend

Modular monolith Laravel API pre fakturáciu a CRM. Postavený na Clean Architecture princípoch s vrstvami: Domain, Application, Infrastructure, Presentation.

## Development Setup

### Prerequisites
- PHP ^8.4
- Docker & Docker Compose (pre lokálny stack)
- Composer

### Rýchly start

```bash
# Clone & install
git clone <repo>
cd qasa_core
composer install

# Environment
cp .env.example .env
php artisan key:generate

# Docker
docker-compose up -d

# Database
php artisan migrate

# Prvý používateľ
php artisan qasa:user
```

### Development Commands

```bash
# Spustenie — všetko v docker qasa_app containeri:
docker-compose exec qasa_app php artisan serve

# Testing — Pest
composer pest
# alebo vnútri containera
docker-compose exec qasa_app php artisan test

# Static analysis — PHPStan (level 8)
composer phpstan

# Code formatting — Laravel Pint
composer pint
# alebo len check bez zmien
composer pint:check
```

## Architecture

Projekt je rozdelený do modulov pod `app/Modules/`:
- **Auth** — user authentication, Sanctum tokens, Google login
- **Clients** — klientske profily
- **Invoicing** — faktúry, ponuky, fakturačný cyklus, náklady, exporty (PDF, Pohoda, ISDOC, DPH výkazy)
- **Orders** — objednávky
- **Shared** — zdieľané utilities, middleware

Každý modul má vrstvy:
- **Domain**: Entities, Models, Repositories, business rules
- **Application**: DTOs, Actions, Services, Commands
- **Infrastructure**: External integrations, drivers
- **Presentation**: Controllers, FormRequests, API Resources

## Code Standards

- ✅ **Strict typing**: `declare(strict_types=1);` povinný v každom PHP súbore
- ✅ **PSR-12**: Formátovanie kontrolované Laravel Pint
- ✅ **PHPStan level 8**: Via Larastan; konfigurácia v `phpstan.neon`
- ✅ **Validation**: Cez FormRequest alebo Spatie Data DTOs, nikdy manuálne v controlleroch
- ✅ **Localization**: Žiadne hardcoded stringy, vždy `__('module.key')` s `lang/{locale}/` súbormi
- ✅ **Security**: Žiadne hardcoded credentials (`.env`), SQL injection prevention (Eloquent), auth middleware

## Testing

```bash
# Spustiť všetky testy
composer pest

# Konkrétny test
docker-compose exec qasa_app php artisan test tests/Feature/Auth

# S coverage
composer pest -- --coverage
```

Testy sú v `tests/` a bežia cez **Pest PHP** (^3.0) + **PHPUnit** (^11.3) proti reálnemu PostgreSQL v containeri.

## API Documentation

OpenAPI docs sú vygenerované z L5-Swagger (^11.0) a dostupné na `/api/documentation` po spustení.

## Key Dependencies

| Package | Version | Purpose |
|---------|---------|---------|
| Laravel | ^13.0 | Framework |
| PostgreSQL | default | Database (Eloquent ORM) |
| Laravel Sanctum | ^4.3 | API authentication |
| Laravel Socialite | ^5.26 | Google login |
| Spatie Laravel Data | ^4.21 | DTOs & validation |
| Spatie Laravel Query Builder | ^7.1 | Advanced query building |
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

1. Analyzuj existujúci kód a vzory modulu pred zmenou
2. Drž sa PHPStan (level 8) a Pint (formátovanie)
3. Lokalizuj všetky user-facing stringy (aj error messages)
4. Validation vždy skrz FormRequest/DTO, nikdy manuálne