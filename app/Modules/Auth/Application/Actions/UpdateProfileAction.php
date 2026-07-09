<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Domain\Models\User;
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
            $updateData = array_filter([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'phone' => $data->phone,
                'ico' => $data->ico,
                'dic' => $data->dic,
                'is_vat_payer' => $data->is_vat_payer,
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
                'clockify_api_key' => $data->clockify_api_key,
                'clockify_workspace_id' => $data->clockify_workspace_id,
            ], fn ($value) => $value !== null);

            if ($data->invoice_number_mask_provided) {
                $updateData['invoice_number_mask'] = $data->invoice_number_mask;
            }

            if ($data->invoice_number_start_provided) {
                $updateData['invoice_number_start'] = $data->invoice_number_start;
            }

            // Hash password if provided
            if ($data->password !== null) {
                $updateData['password'] = Hash::make($data->password);
            }

            $user->update($updateData);

            return $user->fresh();
        });
    }
}
