<?php

declare(strict_types=1);

namespace App\Modules\Auth\Presentation\Console;

use App\Modules\Auth\Domain\Events\UserRegistered;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Auth\Domain\Rules\EmailAvailable;
use App\Modules\Shared\Support\TenantContext;
use App\Modules\Taxation\Application\Contracts\TaxSystemResolverInterface;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The OSS edition has no public registration — accounts are created here.
 * E-mail is marked verified: whoever runs artisan owns the instance. There
 * is no step-2 flow in this edition, so residency (mandatory, immutable —
 * see the Taxation module) is collected right here instead.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'qasa:user
        {--name= : First name}
        {--surname= : Last name}
        {--email= : E-mail address (login)}
        {--password= : Password, min. 8 characters (prompted when omitted)}
        {--country= : Tax residency, SK or CZ}
        {--ico= : IČO, format-validated against the given country}';

    protected $description = 'Create a user account';

    public function handle(TaxSystemResolverInterface $taxSystems): int
    {
        $input = [
            'name' => $this->option('name') ?? $this->ask('First name'),
            'surname' => $this->option('surname') ?? $this->ask('Last name'),
            'email' => $this->option('email') ?? $this->ask('E-mail address'),
            'password' => $this->option('password') ?? $this->secret('Password (min. 8 characters)'),
            'country' => $this->option('country') ?? $this->ask('Tax residency (SK or CZ)'),
            'ico' => $this->option('ico') ?? $this->ask('IČO'),
        ];

        // Which rule validates an IČO is a property of the residency, and
        // TaxSystemResolver is where that mapping lives. Spelling out
        // "CZ means ValidCzIco" here was a second copy of it — and the one
        // that would not be updated when a third market arrives.
        $residency = TaxResidency::tryFrom((string) $input['country']);

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'surname' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', new EmailAvailable],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'country' => ['required', Rule::in(['SK', 'CZ'])],
            'ico' => [
                'required',
                'string',
                // Nothing to check the format against until the country is a
                // residency we serve; the rule above is what reports that.
                ...($residency === null ? [] : [$taxSystems->forResidency($residency)->icoRule()]),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        /** @var class-string<User> $model */
        $model = config('auth.providers.users.model', User::class);

        // A console command starts bound to no account, and users is now
        // tenant-scoped too, so the insert itself needs an account bound to
        // satisfy WITH CHECK. The row about to be created is its own
        // account, so the id is generated here instead of left to HasUuids,
        // and TenantContext::for() keeps everything — the insert and the
        // listeners it triggers alike — bound to it, restoring whatever was
        // bound before (nothing) once done. See RegisterUserAction.
        $id = (string) (new $model)->newUniqueId();

        $user = TenantContext::for($id, function () use ($model, $id, $validator): User {
            $user = $model::query()->forceCreate([
                'id' => $id,
                ...$validator->validated(),
                'email_verified_at' => now(),
                'default_currency' => 'EUR',
                'locale' => 'sk',
                'is_vat_payer' => false,
                'tax_flat_rate' => 0,
            ]);

            event(new UserRegistered($user));

            return $user;
        });

        $this->info("User {$user->email} created.");

        return self::SUCCESS;
    }
}
