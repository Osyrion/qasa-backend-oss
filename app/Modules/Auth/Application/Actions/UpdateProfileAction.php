<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class UpdateProfileAction
{
    /**
     * @throws Throwable
     */
    public function execute(User $user, UpdateProfileData $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $vatStatus = $this->resolveVatStatus($user, $data);

            $updateData = array_filter([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'phone' => $data->phone,
                'ico' => $data->ico,
                'dic' => $data->dic,
                'vat_status' => $vatStatus?->value,
                'tax_flat_rate' => $data->tax_flat_rate,
                'default_currency' => $data->default_currency->value,
                'invoice_prefix' => $data->invoice_prefix,
                'locale' => $data->locale,
                // invoice_number_mask / invoice_number_start are set below,
                // outside the filter, so an explicit "" can clear them back
                // to the legacy default — array_filter would otherwise drop
                // a null value indistinguishably from a field left unsent.
                'country' => $data->country,
                'address' => $data->address,
                'city' => $data->city,
                'postal_code' => $data->postal_code,
                'vat_id' => $data->vat_id,
                'website' => $data->website,
                'invoice_footer_text' => $data->invoice_footer_text,
                'overdue_reminder_days' => $data->overdue_reminder_days,
                'auto_remind_enabled' => $data->auto_remind_enabled,
                'auto_remind_max' => $data->auto_remind_max,
                'auto_remind_interval_days' => $data->auto_remind_interval_days,
                'clockify_api_key' => $data->clockify_api_key,
                'clockify_workspace_id' => $data->clockify_workspace_id,
            ], fn ($value) => $value !== null);

            if ($data->invoice_number_mask_provided) {
                $updateData['invoice_number_mask'] = $data->invoice_number_mask;
            }

            if ($data->invoice_number_start_provided) {
                $updateData['invoice_number_start'] = $data->invoice_number_start;
            }

            if ($data->quote_number_mask_provided) {
                $updateData['quote_number_mask'] = $data->quote_number_mask;
            }

            if ($data->quote_number_start_provided) {
                $updateData['quote_number_start'] = $data->quote_number_start;
            }

            // Hash password if provided
            if ($data->password !== null) {
                $updateData['password'] = Hash::make($data->password);
            }

            $user->update($updateData);

            return $user->fresh();
        });
    }

    /**
     * vat_status wins whenever both fields are sent. The legacy boolean alone
     * can't express the "identified" status, so applying it to an identified
     * user would silently downgrade/upgrade them — reject instead.
     *
     * @throws DomainException
     */
    private function resolveVatStatus(User $user, UpdateProfileData $data): ?VatStatus
    {
        if ($data->vat_status !== null) {
            return $data->vat_status;
        }

        if ($data->is_vat_payer === null) {
            return null;
        }

        if ($user->vat_status === VatStatus::Identified) {
            throw DomainException::validation(__('auth.legacy_vat_payer_conflicts_with_identified'));
        }

        return VatStatus::fromLegacyBool($data->is_vat_payer);
    }
}
