<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Application\Actions;

use App\Modules\Clients\Application\Contracts\VatPayerAccountRegistryInterface;
use App\Modules\Clients\Application\DTOs\VatPayerAccountData;
use App\Modules\Invoicing\Domain\Models\SupplierInvoice;
use App\Modules\Invoicing\Domain\Services\CzechIbanConverter;
use App\Modules\Shared\Exceptions\DomainException;

/**
 * Checks the invoice's stored vendor account against the accounts the
 * vendor published in the CZ VAT payer register (CRPDPH) — the § 109
 * VAT-liability guard before paying. The result is stored on the invoice;
 * a later account edit resets it.
 */
readonly class VerifySupplierAccountAction
{
    public function __construct(
        private VatPayerAccountRegistryInterface $registry,
        private CzechIbanConverter $ibanConverter,
    ) {}

    /**
     * @return array{result: string, verified_at: string, published_accounts: list<array{account_number: string|null, bank_code: string|null, iban: string|null}>}
     *
     * @throws DomainException
     */
    public function execute(SupplierInvoice $supplierInvoice): array
    {
        if (! $supplierInvoice->hasPaymentAccount()) {
            throw DomainException::because(__('invoicing.payment_account_missing'));
        }

        $dic = $this->czechDic($supplierInvoice);

        if ($dic === null) {
            throw DomainException::because(__('invoicing.account_verification_unavailable'));
        }

        $registry = $this->registry->lookup($dic);

        if ($registry === null) {
            throw DomainException::because(__('invoicing.account_verification_unavailable'));
        }

        $result = match (true) {
            $registry->unreliable => 'unreliable',
            $this->accountPublished($supplierInvoice, $registry->accounts) => 'published',
            default => 'unpublished',
        };

        $verifiedAt = now();

        $supplierInvoice->account_verified_at = $verifiedAt;
        $supplierInvoice->account_verification_result = $result;
        $supplierInvoice->save();

        return [
            'result' => $result,
            'verified_at' => (string) $verifiedAt->toISOString(),
            // On a mismatch the caller shows these for manual comparison.
            'published_accounts' => $result === 'published' ? [] : array_map(
                fn (VatPayerAccountData $account): array => [
                    'account_number' => $account->account_number,
                    'bank_code' => $account->bank_code,
                    'iban' => $account->iban,
                ],
                $registry->accounts,
            ),
        ];
    }

    /**
     * The CRPDPH register only covers Czech VAT payers.
     */
    private function czechDic(SupplierInvoice $supplierInvoice): ?string
    {
        $snapshot = $supplierInvoice->vendor_snapshot;

        $dic = isset($snapshot['dic']) && $snapshot['dic'] !== '' ? (string) $snapshot['dic'] : $supplierInvoice->client?->dic;
        $country = isset($snapshot['country']) && $snapshot['country'] !== '' ? (string) $snapshot['country'] : $supplierInvoice->client?->country;

        if ($dic === null || $dic === '' || strtoupper((string) $country) !== 'CZ') {
            return null;
        }

        return $dic;
    }

    /**
     * Compares in IBAN space — the stored account and every published one
     * are normalised to a CZ IBAN, so a domestic pair matches its own IBAN
     * form and vice versa.
     *
     * @param  list<VatPayerAccountData>  $published
     */
    private function accountPublished(SupplierInvoice $supplierInvoice, array $published): bool
    {
        $invoiceIbans = array_filter([
            $supplierInvoice->vendor_iban,
            $supplierInvoice->hasDomesticVendorAccount()
                ? $this->ibanConverter->toIban((string) $supplierInvoice->vendor_account_number, (string) $supplierInvoice->vendor_bank_code)
                : null,
        ]);

        foreach ($published as $account) {
            $publishedIban = $account->iban
                ?? ($account->account_number !== null && $account->bank_code !== null
                    ? $this->ibanConverter->toIban($account->account_number, $account->bank_code)
                    : null);

            if ($publishedIban !== null && in_array(strtoupper($publishedIban), array_map(strtoupper(...), $invoiceIbans), true)) {
                return true;
            }
        }

        return false;
    }
}
