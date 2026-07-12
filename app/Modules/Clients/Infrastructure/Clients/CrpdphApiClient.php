<?php

declare(strict_types=1);

namespace App\Modules\Clients\Infrastructure\Clients;

use App\Modules\Clients\Application\Contracts\VatPayerAccountRegistryInterface;
use App\Modules\Clients\Application\DTOs\VatPayerAccountData;
use App\Modules\Clients\Application\DTOs\VatPayerAccountRegistryData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Czech register of VAT payers (CRPDPH, "nespolehlivý plátce") — MFČR web
 * service rozhraniCRPDPH. Plain SOAP 1.1 envelope over Http::withBody, no
 * ext-soap needed. Published accounts feed the § 109 liability check on
 * received invoices.
 */
class CrpdphApiClient implements VatPayerAccountRegistryInterface
{
    private const int CACHE_TTL = 86400;

    private const string SERVICE_PATH = '/adistc/axis2/services/rozhraniCRPDPH.rozhraniCRPDPHSOAP';

    public function lookup(string $dic): ?VatPayerAccountRegistryData
    {
        $dic = strtoupper(trim($dic));
        $dic = str_starts_with($dic, 'CZ') ? substr($dic, 2) : $dic;

        if (preg_match('/^\d{8,10}$/', $dic) !== 1) {
            return null;
        }

        return Cache::remember("crpdph:{$dic}", self::CACHE_TTL, function () use ($dic): ?VatPayerAccountRegistryData {
            try {
                $response = Http::baseUrl((string) config('services.crpdph.base_url'))
                    ->timeout(15)
                    ->retry(1, 300, throw: false)
                    ->withBody($this->requestEnvelope($dic), 'text/xml; charset=UTF-8')
                    ->withHeaders(['SOAPAction' => 'getStatusNespolehlivyPlatce'])
                    ->post(self::SERVICE_PATH);
            } catch (Throwable) {
                return null;
            }

            if (! $response->successful()) {
                return null;
            }

            return $this->parse($response->body());
        });
    }

    private function requestEnvelope(string $dic): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:roz="http://adis.mfcr.cz/rozhraniCRPDPH/">
              <soapenv:Body>
                <roz:StatusNespolehlivyPlatceRequest>
                  <roz:dic>{$dic}</roz:dic>
                </roz:StatusNespolehlivyPlatceRequest>
              </soapenv:Body>
            </soapenv:Envelope>
            XML;
    }

    private function parse(string $body): ?VatPayerAccountRegistryData
    {
        try {
            $xml = new SimpleXMLElement($body);
        } catch (Throwable) {
            return null;
        }

        // local-name() xpath sidesteps namespace prefixes, which differ
        // between the service's environments.
        $status = $xml->xpath('//*[local-name()="statusPlatceDPH"]');

        if ($status === null || $status === []) {
            return null;
        }

        $payer = $status[0];
        $flag = strtoupper((string) $payer['nespolehlivyPlatce']);

        if ($flag === 'NENALEZEN') {
            return new VatPayerAccountRegistryData(found: false, unreliable: false, accounts: []);
        }

        $accounts = [];

        foreach ($payer->xpath('.//*[local-name()="standardniUcet"]') ?: [] as $account) {
            $prefix = trim((string) $account['predcisli']);
            $number = trim((string) $account['cislo']);

            $accounts[] = new VatPayerAccountData(
                account_number: $prefix !== '' ? "{$prefix}-{$number}" : $number,
                bank_code: trim((string) $account['kodBanky']),
            );
        }

        foreach ($payer->xpath('.//*[local-name()="nestandardniUcet"]') ?: [] as $account) {
            $accounts[] = new VatPayerAccountData(
                iban: strtoupper(str_replace(' ', '', (string) $account['cislo'])),
            );
        }

        return new VatPayerAccountRegistryData(
            found: true,
            unreliable: $flag === 'ANO',
            accounts: $accounts,
        );
    }
}
