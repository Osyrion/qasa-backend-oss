<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\RegisterUserData;
use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisterUserAction
{
    /**
     * @throws Throwable
     */
    public function execute(RegisterUserData $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            /** @var class-string<User> $model */
            $model = config('auth.providers.users.model', User::class);

            $user = $model::create([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'password' => $data->password,
                'default_currency' => $data->default_currency,
                'locale' => $data->locale,
                'country' => $data->country,
                'invoice_prefix' => 'FA',
                'is_vat_payer' => false,
                'tax_flat_rate' => 0,
            ]);

            // Self-registered users own their account — the SaaS Team module
            // assigns the Owner role via a listener on this event.
            event(new UserRegistered($user));

            return $user;
        });

        // Outside the transaction — a mail failure must not break registration.
        rescue(fn () => $user->sendEmailVerificationNotification());

        return $user;
    }
}
