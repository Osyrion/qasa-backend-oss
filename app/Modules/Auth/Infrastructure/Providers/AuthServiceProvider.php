<?php

declare(strict_types=1);

namespace App\Modules\Auth\Infrastructure\Providers;

use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Presentation\Console\CreateUserCommand;
use App\Modules\Shared\Authorization\AbilityCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../Presentation/Routes/auth.php');

        if ($this->app->runningInConsole()) {
            $this->commands([CreateUserCommand::class]);
        }

        // The OSS edition has no roles — grant every core ability and leave
        // data isolation to HasUserScope and the policies' account checks.
        if (config('qasa.edition') === 'oss') {
            Gate::before(fn (User $user, string $ability) => AbilityCatalog::handles($ability) ? true : null);
        }

        // Reset links land on the SPA, which posts the token back to the API.
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim((string) config('app.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->email);
        });
    }
}
