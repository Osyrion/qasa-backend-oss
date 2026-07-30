<?php

declare(strict_types=1);

namespace App\Modules\Clients\Application\Actions;

use App\Modules\Clients\Application\Contracts\VatValidatorInterface;
use App\Modules\Clients\Application\DTOs\VatValidationData;
use App\Modules\Shared\Exceptions\DomainException;

readonly class VerifyVatAction
{
    public function __construct(
        private VatValidatorInterface $validator,
    ) {}

    /**
     * @throws DomainException
     */
    public function execute(string $country, string $vatNumber): VatValidationData
    {
        $result = $this->validator->verify($country, $vatNumber);

        if ($result === null) {
            throw DomainException::because(__('clients.vat_check_unavailable'));
        }

        return $result;
    }
}
