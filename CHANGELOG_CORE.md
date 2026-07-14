# Changelog

Všetky významné zmeny projektu sú dokumentované v tomto súbore.

Projekt dodržiava formát Keep a Changelog a Semantic Versioning.

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

### Added

- Customer/Vendor roles for clients.
- Configurable invoice numbering format.
- Pohoda XML export.
- CSV invoice export.
- Shared export filtering.
- Extended accounting export capabilities.

### Changed

- Improved export performance.
- Updated API documentation.

### Fixed

- Minor stability improvements.

---

## [1.0.0] - 2026-07-08

### Added

- Initial open-source release.
- Laravel 13 backend.
- Modular architecture.
- REST API.
- User authentication.
- Client management.
- Orders.
- Time tracking.
- Invoicing.
- PDF invoice generation.
- Dashboard.
- Integrations with public business registries.
