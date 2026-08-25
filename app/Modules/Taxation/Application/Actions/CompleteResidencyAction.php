<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Application\Actions;

use App\Modules\Auth\Domain\Events\TaxResidencyCompleted;
use App\Modules\Auth\Domain\Events\UserIcoChanged;
use App\Modules\Shared\Domain\Contracts\Account;
use App\Modules\Shared\Domain\Contracts\ProvidesSupplierProfile;
use App\Modules\Shared\Enums\Currency;
use App\Modules\Shared\Exceptions\DomainException;
use App\Modules\Taxation\Application\DTOs\CompleteResidencyData;
use App\Modules\Taxation\Domain\Enums\TaxResidency;
use App\Modules\Taxation\Domain\Rules\ValidCzDic;
use App\Modules\Taxation\Domain\Rules\ValidCzIco;
use App\Modules\Taxation\Domain\Rules\ValidSkDic;
use App\Modules\Taxation\Domain\Rules\ValidSkIco;
use App\Modules\Taxation\Domain\Rules\ValidSkVatId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class CompleteResidencyAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(Account&Model&ProvidesSupplierProfile $user, CompleteResidencyData $data): void
    {
        if ($user->supplierProfile()->country !== null) {
            throw DomainException::because(__('taxation.residency_already_set'));
        }

        $residency = TaxResidency::from($data->country);

        $this->assertValidIco($residency, $data->ico);
        $this->assertValidDic($residency, $data->dic);
        $this->assertValidVatId($residency, $data->vat_id);

        DB::transaction(function () use ($user, $data, $residency): void {
            $user->update([
                'country' => $residency->value,
                'ico' => $data->ico,
                'dic' => $data->dic,
                'vat_id' => $data->vat_id,
                'company_name' => $data->company_name,
                'address' => $data->address,
                'city' => $data->city,
                'postal_code' => $data->postal_code,
                // A suggestion only — mena nie je viazaná na rezidenciu, the
                // user may change it freely afterward via the profile.
                'default_currency' => ($residency === TaxResidency::Cz ? Currency::CZK : Currency::EUR)->value,
            ]);

            event(new UserIcoChanged($user, null, $data->ico));
            event(new TaxResidencyCompleted($user));
        });
    }

    /**
     * @throws DomainException
     */
    private function assertValidIco(TaxResidency $residency, string $ico): void
    {
        $valid = match ($residency) {
            TaxResidency::Sk => ValidSkIco::isValid($ico),
            TaxResidency::Cz => ValidCzIco::isValid($ico),
        };

        if (! $valid) {
            throw DomainException::because(__('taxation.invalid_ico_format'));
        }
    }

    /**
     * @throws DomainException
     */
    private function assertValidDic(TaxResidency $residency, ?string $dic): void
    {
        if ($dic === null) {
            return;
        }

        $valid = match ($residency) {
            TaxResidency::Sk => ValidSkDic::isValid($dic),
            TaxResidency::Cz => ValidCzDic::isValid($dic),
        };

        if (! $valid) {
            throw DomainException::because(__('taxation.invalid_dic_format'));
        }
    }

    /**
     * @throws DomainException
     */
    private function assertValidVatId(TaxResidency $residency, ?string $vatId): void
    {
        if ($vatId === null) {
            return;
        }

        // CZ has no separate IČ DPH shape — same identifier as DIČ, see
        // ValidCzDic. Both cases also enforce "vat_id prefix matches
        // residency" implicitly, since the prefix is baked into the regex.
        $valid = match ($residency) {
            TaxResidency::Sk => ValidSkVatId::isValid($vatId),
            TaxResidency::Cz => ValidCzDic::isValid($vatId),
        };

        if (! $valid) {
            throw DomainException::because(__('taxation.invalid_vat_id_format'));
        }
    }
}
