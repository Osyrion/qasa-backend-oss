<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\DTOs\ContactPersonData;
use App\Modules\Clients\Domain\Models\Client;
use App\Modules\Clients\Domain\Models\ContactPerson;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class AddContactPersonAction
{
    private const int MAX_CONTACT_PERSONS = 5;

    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Client $client, ContactPersonData $data): ContactPerson
    {
        if (! $client->canHaveContactPersons()) {
            throw DomainException::because(__('clients.contact_persons_only_for_company'));
        }

        if ($client->contactPersons()->count() >= self::MAX_CONTACT_PERSONS) {
            throw DomainException::because(
                __('clients.max_contact_persons_reached', ['max' => self::MAX_CONTACT_PERSONS])
            );
        }

        return DB::transaction(function () use ($client, $data): ContactPerson {
            if ($data->is_primary) {
                $client->contactPersons()
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            /** @var ContactPerson */
            return $client->contactPersons()->create([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'phone' => $data->phone,
                'role' => $data->role,
                'is_primary' => $data->is_primary,
            ]);
        });
    }
}
