# Setup checklist

## 1. Vytvorenie projektu

```bash
git clone git@github.com:ty/moj-saas.git
cd moj-saas
composer create-project laravel/laravel .
```

## 2. Inštalácia balíčkov

```bash
# Core API + Auth
php artisan install:api
composer require laravel/socialite
composer require laravel/cashier

# DDD / Architecture
composer require spatie/laravel-data
composer require spatie/laravel-query-builder

# PDF
composer require barryvdh/laravel-dompdf

# CSV import
composer require league/csv

# Dev only
composer require barryvdh/laravel-debugbar --dev
composer require laravel/telescope --dev
php artisan telescope:install

# Static analysis
composer require nunomaduro/larastan --dev
composer require vimeo/psalm --dev
composer require psalm/plugin-laravel --dev
```

## 3. Kopírovanie súborov

```bash
# Migrácie — zmaž defaultné Laravel migrácie pre users
rm database/migrations/0001_01_01_000000_create_users_table.php

# Skopíruj naše migrácie
cp -r /cesta/k/suborom/migrations/* database/migrations/

# Skopíruj moduly
cp -r /cesta/k/suborom/app/Modules app/Modules

# Skopíruj providera
cp /cesta/k/suborom/app/Providers/ModuleServiceProvider.php app/Providers/

# Skopíruj config
cp /cesta/k/suborom/config/admin.php config/
```

## 4. composer.json — autoload

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "App\\Modules\\": "app/Modules/"
    }
}
```

```bash
composer dump-autoload
```

## 5. config/app.php — providers

```php
// V sekcii Application Service Providers pridaj:
App\Providers\ModuleServiceProvider::class,
```

## 6. config/auth.php — guards a providers

```php
'guards' => [
    // ... existujúce ...
    'admin' => [
        'driver'   => 'sanctum',
        'provider' => 'admin_users',
    ],
],

'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model'  => App\Modules\Auth\Domain\Models\User::class,
    ],
    'admin_users' => [
        'driver' => 'eloquent',
        'model'  => App\Modules\Admin\Domain\Models\AdminUser::class,
    ],
],
```

## 7. config/sanctum.php — guard

```php
'guard' => ['web', 'admin'],
```

## 8. config/cashier.php — model

```php
// Cashier používa náš User model
// V .env:
// CASHIER_MODEL=App\Modules\Auth\Domain\Models\User
```

## 9. .env

```env
APP_NAME="Qasa"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost

# Database
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=qasa
DB_USERNAME=qasa
DB_PASSWORD=secret

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Google OAuth
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI="${APP_URL}/api/v1/auth/google/callback"

# Stripe
STRIPE_KEY=
STRIPE_SECRET=
STRIPE_WEBHOOK_SECRET=
CASHIER_MODEL=App\Modules\Auth\Domain\Models\User

# Admin secret route
ADMIN_PATH=
ADMIN_TOKEN=
ADMIN_SEED_EMAIL=admin@example.com
ADMIN_SEED_PASSWORD=

# Storage
FILESYSTEM_DISK=local
# Pre R2:
# AWS_ACCESS_KEY_ID=
# AWS_SECRET_ACCESS_KEY=
# AWS_DEFAULT_REGION=auto
# AWS_BUCKET=
# AWS_URL=
# AWS_ENDPOINT=https://<account>.r2.cloudflarestorage.com
```

## 10. config/filesystems.php — R2 disk

```php
'disks' => [
    // ... existujúce ...
    'r2' => [
        'driver'   => 's3',
        'key'      => env('AWS_ACCESS_KEY_ID'),
        'secret'   => env('AWS_SECRET_ACCESS_KEY'),
        'region'   => env('AWS_DEFAULT_REGION', 'auto'),
        'bucket'   => env('AWS_BUCKET'),
        'url'      => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => true,
    ],
],
```

## 11. config/services.php — Google + Stripe

```php
'google' => [
    'client_id'     => env('GOOGLE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    'redirect'      => env('GOOGLE_REDIRECT_URI'),
],
```

## 12. Migrácie a seed

```bash
php artisan key:generate
php artisan migrate
php artisan db:seed --class="App\Modules\Admin\Infrastructure\Seeders\AdminUserSeeder"
```

## 13. Generovanie admin credentials

```bash
# Vygeneruj ADMIN_PATH a ADMIN_TOKEN
php artisan tinker --execute="echo bin2hex(random_bytes(8)).PHP_EOL;"
# Spusti dvakrát — raz pre PATH, raz pre TOKEN
# Výsledky vlož do .env
```

## 14. Larastan konfig

```bash
cp vendor/nunomaduro/larastan/config/larastan.neon phpstan.neon
```

`phpstan.neon`:
```neon
includes:
    - vendor/nunomaduro/larastan/extension.neon

parameters:
    paths:
        - app
    level: 6
    ignoreErrors:
        - '#Call to an undefined method Illuminate\\Database\\Eloquent\\Builder#'
```

## 15. Psalm konfig

```bash
vendor/bin/psalm --init
vendor/bin/psalm-plugin enable psalm/plugin-laravel
```

`psalm.xml`:
```xml
<?xml version="1.0"?>
<psalm errorLevel="4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
       xmlns="https://getpsalm.org/schema/config"
       xsi:schemaLocation="https://getpsalm.org/schema/config vendor/vimeo/psalm/config.xsd">
    <projectFiles>
        <directory name="app" />
        <ignoreFiles>
            <directory name="vendor" />
        </ignoreFiles>
    </projectFiles>
    <plugins>
        <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
    </plugins>
</psalm>
```

## 16. Telescope — len lokálne

`app/Providers/TelescopeServiceProvider.php` — Laravel generuje pri inštalácii, uprav:

```php
public function register(): void
{
    Telescope::night();

    $this->hideSensitiveRequestDetails();

    Telescope::filter(function (IncomingEntry $entry) {
        if ($this->app->environment('local')) {
            return true;
        }
        // Na produkcii nič neloguj
        return false;
    });
}
```

## 17. Debugbar — len lokálne

`.env`:
```env
DEBUGBAR_ENABLED=true  # automaticky false ak APP_ENV != local
```

## 18. Spustenie

```bash
# Docker
docker-compose up -d

# Alebo lokálne
php artisan serve
php artisan queue:work
php artisan schedule:work
```

Scheduler (`schedule:work`, v produkcii cron `* * * * * php artisan schedule:run`)
je potrebný pre pravidelné faktúry — denne o 05:00 beží
`qasa:invoices:generate-recurring`, ktorý generuje koncepty faktúr
zo šablón (`recurring_invoice_templates`).
