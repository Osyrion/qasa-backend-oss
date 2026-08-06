<?php

declare(strict_types=1);

namespace App\Modules\Auth\Application\Actions;

use App\Modules\Auth\Application\DTOs\UpdateProfileData;
use App\Modules\Auth\Domain\Events\UserIcoChanged;
use App\Modules\Auth\Domain\Models\User;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Domain\Models\Quote;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Shared\Enums\VatStatus;
use App\Modules\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class UpdateProfileAction
{
    /**
     * @throws DomainException
     * @throws Throwable
     */
    public function execute(User $user, UpdateProfileData $data): User
    {
        $this->assertReauthenticated($user, $data);

        return DB::transaction(function () use ($user, $data): User {
            $vatStatus = $this->resolveVatStatus($user, $data);
            $this->assertIcoEditable($user, $data);

            $updateData = array_filter([
                'title' => $data->title,
                'name' => $data->name,
                'surname' => $data->surname,
                'email' => $data->email,
                'phone' => $data->phone,
                'ico' => $data->ico,
                'dic' => $data->dic,
                'vat_status' => $vatStatus?->value,
                // vat_status itself always has a DB default, so this is the
                // only way to tell "explicitly confirmed" from "never
                // touched" — drives the onboarding setup checklist.
                'vat_status_confirmed_at' => $vatStatus !== null ? now() : null,
                'tax_flat_rate' => $data->tax_flat_rate,
                'default_currency' => $data->default_currency->value,
                'invoice_prefix' => $data->invoice_prefix,
                'locale' => $data->locale,
                // invoice_number_mask / invoice_number_start are set below,
                // outside the filter, so an explicit "" can clear them back
                // to the legacy default — array_filter would otherwise drop
                // a null value indistinguishably from a field left unsent.
                // "country" (tax residency) is intentionally absent — it is
                // set exactly once, via Taxation's complete-residency, and
                // is immutable after (see User::booted()).
                'company_name' => $data->company_name,
                'address' => $data->address,
                'city' => $data->city,
                'postal_code' => $data->postal_code,
                'vat_id' => $data->vat_id,
                'website' => $data->website,
                'invoice_footer_text' => $data->invoice_footer_text,
                'clockify_api_key' => $data->clockify_api_key,
                'clockify_workspace_id' => $data->clockify_workspace_id,
                'ai_extraction_enabled' => $data->ai_extraction_enabled,
                'auto_remind_enabled' => $data->auto_remind_enabled,
                'auto_remind_after_days' => $data->auto_remind_after_days,
                'auto_remind_max_count' => $data->auto_remind_max_count,
                'overdue_digest_enabled' => $data->overdue_digest_enabled,
                'vat_filing_frequency' => $data->vat_filing_frequency?->value,
                'tax_filing_reminder_enabled' => $data->tax_filing_reminder_enabled,
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

            // Capture before the update: a changed e-mail must be re-verified.
            $emailChanged = $data->email !== null && $data->email !== $user->email;

            $oldIco = $user->ico;
            $user->update($updateData);

            // email_verified_at is not mass-assignable, so reset it explicitly
            // — the new address must not inherit the old "verified" state.
            if ($emailChanged) {
                $user->forceFill(['email_verified_at' => null])->save();
            }

            if (array_key_exists('ico', $updateData) && $oldIco !== $user->ico) {
                event(new UserIcoChanged($user, $oldIco, $user->ico));
            }

            // A password change invalidates other sessions/tokens, exactly like
            // the reset-password flow — but keep the caller's own current token
            // so they are not logged out mid-request.
            if ($data->password !== null) {
                $currentToken = $user->currentAccessToken();
                $currentTokenId = $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null;

                $user->tokens()
                    ->when($currentTokenId !== null, fn ($query) => $query->whereKeyNot($currentTokenId))
                    ->delete();
            }

            return $user->fresh() ?? $user;
        });
    }

    /**
     * Re-authentication gate for the two account-takeover-sensitive fields.
     * Changing the e-mail or the password requires the current password, so a
     * merely-borrowed token can't silently pivot the account to an attacker
     * (change e-mail, then take over via password reset). Google-only accounts
     * have no password to confirm against — they re-authenticate via the
     * provider — so the check is skipped for them.
     *
     * @throws DomainException
     */
    private function assertReauthenticated(User $user, UpdateProfileData $data): void
    {
        $changingEmail = $data->email !== null && $data->email !== $user->email;
        $changingPassword = $data->password !== null;

        if ((! $changingEmail && ! $changingPassword) || $user->password === null) {
            return;
        }

        if ($data->current_password === null
            || ! Hash::check($data->current_password, (string) $user->password)
        ) {
            throw DomainException::because(__('auth.invalid_password'));
        }
    }

    /**
     * Anti-fraud: IČO may only be corrected while the account has never
     * issued a document — once one exists, it's locked (every change,
     * before or after, is still audited via UserIcoChanged).
     *
     * @throws DomainException
     */
    private function assertIcoEditable(User $user, UpdateProfileData $data): void
    {
        if ($data->ico === null || $data->ico === $user->ico) {
            return;
        }

        $ownerId = $user->accountOwnerId();

        $hasDocuments = Invoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists()
            || Quote::withoutGlobalScope('user')->where('user_id', $ownerId)->exists()
            || SupplierInvoice::withoutGlobalScope('user')->where('user_id', $ownerId)->exists();

        if ($hasDocuments) {
            throw DomainException::because(__('taxation.ico_locked'));
        }
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
