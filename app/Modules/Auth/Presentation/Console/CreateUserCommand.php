<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Console;

use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The OSS edition has no public registration — accounts are created here.
 * E-mail is marked verified: whoever runs artisan owns the instance.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'qasa:user
        {--name= : First name}
        {--surname= : Last name}
        {--email= : E-mail address (login)}
        {--password= : Password, min. 8 characters (prompted when omitted)}';

    protected $description = 'Create a user account';

    public function handle(): int
    {
        $input = [
            'name' => $this->option('name') ?? $this->ask('First name'),
            'surname' => $this->option('surname') ?? $this->ask('Last name'),
            'email' => $this->option('email') ?? $this->ask('E-mail address'),
            'password' => $this->option('password') ?? $this->secret('Password (min. 8 characters)'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        $user = $model::query()->forceCreate([
            ...$validator->validated(),
            'email_verified_at' => now(),
            'default_currency' => 'EUR',
            'locale' => 'sk',
            'country' => 'SK',
            'invoice_prefix' => 'FA',
            'is_vat_payer' => false,
            'tax_flat_rate' => 0,
        ]);

        event(new UserRegistered($user));

        $this->info("User {$user->email} created.");

        return self::SUCCESS;
    }
}
