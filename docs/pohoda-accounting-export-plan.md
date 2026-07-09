# Export pre účtovníctvo — Pohoda XML + CSV

> Implementačný plán · Qasa Core · modul **Invoicing**

## Kontext

OSVČ na konci roka/kvartálu potrebuje odovzdať účtovníčke dáta o **vydaných faktúrach**. Dnes appka vie doklad vyexportovať len ako **PDF na jednu faktúru** (`InvoicePdfController`) — hromadný, štruktúrovaný výstup pre účtovný softvér neexistuje. Ak používateľ nedostane dáta von v rozumnom formáte, appku nepoužije.

Cieľom je pridať **hromadný export vydaných faktúr za obdobie** do dvoch formátov:

- **Stormware Pohoda XML** (`dataPack`) — v CZ/SK najrozšírenejší účtovný import.
- **Štruktúrované CSV** — univerzálne, otvoriteľné v Exceli / tabuľkovom procesore.

**Potvrdené rozhodnutia:**

- **Rozsah dát:** len **vydané faktúry** (`invoices`). Výdavky (`expenses` v TimeTracking) sú ploché záznamy bez DPH rozpisu, dodávateľa a čísla dokladu — do Pohoda „prijatých faktúr“ sa čisto nenamapujú, preto **zatiaľ mimo** (dorobí sa neskôr, keď bude bohatší model).
- **Obdobie:** filtrovateľné, so **voliteľným základom dátumu** — `issued_at` (vystavenie) alebo `taxable_supply_at` (DUZP). Parameter `period_basis` (`issue`|`tax`), default `issue`. `date_from`/`date_to` povinné.
- **Typy dokladov:** **filtrovateľné parametrom** `types[]`. Default = daňové doklady (`invoice`, `credit_note`, `storno`) — **proforma a drafty vylúčené** (proforma nie je daňový doklad). Draft sa nikdy neexportuje.
- **Formát:** dva samostatné endpointy (`.../export/pohoda`, `.../export/csv`), zdieľajú rovnaký filter DTO — presne podľa vzoru `pdf/download` vs `pdf/preview`.

**Zdroj pravdy pre dáta:** frozen snapshoty na faktúre (`supplier_snapshot`, `client_snapshot`, `bank_account_snapshot`) — aby sa export zhodoval s vystaveným PDF. VAT rozpis sa **nepočíta zo súčtu položiek**, ale cez `VatRecapCalculator::recap()` (český spôsob, per-sadzba), pre cudziu menu `czkRecap()`.

---

## Návrh

Architektúra kopíruje existujúci PDF export: **Controller (Presentation) → builder Service (Application/Domain) → `response($body, 200, [Content-Disposition])`**. Žiadny `streamDownload`, drží sa vzoru `InvoicePdfController`.

### 1. Filter DTO — `InvoiceExportData` (Application/DTOs)

Nový `app/Modules/Invoicing/Application/DTOs/InvoiceExportData.php` (Spatie `Data`, `validateAndCreate` vzor podľa CLAUDE.md). Polia:

- `CarbonImmutable $dateFrom`, `CarbonImmutable $dateTo` — **povinné** (`required|date`, `date_to >= date_from`).
- `ExportPeriodBasis $periodBasis = ExportPeriodBasis::Issue` — enum `issue|tax`.
- `array<InvoiceType> $types` — default = `[Invoice, CreditNote, Storno]`; validácia `in:invoice,credit_note,storno` (proforma vylúčená). Rieši `types[]` query param.

Nový enum `app/Modules/Invoicing/Domain/Enums/ExportPeriodBasis.php` (`Issue = issue`, `Tax = tax`) s metódou `column(): string` → `issued_at` / `taxable_supply_at`.

### 2. Výber dát — rozšíriť repozitár

`app/Modules/Invoicing/Application/Contracts/InvoiceRepositoryInterface.php` + `EloquentInvoiceRepository.php`:

Nová metóda `forExport(string $userId, InvoiceExportData $filter): Collection<int, Invoice>`:

- Scope `forUser($userId)`, **vylúčiť draft** (`whereNot('status', 'draft')`), `whereIn('type', $filter->types)`.
- Obdobie na **`$filter->periodBasis->column()`**: `whereBetween(column, [dateFrom, dateTo])`. Pri `tax` základe navyše `whereNotNull('taxable_supply_at')` (proforma/nezdaniteľné vypadnú prirodzene).
- Eager-load `items`, `client`, `payments`, `bankAccount` (proti N+1 — dôležité pri celoročnom exporte).
- Zoradiť `issued_at asc, invoice_number asc`. **Nie je stránkované** — účtovník chce celé obdobie naraz.

*Reuse:* logika date-range už existuje v `paginate()` (riadky ~74-80), len sa zovšeobecní na voliteľný stĺpec.

### 3. Pohoda XML builder — `PohodaXmlBuilder` (Domain/Services)

Nový `app/Modules/Invoicing/Domain/Services/PohodaXmlBuilder.php`, `final`. **Buduje cez `DOMDocument`** (nie konkatenáciou reťazcov ani Blade) — garantuje well-formed výstup, korektné XML escapovanie atribútov aj textu a správne UTF-8 kódovanie (bezpečnostné pravidlo CLAUDE.md — žiadne skladanie XML z inputu ručne).

Štruktúra Stormware Pohoda `dataPack` (namespaces `dat:`, `inv:`, `typ:`):

- Root `<dat:dataPack version="2.0" ...>` s `ico` dodávateľa (zo `supplier_snapshot['ico']`).
- Pre každú faktúru `<dat:dataPackItem>` → `<inv:invoice version="2.0">`:
  - `<inv:invoiceHeader>`: `invoiceType` = `issuedInvoice` (pre credit_note/storno → `issuedCreditNotice` resp. vratka — mapované cez `InvoiceType`), `number/numberRequested`, `symVar` (variable_symbol), `date` (issued_at), `dateTax` (taxable_supply_at), `dateDue` (due_at), `partnerIdentity` z `client_snapshot` (`company/name, city, street, zip, ico, dic`), `paymentType`, `text`/`note`.
  - `<inv:invoiceDetail>`: pre každú položku `<inv:invoiceItem>` — `text`, `quantity`, `unit`, `rateVAT` (mapované, viď nižšie), `homeCurrency`/`foreignCurrency` `unitPrice`.
  - `<inv:invoiceSummary>`: `homeCurrency` s `priceNone/priceLow/priceHigh` + `priceHighVAT`... **z `VatRecapCalculator::recap()`**; pre EUR/USD `foreignCurrency` blok (`currency/ids`, `rate` = `exchange_rate_snapshot`, `amount`) a home hodnoty z `czkRecap()`.

**Mapovanie DPH sadzby → Pohoda `rateVAT`** (`none|low|high|third`): nový malý mapper `PohodaVatRate` s prahmi z configu (viď §6). `0 → none`; ostatné podľa configu `high`/`low`/`third`. Vždy sa emituje aj `percentVAT` s presnou číselnou sadzbou, aby Pohoda mala exaktné %. **Pozn.:** default prahy sú CZ (high=21, low=12); pre SK sadzby si používateľ upraví config — flaguje sa v dokumentácii.

**Cudzia mena:** CZK → `homeCurrency`. EUR/USD → `foreignCurrency` (mena + `rate` z `exchange_rate_snapshot`), home ekvivalent z `czkRecap()`. Ak by pri cudzej mene chýbal snapshot (nemalo by pri issued nastať), degradovať na home s rate=1 a nezhodiť export.

Verejné API: `build(iterable<Invoice> $invoices): string` (vráti XML reťazec).

### 4. CSV builder — `InvoiceCsvBuilder` (Application/Services)

Nový `app/Modules/Invoicing/Application/Services/InvoiceCsvBuilder.php`. Použije **`League\Csv\Writer::createFromString()`** (knižnica už je v projekte, dnes len na import). `setDelimiter(';')` + `setOutputBOM(ByteSequence::BOM_UTF8)` — kompatibilita s Excelom v CZ/SK locale.

**Granularita: jeden riadok = jedna faktúra** (hlavičková úroveň — univerzálne pre tabuľkový procesor). Stĺpce (hlavička lokalizovaná cez `__('invoicing.export.*')`):

`invoice_number, type, status, issued_at, taxable_supply_at, due_at, client_name, client_ico, client_dic, client_vat_id, currency, subtotal, discount_amount, vat_amount, total, paid_amount, balance, variable_symbol, exchange_rate`

- Tax IDs a meno klienta zo `client_snapshot` (fallback na `client` reláciu).
- `paid_amount` = `payments->sum('amount')`, `balance` = `Invoice::balance()`, `vat_amount` = `VatRecapCalculator::vatAmount()`.
- Sumy ako desatinné čísla v mene faktúry (bez menového symbolu).

Verejné API: `build(iterable<Invoice> $invoices): string`.

*(Detailný per-položkový CSV je možné doplniť neskôr — teraz stačí hlavičková úroveň pre „bežný tabuľkový procesor“.)*

### 5. Controller + routy

Nový `app/Modules/Invoicing/Presentation/Controllers/InvoiceExportController.php` (vzor `InvoicePdfController`, `use AuthorizesRequests`, `OA\` anotácie):

- Konštruktor injektuje `InvoiceRepositoryInterface`, `PohodaXmlBuilder`, `InvoiceCsvBuilder`.
- `pohoda(Request)` a `csv(Request)`:
  1. `$this->authorize('viewAny', Invoice::class);`
  2. `$filter = InvoiceExportData::validateAndCreate($request->all());`
  3. `$invoices = $repo->forExport($user->accountOwnerId(), $filter);`
  4. `return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8' | 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="..."'])`.
- Filename: `pohoda_{dateFrom}_{dateTo}.xml` / `faktury_{dateFrom}_{dateTo}.csv` (sanitizované).

**Routy** — `routes/invoicing.php`, do existujúcej `api/v1` + `auth:sanctum` skupiny (mimo `invoices/{invoice}` prefixu, sú to kolekcia-level akcie), **pred** `Route::apiResource('invoices', ...)` aby `invoices/export` nekolidoval s `invoices/{invoice}`:

```php
Route::get('invoices/export/pohoda', [InvoiceExportController::class, 'pohoda'])->name('invoices.export.pohoda');
Route::get('invoices/export/csv', [InvoiceExportController::class, 'csv'])->name('invoices.export.csv');
```

### 6. Konfigurácia mapovania DPH

Nový `config/pohoda.php` (alebo sekcia v `config/qasa.php`): prahy sadzieb pre `rateVAT` mapovanie (`high`, `low` číselné %) a default `paymentType`. Retrieved cez `config()` (žiadne hardcoding — CLAUDE.md).

### 7. Autorizácia

`app/Modules/Invoicing/Presentation/Policies/InvoicePolicy.php` — export viazať na existujúce `viewAny` (používateľ vidí len vlastné doklady vďaka `HasUserScope` + `accountOwnerId()` scope v repozitári). Ak `viewAny` neexistuje, doplniť (vráti `true` pre autentikovaného — scoping rieši repozitár).

### 8. Lokalizácia

`lang/en/invoicing.php` + `lang/sk/invoicing.php` — pridať kľúče `export.*` (CSV hlavičky, prípadné chybové hlášky). EN aj SK. Žiadne inline reťazce v kóde.

---

## Kritické súbory

| Súbor | Zmena |
|---|---|
| `Invoicing/Application/DTOs/InvoiceExportData.php` | **nový** – filter (obdobie, period_basis, types) |
| `Invoicing/Domain/Enums/ExportPeriodBasis.php` | **nový** – `issue`/`tax` → stĺpec |
| `Invoicing/Application/Contracts/InvoiceRepositoryInterface.php` | signatúra `forExport()` |
| `Invoicing/Infrastructure/Repositories/EloquentInvoiceRepository.php` | `forExport()` (reuse date-range) |
| `Invoicing/Domain/Services/PohodaXmlBuilder.php` | **nový** – Pohoda `dataPack` cez `DOMDocument` |
| `Invoicing/Domain/Services/PohodaVatRate.php` | **nový** – mapovanie % → `rateVAT` |
| `Invoicing/Application/Services/InvoiceCsvBuilder.php` | **nový** – `League\Csv\Writer`, `;` + BOM |
| `Invoicing/Presentation/Controllers/InvoiceExportController.php` | **nový** – `pohoda()` + `csv()` |
| `Invoicing/Presentation/Policies/InvoicePolicy.php` | `viewAny` (ak chýba) |
| `routes/invoicing.php` | 2 routy `invoices.export.*` (pred apiResource) |
| `config/pohoda.php` | **nový** – DPH prahy, paymentType |
| `lang/{en,sk}/invoicing.php` | kľúče `export.*` |

*Reuse:* `VatRecapCalculator` / `czkRecap` (DPH rozpis + CZK), frozen snapshoty na `Invoice`, `Invoice::balance()`, `Currency` enum, `InvoiceType` (mapovanie typu dokladu), `HasUserScope`/`accountOwnerId()` (tenancy), vzor `response(...Content-Disposition...)` z `InvoicePdfController`.

---

## Verifikácia

1. **Statická analýza:** `composer phpstan` (level 8) + `vendor/bin/pint --dirty`.
2. **Unit testy:**
   - `PohodaVatRate` — 0 → `none`, 21 → `high`, 12 → `low` (podľa configu).
   - `PohodaXmlBuilder` — výstup je well-formed (`new DOMDocument; loadXML()` bez chyby), obsahuje `<inv:invoice>` per faktúru, správny `dateTax`, `symVar`, per-sadzba summary; EUR faktúra má `foreignCurrency` s `rate` zo snapshotu.
   - `InvoiceCsvBuilder` — počet riadkov = počet faktúr + hlavička, `;` delimiter, UTF-8 BOM, `client_dic` zo snapshotu, `balance`/`paid_amount` správne.
3. **Feature testy** (`tests/Feature/Invoicing/`):
   - `GET /api/v1/invoices/export/csv?date_from&date_to` → 200, `Content-Type text/csv`, `Content-Disposition attachment`; draft a proforma **nie sú** vo výstupe; cudzí používateľ nevidí cudzie faktúry.
   - `period_basis=tax` → filtruje podľa `taxable_supply_at`; `types[]=proforma` odmietnuté (422).
   - `GET .../export/pohoda` → 200, `application/xml`, validné XML, počet `<inv:invoice>` sedí.
   - Chýbajúce `date_from`/`date_to` → 422.
4. **Ručne / `/verify`:** vystaviť pár faktúr (CZK + EUR), zavolať oba exporty za obdobie roka; XML skúsiť naimportovať do Pohoda (alebo overiť proti Stormware XSD), CSV otvoriť v Exceli (CZ locale) — diakritika a stĺpce OK.

---

## Otvorené body na overenie pri implementácii

- **Presná Pohoda schéma:** verzie elementov `invoiceType` pre dobropis/storno a štruktúra `invoiceSummary` sa mierne líšia podľa verzie Pohoda XSD — pri implementácii overiť proti aktuálnemu Stormware XSD (element `rateVAT`, `foreignCurrency`).
- **DPH prahy CZ vs SK:** default config je CZ (21/12). SK používateľ (20/10/5) si upraví `config/pohoda.php` — zdokumentovať v README/CLAUDE kontexte.
