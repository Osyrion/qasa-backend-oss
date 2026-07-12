# Changelog — Qasa Core

Všetky značajné zmeny tohto projektu sú dokumentované v tomto súbore.

Formát je založený na [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
a projekt sa drží [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Pridané (Added)

#### Platobné príkazy — hromadná úhrada prijatých faktúr
- **Dávka (`PaymentOrder`)**: výber neuhradených prijatých faktúr → platobný príkaz so zmrazenými riadkami (`payment_order_items` — dodávateľ, účet, VS, suma); opakované stiahnutie je totožné s pôvodným exportom bez ohľadu na neskoršie zmeny faktúr
  - Jeden príkaz = jedna mena = mena účtu platcu (`BankAccount`); splatnosť v minulosti sa posunie na dnešok (`due_date_adjusted` v response)
  - „Predané k úhrade" je samostatná dimenzia (`supplier_invoices.handed_to_payment_at`), nie stav — pripravené pre budúce SaaS párovanie výpisov; voliteľný `mark_paid` prepne faktúry na `paid`
  - Endpointy: `GET /api/v1/payment-orders/candidates` (skupiny `abo_eligible`/`other`, `selectable` + lokalizovaný dôvod, `hide_handed`), `apiResource payment-orders` (index/store/show/destroy), `GET /api/v1/payment-orders/{id}/export/{abo|csv|pdf}`
  - Zmazanie dávky vynuluje `handed_to_payment_at` faktúram, ktoré nie sú v žiadnej inej živej dávke
- **Exporty**: ABO (KPC) „hromadný příkaz k úhradě" (`AboKpcBuilder`, len CZK + tuzemské účty, golden-file test), CSV (UTF-8 BOM, `;`), PDF prehľad (dompdf, landscape)
- **Účet príjemcu na prijatej faktúre**: `vendor_account_number`/`vendor_bank_code` (tuzemský tvar), `vendor_iban`/`vendor_bic`; `account_source` (`manual|ocr`); OCR parser scan inboxu extrahuje IBAN (mod-97 validácia) aj označený tuzemský účet a konverzia ich prenesie ako `ocr`
- **Overenie účtu proti CZ registru platiteľov DPH (CRPDPH, § 109 ručenie)**: `POST /api/v1/supplier-invoices/{id}/verify-account` — `CrpdphApiClient` za `VatPayerAccountRegistryInterface` (Clients modul, cache 1 deň, `CRPDPH_API_URL`); výsledok `published|unpublished|unreliable` sa ukladá na faktúru, pri nezhode response vypíše zverejnené účty; zmena účtu overenie zresetuje
- **QR platba per prijatá faktúra**: `GET /api/v1/supplier-invoices/{id}/payment-qr` (CZK → SPAYD, EUR → SEPA EPC; tuzemský účet sa deterministicky prepočíta na CZ IBAN cez `CzechIbanConverter`)
- **Zoznam prijatých faktúr**: filter `handed=1|0`, účtové polia a stav overenia v `SupplierInvoiceResource`
- Migrácie: `2026_07_17_000001_add_payment_fields_to_supplier_invoices`, `2026_07_17_000002_create_payment_orders_table`, `2026_07_17_000003_create_payment_order_items_table`

---

## [1.1.0] - 2026-07-09

### Pridané (Added)

#### Role klientov — Customer / Vendor flagy
- **Duálny model roly klienta**: každý klient môže mať alebo nemať rolu ako **zákazníka** (predvolene `true`) a/alebo **dodávateľa** (`is_customer` a `is_vendor` flagy)
  - `is_customer` — klient, od ktorého se vytvoria faktúry (bežná úroveň — prijímame platby)
  - `is_vendor` — dodávateľ/subdodávateľ (budúca funkcia — registrujeme vstupné faktúry)
  - Validácia: aspoň jedna rola musí byť nastavená (`clients.role_required` — domeno-domínová výnimka)
- **Filtrovanie v API**: endpointy `/api/v1/clients` a `/api/v1/clients/{client}` podporujú query parameter `role` (`customer` / `vendor` / `all`, default `customer`)
  - `GET /api/v1/clients?role=customer` — len aktívnych zákazníkov
  - `GET /api/v1/clients?role=vendor` — len dodávateľov
  - `GET /api/v1/clients?role=all` — všetci nezávisle na roli
- **Dátový model**:
  - Nový stĺpec v tabuľke `clients`: `is_customer BOOL DEFAULT TRUE`, `is_vendor BOOL DEFAULT FALSE`
  - Nové indexy: `(user_id, is_customer)` a `(user_id, is_vendor)` pre rýchle filtrovanie
  - Migracia: `2026_07_09_000001_add_customer_vendor_flags_to_clients.php`
- **DTO ClientData**: nové properties `is_customer` (default `true`), `is_vendor` (default `false`)
- **Model helpers**: `Client::isCustomer()`, `Client::isVendor()` — typované getters
- **Repository filtrovanie**: `EloquentClientRepository::paginate()` s kľúčom `role` v Query Builder filtri
- **OpenAPI dokumentácia**: aktualizované schémy na `GET /clients`, `POST /clients`, `PUT /clients/{client}` s novými polami

#### Konfigurácia číslovania faktúr
- **Invoice Number Mask** — používatelia si teraz môžu v profile nastaviť vlastnú masku číslovania faktúr namiesto pevného formátu
  - Podporované placeholdery: `{YYYY}` (rok), `{YY}` (2-znakový rok), `{MM}` (mesiac), `{DD}` (deň), `{N}`/`{NN}`/`{NNN}` atď. (sekvenčný token s variabilnou šírkou)
  - Príklady: `{YYYY}{NNNN}` → `20260001`, `{YY}01{NNN}` → `2601001`, `{NNNNN}` → `00001`
  - Konfigurovateľné počiatočné poradie (`invoice_number_start`) pre podporu migrácie z iných systémov
  - Nezávislé rady pre rôzne typy dokladov (Invoice, Proforma, CreditNote, Storno) — každý typ má vlastnú sekvenciu cez prefix
  - Spätná kompatibilita: null maska → doterajší formát `{prefix}-{YYYY}-{NNN}`
  - Nové stĺpce v tabuľke `users`: `invoice_number_mask` (varchar), `invoice_number_start` (int)
  - Nový value object `app/Modules/Invoicing/Domain/Services/InvoiceNumberMask` s metódami pre formátovanie, validáciu a extrahovanie poradí

#### Hromadný export faktúr pre účtovníkov
- **Pohoda XML Export** (`GET /api/v1/invoices/export/pohoda`) — export vydaných faktúr do formátu Stormware Pohoda dataPack
  - Formát XML s namespaces `dat:`, `inv:`, `typ:` (verzia 2.0)
  - Vylúčené: proforma a drafty; filtrovateľné podľa okresí, typu dokladu
  - Podpora DPH mapovania: `none|low|high|third` cez konfigurovaný `PohodaVatRate`
  - Zmrazené snapshoty (`supplier_snapshot`, `client_snapshot`, `bank_account_snapshot`) — export odráža stav faktúry pri vystavení
  - Podpora cudzích mien: `foreignCurrency` blok s kúrsom zo snapshoty; home ekvivalent cez `czkRecap()` (CZK prepočet)
  - Bezpečnosť: budovanie cez `DOMDocument` (nie ručné skladanie XML — zabránenie SSRF/injection)

- **CSV Export** (`GET /api/v1/invoices/export/csv`) — export vydaných faktúr do štruktúrovaného CSV
  - Stĺpce: `invoice_number`, `type`, `status`, `issued_at`, `taxable_supply_at`, `due_at`, `client_name`, `client_ico`, `client_dic`, `client_vat_id`, `currency`, `subtotal`, `discount_amount`, `vat_amount`, `total`, `paid_amount`, `balance`, `variable_symbol`, `exchange_rate`
  - UTF-8 BOM + `;` delimiter (Excel kompatibilita v CZ/SK locales)
  - Lokalizované hlavičky cez `__('invoicing.export.*')`
  - Granularita: jeden riadok = jedna faktúra (hlavičková úroveň)

- **Filter DTO** (`InvoiceExportData`) — zdieľaný filtrovací DTO pre oba exporty
  - `dateFrom`, `dateTo` (povinné)
  - `periodBasis`: `issue` (dátum vystavenia) alebo `tax` (DUZP), default `issue`
  - `types[]`: filtrovateľné typy dokladov (default: `invoice, credit_note, storno`)

- **Rozšírené repository metódy** — `InvoiceRepository::forExport()` s eager-loading (`items`, `client`, `payments`, `bankAccount`) — optimalizácia N+1 problémov pri celoročných exportoch

#### Integrácia Pohoda XML
- Nový config súbor `config/pohoda.php` s DPH prahmi pre mapovanie sadzieb
- `PohodaVatRate` service s konfigurovateľnými prahmi (`high`, `low`, `third`) — default CZ (21%, 12%), nastaviteľné pre SK/iné krajiny
- Príklad: `0 → none`, `15 → low`, `20 → high`, iné → `third`

### Zmenené (Changed)

- **Invoicing endpointy** — tabuľka endpointov v APLIKACIA.md rozšírená o nové export routy
- **Migracia s novými stĺpcami** — `2026_07_09_000001_add_invoice_number_mask_to_users.php`

### Opravené (Fixed)

### Deprecated

### Odstrániť v budúcnosti

---

## [1.0.0] - 2026-07-08

### Pridané (Added)

- Otvorenie ako open-source single-user edicia (Qasa Core)
- Kompletná dokumentácia architektúry v APLIKACIA.md
- Modularny monolit s 10+ modulmi: Auth, Team, Admin, Clients, Orders, Pricing, TimeTracking, Invoicing, Subscriptions, Saas, Shared
- Multi-tenancy podpora cez `HasUserScope` trait s `user_id` scoping
- Konfigurovateľná edícia (OSS vs. SaaS) cez `config/qasa.php`
- Sanctum token-based API autentifikácia (dva oddelené guardy: `sanctum` a `admin`)
- Sociálne prihlasovanie cez Google OAuth (Laravel Socialite)
- Role a oprávnenia (Spatie Laravel Permission) — len SaaS edícia
- Platby a predplatné (Laravel Cashier + Stripe) — len SaaS edícia
- Faktúry s komplexným stavovým automatom (draft → issued → sent → paid/credited/cancelled)
- Generovanie PDF faktúr (barryvdh/laravel-dompdf) s QR kódmi (SPAYD/SEPA EPC)
- Časové záznamy a výdavky s integrácou Clockify
- Opakovaná fakturácia (daily cron job)
- Integrácia ARES, RPO a VIES pre dáta o klientoch
- Dashboard s agregovanými dátami
- Admin back-office s audit logom a správou používateľov

### Technologický stack
- PHP 8.4, Laravel 13.0 (declare strict_types)
- PostgreSQL + Eloquent ORM
- PHPStan level 8 (cez Larastan)
- Pest PHP / PHPUnit pre testy
- L5-Swagger pre API dokumentáciu
