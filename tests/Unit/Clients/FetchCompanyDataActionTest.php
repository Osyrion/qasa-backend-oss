<?php

declare(strict_types=1);

use App\Modules\Clients\Application\Actions\FetchCompanyDataAction;
use App\Modules\Clients\Application\Contracts\CompanyRegistryClientInterface;
use App\Modules\Clients\Application\DTOs\CompanyRegistryData;
use App\Modules\Shared\Exceptions\DomainException;

function registryStub(?CompanyRegistryData $data): CompanyRegistryClientInterface
{
    return new class($data) implements CompanyRegistryClientInterface
    {
        public function __construct(private readonly ?CompanyRegistryData $data) {}

        public function fetchByIco(string $ico): ?CompanyRegistryData
        {
            return $this->data;
        }
    };
}

it('routes to the client matching the country code', function (): void {
    $czData = new CompanyRegistryData('ACME s.r.o.', '27074358', 'CZ27074358', 'CZ27074358', 'Testovací 1', 'Praha', '110 00', 'CZ');

    $action = new FetchCompanyDataAction([
        'CZ' => registryStub($czData),
        'SK' => registryStub(null),
    ]);

    expect($action->execute('cz', '27074358'))->toBe($czData);
});

it('throws for an unmapped country', function (): void {
    $action = new FetchCompanyDataAction(['CZ' => registryStub(null)]);

    expect(fn () => $action->execute('FR', '123'))->toThrow(DomainException::class);
});

it('throws when the register returns nothing', function (): void {
    $action = new FetchCompanyDataAction(['CZ' => registryStub(null)]);

    expect(fn () => $action->execute('CZ', '00000000'))->toThrow(DomainException::class);
});
