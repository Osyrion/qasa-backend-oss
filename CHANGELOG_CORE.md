# Changelog — Qasa Core

Všetky značajné zmeny tohto projektu sú dokumentované v tomto súbore.

Formát je založený na [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
a projekt sa drží [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Pridané (Added)

#### Prepojenie kalendára so zákazkami
- `events.order_id` (nullable, FK `nullOnDelete`) — udalosť sa dá naviazať na zákazku; klient je dosiahnuteľný tranzitívne cez `order->client` (žiadny samostatný `client_id`, jeden zdroj pravdy)
- Cudzia zákazka pri vytvorení/úprave → `404` (globálny `HasUserScope` scope na `Order::findOrFail()`, rovnaký idiom ako pri časových záznamoch)
- `EventResource`: `order_id`, vnorený `order: {id, name, color, client_display_name}` (eager-load, bez N+1) a `effective_color` (vlastná farba, inak farba zákazky)
- `GET /events?order_id=` filter; `GET /events?order_id=&include=order_deadlines` primieša **virtuálne** celodenné položky za zákazky s `deadline` v rozsahu (stav `active`/`paused`) — nič sa nepersistuje, prepočítava sa pri každom requeste; export (ICS/CSV) ich nikdy nezahŕňa
- Zmazanie zákazky (soft delete) udalosť len odviaže — `DeleteOrderAction` explicitne nuluje `events.order_id` (DB FK `nullOnDelete` samo osebe nestačí, keďže ide o soft delete, nie skutočný `DELETE`)
- Migrácia: `2026_07_18_000005_add_order_id_to_events`

#### Setup checklist pre onboarding frontendu
- **`GET /api/v1/profile/setup-status`** (`SetupStatusController` → `SetupStatusService`, `auth:sanctum`, žiadna nová ability — rovnaký režim ako `GET /me`): sedem položiek `{key, done, optional}` — `billing_identity`, `vat_status`, `bank_account`, `first_client` (povinné), `invoice_numbering`, `logo`, `first_invoice` (nepovinné) — plus `completed` (všetky povinné hotové)
- Nový stĺpec `users.vat_status_confirmed_at` — `vat_status` má DB default, takže samo osebe nevie rozlíšiť „potvrdené" od „nikdy nevidené"; `UpdateProfileAction` ho nastaví, keď request obsahuje `vat_status` alebo `is_vat_payer`
- Migrácia: `2026_07_18_000004_add_vat_status_confirmed_at_to_users`

#### Denný digest vlastníkovi — faktúry novo po splatnosti
- `SendAutoRemindersCommand` po detekcii novo-po-splatnosti faktúr (marker `overdue_notified_at`) zozbiera per používateľ tie, ktorým marker práve pribudol, a po skončení jeho slučky pošle **jeden** `OverdueInvoicesDigestMail` (nie e-mail na faktúru — výpadok cronu/dovolenka by inak zaplavili inbox)
- Nový stĺpec `users.overdue_digest_enabled`, default **zapnuté** (na rozdiel od `auto_remind_enabled` ide o notifikáciu vlastníkovi, nie klientovi) — nastaviteľné cez `PUT /api/v1/auth/profile`
- Nezávislé od `auto_remind_enabled`; idempotencia zadarmo cez existujúci marker
- Migrácia: `2026_07_18_000003_add_overdue_digest_enabled_to_users`

#### OCR fallback pre skenované PDF (scan inbox)
- Nová `PdfRasterizer` (Infrastructure/Ocr): skenované PDF bez textovej vrstvy sa už neukončí rovno ako `failed` — stránky sa prevedú na PNG cez `pdftoppm` (poppler-utils, `Illuminate\Support\Facades\Process`, limit 5 strán/200 DPI, konfigurovateľné) a OCR-ujú rovnakým `thiagoalessio/tesseract_ocr` ako fotky (`ocr_engine = 'pdftoppm+tesseract'`)
- Chýbajúca binárka alebo zlyhaný proces sa nikdy nevyhodí ako výnimka — degraduje na pôvodné správanie (prázdny text → `failed`)
- `docker/php/Dockerfile`: pridaný `poppler-utils` (tesseract + jazykové balíky tam už boli)
- Nové config kľúče `invoicing.inbox.pdftoppm_path`/`ocr_max_pages`/`ocr_dpi`

#### Výdavky v štatistikách nákladov
- `RevenueCostAggregator` teraz počíta náklady zo `supplier_invoices` **aj** z evidovaných výdavkov (`Expense`) — celá suma bez rozpadu DPH, datovaná podľa vlastného `date`; cudzia mena bez zmrazeného kurzu ide cez existujúci `StatisticsCurrencyConverter` fallback
- `GET /statistics/overview` a `/statistics/tables` majú nové pole `assumptions` s upozornením na vedomé riziko dvojitého započítania (výdavok + prijatá faktúra za tú istú vec — aplikácia to nededupuje)
- Cache kľúč `stats:overview:*` verzovaný na `v2`, aby sa po nasadení nečítal starý výpočet

#### SEPA XML export platobných príkazov
- **`GET /api/v1/payment-orders/{id}/export/sepa`**: nový formát `SepaPain001Builder` (ISO 20022 pain.001.001.03) popri ABO/CSV/PDF — otvára platobné príkazy pre SK/EUR polovicu trhu (ABO je len CZK/tuzemské účty)
- Guardy: len EUR dávka, IBAN na platcovi aj na každom riadku (fallback cez `CzechIbanConverter` z tuzemského účtu dodávateľa), inak `422`
- `GET /api/v1/payment-orders/candidates` má novú skupinu `sepa_eligible` (EUR + IBAN na faktúre) popri `abo_eligible`/`other`
- XSD overené testom proti oficiálnej schéme (`tests/Fixtures/payment-order/pain.001.001.03.xsd`)

#### Vyúčtovanie proformy
- **`POST /api/v1/invoices/{invoice}/settle`**: zaplatená proforma → jedným krokom ostrá faktúra (`type = invoice`, vlastná číselná rada, `related_invoice_id` na proformu) s plnými položkami (žiadne záporné odpočítanie zálohy — tržba sa tak v štatistikách započíta správne raz), vystavená hneď cez `IssueInvoiceAction`, platby proformy prenesené ako nové `InvoicePayment` riadky, stav rovno `Paid` (`InvoicePaid` event/webhook)
- Nový stĺpec `invoices.settled_invoice_id` (na proforme) — idempotencia, druhé vyúčtovanie tej istej proformy vráti `422`
- Guardy: len typ `proforma`, len po úplnom zaplatení (čiastočné úhrady zatiaľ nevyúčtovávame)
- Migrácia: `2026_07_18_000001_add_settled_invoice_id_to_invoices`

#### Invoice inbox — manuálny upload
- **`POST /api/v1/invoice-inbox/upload`**: nahranie PDF/JPEG/PNG priamo cez API, spracované synchrónne (bez čakania na `qasa:invoices:scan-inbox`) a nezávisle od `invoice_inbox_enabled` — rovnaký MIME/veľkostný limit aj SHA-256 dedupe ako cron scan
- Zdieľaná logika spracovania jedného súboru extrahovaná do `ProcessInboxFileAction`; `ScanInboxAction` ju teraz volá per súbor, správanie cronu (presun do `processed/`, skip nepodporovaných) sa nemení
- Duplicita hashu → `422` (`invoicing.inbox.duplicate_file`), nepodporovaný typ/veľkosť → `422` (`invoicing.inbox.upload_invalid_file`)

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

### Zmenené (Changed)

#### ⚠️ Breaking: `invoice_number` sa prideľuje pri vystavení, nie pri vytvorení draftu
- `POST /api/v1/invoices` (a `.../corrective`, `.../settle`) teraz vracia draft s `invoice_number: null` a `variable_symbol: null` — obe polia sa naplnia až pri `POST /api/v1/invoices/{id}/status` (`sent`/`issued`), cez `IssueInvoiceAction`
- Dôvod: draft sa dá zmazať a dlho ležiaci draft predtým dostal číslo „svojej doby" namiesto poradia podľa skutočného vystavenia — diera/nechronologickosť v číselnom rade, ktorú SK/CZ účtovná prax nepripúšťa
- Existujúce drafty s už prideleným číslom (vytvorené pred touto zmenou) si číslo ponechávajú — nikdy sa neprepíše
- Migrácia: `2026_07_18_000002_make_invoice_number_nullable` (stĺpec `invoices.invoice_number` je teraz `nullable`)
- PDF draftu vykresľuje namiesto čísla lokalizovaný placeholder ("KONCEPT"/"DRAFT")

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
