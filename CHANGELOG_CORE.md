# Changelog — Qasa Core

Všetky značajné zmeny tohto projektu sú dokumentované v tomto súbore.

Formát je založený na [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
a projekt sa drží [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.1.0] - 2026-07-09

### Pridané (Added)

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
