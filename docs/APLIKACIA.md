# Qasa — dokumentácia aplikácie

> Tento dokument popisuje architektúru, moduly a funkčné toky backendu Qasa (Laravel 13, PHP 8.4).
> Cieľom je poskytnúť ucelený obraz o tom, ako aplikácia funguje — od registrácie používateľa
> až po vystavenie a zaplatenie faktúry.

## Obsah

1. [Prehľad a technologický stack](#1-prehľad-a-technologický-stack)
2. [Architektúra: modulárny monolit](#2-architektúra-modulárny-monolit)
3. [Open-core: OSS vs. SaaS edícia](#3-open-core-oss-vs-saas-edícia)
4. [Multi-tenancy: ako sú oddelené dáta jednotlivých účtov](#4-multi-tenancy-ako-sú-oddelené-dáta-jednotlivých-účtov)
5. [Role a oprávnenia](#5-role-a-oprávnenia)
6. [Modul Auth — registrácia, prihlásenie, profil](#6-modul-auth--registrácia-prihlásenie-profil)
7. [SaaS nadstavba — Team, Admin, Subscriptions, Saas](#7-saas-nadstavba--team-admin-subscriptions-saas)
8. [Modul Clients — klienti a kontaktné osoby](#8-modul-clients--klienti-a-kontaktné-osoby)
9. [Modul Orders — zákazky](#9-modul-orders--zákazky)
10. [Modul Pricing — sadzby a cenníky](#10-modul-pricing--sadzby-a-cenníky)
11. [Modul TimeTracking — časové výkazy a výdavky](#11-modul-timetracking--časové-výkazy-a-výdavky)
12. [Modul Calendar — udalosti, import/export, retencia](#12-modul-calendar--udalosti-importexport-retencia)
13. [Modul Invoicing — fakturácia, DPH, prijaté faktúry](#13-modul-invoicing--fakturácia-dph-prijaté-faktúry)
14. [Štatistiky](#14-štatistiky)
15. [Kompletný flow: od zákazky po zaplatenú faktúru](#15-kompletný-flow-od-zákazky-po-zaplatenú-faktúru)
16. [Bezpečnosť a spoločné konvencie](#16-bezpečnosť-a-spoločné-konvencie)
17. [Známe medzery / rozpracované časti](#17-známe-medzery--rozpracované-časti)
18. [Modul Integrations — token scopes a webhooky](#18-modul-integrations--token-scopes-a-webhooky)
19. [2FA (TOTP) pre bežných používateľov](#19-2fa-totp-pre-bežných-používateľov)

---

## 1. Prehľad a technologický stack

Qasa je API backend pre fakturačný/zákazkový systém (podobný napr. Fakturoid alebo Pohoda,
ale s dôrazom na sledovanie zákaziek a času). Postavený je na:

| Oblasť | Technológia |
|---|---|
| Jazyk / framework | PHP ^8.4, Laravel ^13.0 (`declare(strict_types=1)` povinné vo všetkých súboroch) |
| Databáza | PostgreSQL cez Eloquent ORM |
| Autentifikácia (API) | Laravel Sanctum (token-based, nie session cookies) |
| Sociálne prihlásenie | Laravel Socialite (Google) |
| Platby / predplatné | Laravel Cashier (Stripe) — **len SaaS repo** (`qasa_saas`), nie je v `composer.json` tohto repozitára |
| Role a oprávnenia | Spatie Laravel Permission — **len SaaS repo**; OSS jadro schvaľuje všetko cez `Gate::before` (kapitola 3) |
| DTO / validácia | Spatie Laravel Data |
| Query building | Spatie Laravel Query Builder |
| PDF | barryvdh/laravel-dompdf |
| QR platby | chillerlan/php-qrcode |
| CSV import | league/csv |
| ICS (kalendár) | sabre/vobject |
| Extrakcia textu z PDF (scan inbox) | smalot/pdfparser |
| OCR obrázkov (scan inbox) | thiagoalessio/tesseract_ocr |
| Testy | Pest PHP ^3.0 / PHPUnit ^11.3 |
| Statická analýza | PHPStan ^2.1 cez Larastan ^3.9 (level 8), Laravel Pint ^1.27. **Psalm sa nepoužíva.** |
| API dokumentácia | L5-Swagger (OpenAPI) |
| MCP server | `laravel/mcp` (`Mcp::local('qasa', QasaServer::class)`) |

Zoznam dependencies pravidelne kontroluj proti `composer.json` — je jediný zdroj pravdy.

---

## 2. Architektúra: modulárny monolit

Kód je rozdelený do modulov v `app/Modules/*`. Každý modul má rovnaké vrstvy:

```
app/Modules/{Modul}/
├── Domain/          # Entity (Eloquent modely), enumy, doménová logika, rozhrania repozitárov
├── Application/     # DTOs, Actions, Services, Commands, Handlers, eventy/listenery
├── Infrastructure/  # Implementácie externých služieb, Service Providery, HTTP klienti
└── Presentation/    # Controllery, FormRequesty, API resources, route súbory
```

Existujúce moduly v tomto repozitári (`app/Modules/*`) — jadro/OSS edícia:

- **Auth** — používatelia, prihlásenie, registrácia, profil
- **Clients** — klienti (zákazníci aj dodávatelia) a ich kontaktné osoby
- **Orders** — zákazky (projekty/kontrakty)
- **Pricing** — sadzby a cenníky služieb/produktov
- **TimeTracking** — časové záznamy (vrátane API pre CRUD/timer/CSV import), výdavky (s prílohou
  dokladu), kurzové lístky
- **Calendar** — udalosti, CSV/ICS import a export, retenčné mazanie
- **Invoicing** — faktúry, DPH (vrátane reverse charge), prijaté faktúry a scan inbox, platby,
  PDF, opakovaná fakturácia, cenové ponuky, štatistiky
- **Shared** — spoločné traits, enumy, politiky, výnimky

Moduly **Team, Admin, Subscriptions a Saas** v tomto repozitári **nie sú** — sú súčasťou
uzavretého multi-tenant nadstavbového repozitára `qasa_saas` (kapitola 7).

Každý modul má vlastný `*ServiceProvider` (`app/Modules/{Modul}/Infrastructure/Providers/`),
ktorý registruje routy, bindingy rozhraní a middleware. Laravel ich nenačítava automaticky cez
`bootstrap/app.php` (tam je len `web.php`, `console.php` a health-check) — moduly si routy
registrujú sami vo svojich provideroch.

---

## 3. Open-core: OSS vs. SaaS edícia

Qasa je navrhnutá ako **open-core** produkt — tento repozitár (`qasa_backend`) obsahuje jadro
(OSS edícia, single-user), zatiaľ čo multi-tenant SaaS nadstavba (tímy, platby, admin back-office
naplno) žije v samostatnom repozitári `qasa_saas`, ktorý sa nabalí navrch.

Riadi to `config/qasa.php` cez `qasa.edition` (`oss` alebo `saas`, env `QASA_EDITION`):

- **OSS edícia**: jeden používateľ = jeden účet. `AuthServiceProvider::boot()` použije
  `Gate::before(...)`, ktorý automaticky schváli každú "core" schopnosť z `AbilityCatalog`
  — v OSS teda nie je potrebné riešiť roly, oddelenie dát zabezpečuje len `HasUserScope`.
- **SaaS edícia**: modul `app/Modules/Saas` prepne `auth.providers.users.model` na rozšírený
  `Saas\Domain\Models\User` (dedí od jadrového `User`), zapne `Cashier::useCustomerModel()`
  a zaradí `RolePermissionSeeder` do zoznamu seederov.

Kľúčový trik: **všetky moduly pracujú s jadrovým `App\Modules\Auth\Domain\Models\User`**,
ale konfigurácia autentifikácie za behu podsunie SaaS podtriedu (`Saas\Domain\Models\User
extends CoreUser`), ktorá pridáva `Billable` (Cashier), `HasRoles` (Spatie) a `owner_id`.
Jadrový kód sa tak nikdy priamo neodkazuje na SaaS triedu — celý mechanizmus otvoreného jadra
stojí na tejto zámene modelu v konfigurácii.

---

## 4. Multi-tenancy: ako sú oddelené dáta jednotlivých účtov

Dôležité: **v databáze neexistuje samostatná tabuľka `Team`/`Account`**. Hranicu nájomcu (tenant)
tvorí priamo riadok v tabuľke `users`:

- V **core/OSS** edícii vráti `User::accountOwnerId()` vždy `$this->id` — každý používateľ
  vlastní len svoje dáta.
- V **SaaS** edícii `Saas\User::accountOwnerId()` vráti `$this->owner_id ?? $this->id`.
  `owner_id` je self-referenčný cudzí kľúč na tabuľku `users` — člen tímu je teda ten istý
  typ záznamu ako vlastník účtu, len má vyplnený `owner_id` smerujúci na vlastníka.

Každý doménový model, ktorý patrí k účtu, používa trait `App\Modules\Shared\Traits\HasUserScope`:
pridá globálny scope `user_id = auth()->user()->accountOwnerId()` a lokálny scope `forUser()`
pre explicitné dotazy (napr. vo frontách, kde `auth()` nie je dostupné). Tento trait používajú:
`Client`, `Order`, `Invoice`, `BankAccount`, `RecurringInvoiceTemplate`, `PriceList`, `Rate`,
`TimeEntry`, `Expense`.

Autorizácia kopíruje rovnaký princíp — každá Policy používa
`App\Modules\Shared\Policies\InteractsWithAccount::sameAccount()`, ktorá porovná
`$user->accountOwnerId()` s `user_id` daného záznamu. Vďaka tomu nie je možné cudzí záznam
(napr. faktúru iného účtu) ani vidieť, ani ho referencovať v cudzom kľúči (IDOR ochrana —
pozri kapitolu 16).

---

## 5. Role a oprávnenia

V **tomto repozitári (OSS jadro)** neexistuje žiadna tabuľka rolí ani Spatie Permission
balík — `AuthServiceProvider::boot()` použije `Gate::before(...)`, ktorý automaticky schváli
každú schopnosť z `App\Modules\Shared\Authorization\AbilityCatalog`
(`clients.view/manage`, `orders.view/manage`, `timetracking.view/manage`,
`invoices.view/manage`, `pricing.view/manage`, `reports.view`, `calendar.view/manage`,
`integrations.manage`) pre každého prihláseného používateľa. Policy triedy (`ClientPolicy`,
`OrderPolicy`, `TimeEntryPolicy`, ...) teda v OSS edícii vždy kontrolujú len
`sameAccount()` (kapitola 4) — samotné volanie `$user->can(...)` v OSS prejde vždy.

**Výnimka — scoped API tokeny**: pred týmto allow-all sa v `AuthServiceProvider::boot()`
registruje ešte skorší `Gate::before`, ktorý pre `PersonalAccessToken` bez danej schopnosti
vráti `false` (denial má prednosť pred allow-all aj pred budúcimi SaaS Spatie permissions).
Login/2FA tokeny majú `['*']` a nie sú týmto obmedzené — pozri kapitolu 18.

Skutočná matica rolí (`Owner/Admin/Member/Viewer`), tímové pozvánky, Spatie Permission a
samostatný `admin` guard s back-office rolami (`super_admin | support | billing`) sú
implementované **len v `qasa_saas`** — pozri kapitolu 7. V tomto repozitári preto niet
tried ako `TeamRole`, `PermissionCatalog`, `AdminUser` ani `RolePermissionSeeder`.

---

## 6. Modul Auth — registrácia, prihlásenie, profil

### Mechanizmus autentifikácie

Sanctum je nasadený v **token-based** režime (nie SPA session cookies) — `createToken()->plainTextToken`
sa vracia pri každom prihlásení. Existujú dva úplne oddelené guardy:

- `sanctum` (provider `users`) — bežní používatelia aplikácie.
- `admin` (provider `admin_users`) — administrátori back-office, samostatná tabuľka aj samostatné tokeny.

Tieto dva guardy sa **nikdy nesmú miešať** — nemajú spoločné oprávnenia ani spoločnú tabuľku.

### Endpointy (`api/v1/auth/*`)

| Metóda | URI | Účel | Middleware |
|---|---|---|---|
| POST | `/register` | registrácia (v OSS len ak `QASA_REGISTRATION=true`) | `throttle:10,1` |
| POST | `/login` | prihlásenie emailom/heslom | `throttle:10,1` |
| GET | `/google/redirect` | presmerovanie na Google OAuth | `throttle:10,1` |
| POST | `/google/callback` | spracovanie Google prihlásenia | `throttle:10,1` |
| POST | `/forgot-password` | odoslanie reset linku | `throttle:10,1` |
| POST | `/reset-password` | reset hesla | `throttle:10,1` |
| GET | `/email/verify/{id}/{hash}` | overenie e-mailu (podpísaný link) | `signed`, `throttle:10,1` |
| POST | `/logout` | zneplatnenie tokenu | `auth:sanctum` |
| GET | `/me` | profil prihláseného používateľa | `auth:sanctum` |
| PUT | `/profile` | úprava profilu | `auth:sanctum` |
| POST | `/profile/logo` | upload loga (na faktúry) | `auth:sanctum` |
| POST | `/email/verification-notification` | opätovné odoslanie overovacieho e-mailu | `auth:sanctum`, `throttle:6,1` |
| GET | `/api/v1/profile/export` | GDPR export všetkých dát účtu ako JSON (`AccountExportService`) | `auth:sanctum` |
| DELETE | `/api/v1/profile` | GDPR zmazanie účtu (soft-delete, zneplatnenie tokenov) | `auth:sanctum` |

Export a zmazanie účtu sú zámerne **len vlastníkove** (`accountOwnerId() === id`, inak 403
`auth.export_owner_only`) — v tomto jadre je to vždy pravda, hák slúži pre SaaS tímových
členov. Zmazanie vyžaduje heslo (účty s heslom) alebo reťazec `confirmation: "DELETE"`
(Google-only účty); dáta v DB pri zmazaní **ostávajú** (vystavené faktúry majú zákonnú
retenciu) — ide o vedomé minimum, nie plný purge/anonymizáciu (kapitola 17).

Rovnaký route súbor (`auth.php`) registruje aj `GET /api/v1/dashboard`
(`DashboardController` → `DashboardService`, middleware `auth:sanctum`) — agregované
súhrnné dáta pre prihláseného používateľa.

### Dátový model `User`

UUID primárny kľúč, `SoftDeletes`, `HasApiTokens`, `Notifiable`. Kľúčové polia: `title, name,
surname, email, phone, password` (nullable — `null` znamená účet len cez Google),
`google_id, avatar_path, color, ico, dic, is_vat_payer, tax_flat_rate` (0–80 %, 0 = skutočné
výdavky), `default_currency, invoice_prefix, locale, country, address, city, postal_code,
logo_path, vat_id, website, invoice_footer_text, clockify_api_key` (šifrované),
`clockify_workspace_id, overdue_reminder_days` (prah pre dashboard upomienky, predvolene 14
dní), `auto_remind_enabled` (predvolene vypnuté), `auto_remind_max` (1–10, predvolene 3),
`auto_remind_interval_days` (min. `invoicing.reminder_cooldown_days`, predvolene 7) — pozri
"Automatické upomienky" nižšie. `two_factor_secret` (šifrovaný, nepotvrdený secret),
`two_factor_recovery_codes` (šifrovaný json, hashované jednorazové kódy),
`two_factor_confirmed_at` (2FA aktívne len keď nie je null) — pozri kapitolu 18. Vzťahy:
`clients, orders, orderNotes, orderAttachments, timeEntries, expenses, exchangeRates,
invoices`.

### Krok za krokom

1. **Registrácia** (`RegisterUserAction`): vytvorí používateľa (rešpektuje SaaS zámenu modelu),
   vyvolá event `UserRegistered` (SaaS listener `AssignOwnerRole` mu pridelí rolu `owner`),
   následne sa **mimo** DB transakcie pošle overovací e-mail (`rescue(...)`) — zlyhanie mailu
   tak nikdy nezruší úspešnú registráciu.
2. **Prihlásenie** (`LoginAction`): vyhľadá podľa e-mailu, odmietne Google-only účty
   (`password === null`), overí heslo, **zmaže existujúci token s rovnakým názvom zariadenia**
   pred vydaním nového (zabraňuje hromadeniu tokenov z jedného zariadenia). Ak má účet
   potvrdené 2FA, namiesto tokenu vráti `LoginResult::twoFactorChallenge()` — pozri kapitolu 18.
3. **Google prihlásenie** (`LoginWithGoogleAction`): ak e-mail z Google účtu už existuje,
   len sa naň naviaže `google_id`; ak neexistuje, nový účet sa vytvorí len ak je
   `qasa.features.registration` zapnuté (inak `ValidationException` — "Účet s týmto e-mailom
   neexistuje."). Používa sa `Socialite::driver('google')->stateless()` (vhodné pre mobil/SPA).
   Rovnako ako pri bežnom prihlásení, potvrdené 2FA vynúti challenge namiesto tokenu.
4. **Reset hesla**: štandardný Laravel `Password` broker, `ResetPassword::createUrlUsing()`
   je prepísaný tak, aby smeroval na SPA (`{FRONTEND_URL}/reset-password?token=…`).
   Po úspešnom resete sa **zneplatnia všetky API tokeny** používateľa (vynútené opätovné
   prihlásenie na všetkých zariadeniach).
5. **Overenie e-mailu**: podpísaný (`signed`) link — nevyžaduje prihlásenie, dôkazom je HMAC
   podpis; kontrola prebieha manuálnym `hash_equals()` porovnaním hashu.

---

## 7. SaaS nadstavba — Team, Admin, Subscriptions, Saas

Moduly **Team** (tímy a pozvánky), **Admin** (administrátorský back-office s vlastným
`AdminUser` modelom a `admin` guardom), **Subscriptions** (dátový model predplatných plánov,
Stripe/Cashier) a **Saas** ("lepidlo" edície — migrácie `owner_id`, seedery rolí) **žijú
v uzavretom repozitári `qasa_saas`**, nie v tomto jadre. Tento repozitár (`qasa_core`) nesie
len to, čo jadro potrebuje na zámenu (kapitola 3):

- **Multi-tenancy hák**: `Saas\Domain\Models\User extends CoreUser`, `accountOwnerId()`
  vracia `owner_id ?? id` (kapitola 4).
- **Role a oprávnenia**: `Gate::before` v OSS jadre schvaľuje všetko z `AbilityCatalog`;
  Spatie Permission a matica rolí (`Owner/Admin/Member/Viewer`) sa reálne aplikujú až v SaaS
  repozitári (kapitola 5).
- **Guard `admin`**: úplne oddelený autentifikačný vesmír od bežného `sanctum` guardu —
  iná tabuľka (`admin_users`), žiadne prekrývanie oprávnení; implementácia je v SaaS repu.

V tomto repozitári teda **nie je** žiadny `TeamController`, `AdminController`,
`SubscriptionController`, checkout endpoint ani Stripe webhook — hľadaj ich v `qasa_saas`.

---

## 8. Modul Clients — klienti a kontaktné osoby

### Endpointy (`api/v1/clients/*`)

Plný REST (`apiResource`) pre `clients`, plus vnorené `clients/{client}/contact-persons`
(GET/POST/PUT/DELETE).

| Metóda | URI | Parametry | Účel |
|---|---|---|---|
| GET/POST/PUT/DELETE | `/clients[/{client}]` | `role=customer\|vendor\|all` (default `customer`) | CRUD |
| GET | `/clients/lookup?country=&ico=` | — | vytiahne firemné údaje z registra |
| GET | `/clients/verify-vat?country=&vat_id=` | — | overí platnosť IČ DPH cez VIES |
| GET/POST/PUT/DELETE | `/clients/{client}/contact-persons[/{contact}]` | — | vnorené kontaktné osoby |

Príklady filtrovania podľa roly:
- `GET /api/v1/clients` (default) — len zákazníci (`is_customer=true`)
- `GET /api/v1/clients?role=vendor` — len dodávateľi
- `GET /api/v1/clients?role=all` — bez ohľadu na rolu

Dva **pomocné (needukladajúce) lookup endpointy** na predvyplnenie a overenie údajov
pri zakladaní klienta. Obidva vyžadujú `auth:sanctum` + oprávnenie `clients.manage`
(`authorize('create', Client::class)`) a sú registrované **pred** `apiResource`, aby ich
nezachytil parameter `clients/{client}`:

| Metóda | URI | Účel |
|---|---|---|
| GET | `/clients/lookup?country=&ico=` | vytiahne firemné údaje z verejného registra podľa IČO (ARES pre CZ, RPO pre SK) |
| GET | `/clients/verify-vat?country=&vat_id=` | overí platnosť IČ DPH cez EU VIES |

Obsluhuje ich `CompanyLookupController`; výsledok sa **neuloadá** — frontend ním len
predvyplní/zvaliduje formulár a klient sa uloží až bežným `POST /clients`.

### Integrácia registrov (ARES / RPO / VIES)

Vyhľadanie firmy a overenie IČ DPH sledujú rovnaký vzor ako externí klienti v module
TimeTracking (`Http::baseUrl(config('services.*'))->timeout()->retry(…, throw: false)`,
návrat `null` pri chybe/nedostupnosti, výsledok cache-ovaný). Klienti sú v
`app/Modules/Clients/Infrastructure/Clients/`:

- **`AresApiClient`** (CZ) — český register `ares.gov.cz`; mapuje `obchodniJmeno`, `dic`
  (v ČR je DIČ zároveň IČ DPH → aj `vat_id`) a adresu zo `sidlo`.
- **`RpoApiClient`** (SK) — slovenský Register právnických osôb `api.statistics.sk/rpo/v1`;
  berie posledný platný názov/adresu; DIČ/IČ DPH nechá `null` (RPO ich spoľahlivo neobsahuje
  → dopĺňa VIES).
- **`ViesApiClient`** — EU VIES overenie IČ DPH (`isValid` + názov/adresa).

Rozhrania `CompanyRegistryClientInterface` a `VatValidatorInterface` (Application/Contracts)
bindinguje `ClientsServiceProvider`. `FetchCompanyDataAction` smeruje na správny register
podľa mapy krajín (`['CZ' => AresApiClient, 'SK' => RpoApiClient]`) a pri neznámej krajine /
nenájdenej firme hodí `DomainException` (→ 422); `VerifyVatAction` obaľuje VIES. Base URL
registrov sú v `config/services.php` (`services.ares|rpo|vies`, env `ARES_API_URL` /
`RPO_API_URL` / `VIES_API_URL`).

### Dátové modely

- **`Client`**: `user_id` (scoped), `client_type` (`individual | self_employed | company`),
  `title, name, surname, company_name, avatar_path, color, ico, dic, vat_id, is_vat_payer,
  email, phone, address, city, postal_code, country, currency, locale, note`,
  **`is_customer` (default `true`), `is_vendor` (default `false`)** — duálne roly klienta.
  Vypočítané `display_name` sa líši podľa typu klienta (firma → `company_name`; SZČO →
  `company_name (meno priezvisko)`; fyzická osoba → `titul meno priezvisko`).
  `vat_id` (IČ DPH) je editovateľné cez create/update (`ClientData`) a práve toto pole
  overuje VIES.
  **Validácia roly**: aspoň jedna z (`is_customer`, `is_vendor`) musí byť `true` — pri porušení
  sa hodí `DomainException` s kľúčom `clients.role_required` (SK/EN lokalizácia).
  **Helpers**: `isCustomer()`, `isVendor()` — typované getters.
- **`ContactPerson`**: `client_id, title, name, surname, email, phone, role, is_primary`.

Autorizácia (`ClientPolicy`): rovnaký vzorec ako všade — `sameAccount()` + `clients.view`/`clients.manage`.

---

## 9. Modul Orders — zákazky

"Zákazka" (job/projekt/kontrakt) je kontajner, na ktorý sa naväzujú časové záznamy, poznámky,
prílohy a položky — a z ktorej neskôr vzniká faktúra.

### Endpointy (`api/v1/orders/*`)

Plný REST pre `orders`, plus vnorené (`scopeBindings`) pod `orders/{order}`:
`items`, `items/{item}`, `notes`, `notes/{note}`, `attachments`, `attachments/{attachment}`.

### Dátové modely

- **`Order`**: `user_id, client_id` (nullable — `null` = "osobná" zákazka, nefakturuje sa),
  `name, color, readme` (markdown brief), `status` (`active|paused|completed|archived`),
  `billing_type` (`hourly|daily|monthly|fixed_per_item|mixed`), `rate, currency`
  (prebíja menu klienta), `estimated_hours, estimated_price, deadline`.
  `effectiveCurrency()` padá späť v poradí zákazka → klient → predvolená mena používateľa.
- **`OrderItem`**: `type` (`service|product|time`), `description, quantity, unit, unit_price,
  vat_rate, vat_amount, total_excl_vat, total_incl_vat, sort_order`.
- **`OrderNote`**, **`OrderAttachment`**: voľné poznámky a prílohy (disk `local/r2/sharepoint/onedrive`).

### Pravidlá

- Osobná zákazka (bez klienta) **nesmie mať** `rate`.
- Fakturovateľná zákazka s `billing_type`, ktorý má predvolenú sadzbu (všetko okrem `mixed`),
  **musí mať** `rate`.
- Zákazku nemožno upravovať (`update`), ak jej stav (`status_enum->isEditable()`) to nedovoľuje.
- Pri zadaní `rate` sa táto sadzba zaznamená ako história do modulu Pricing.

---

## 10. Modul Pricing — sadzby a cenníky

Rieši, akou sadzbou sa má oceniť konkrétny odpracovaný čas alebo položka. Konzumujú ho
moduly Orders aj Invoicing.

### Endpointy (`api/v1/*`)

`rates/effective` (GET), `rates` (GET/POST), `rates/{rate}` (DELETE); plný REST pre
`price-lists`, vnorené `price-lists/{priceList}/items`.

### Dátové modely

- **`Rate`**: sadzba platná od dátumu (**append-only**, nikdy sa neprepisuje).
  `level` (`User|Client|Order`), `client_id`/`order_id` (podľa úrovne), `rate` (nullable =
  "náhrobný kameň" — daná úroveň prestáva platiť od `valid_from`), `currency` (nullable =
  dedí sa), `valid_from`. Zmazať možno len sadzbu platnú od dneška alebo neskôr — chráni to
  historické oceňovanie už odpracovaného obdobia.
- **`PriceList`**: globálny katalóg používateľa, voliteľne podľa meny/krajiny, `is_default`.
- **`PriceListItem`**: položka katalógu (`name, unit, unit_price, vat_rate, is_active,
  sort_order`) — pri vzniku zákazky/faktúry sa **odfotí** (snapshot), takže neskoršia úprava
  cenníka spätne nemení už vystavené doklady.

### Hierarchia riešenia sadzby (`RateResolver`)

Poradie priority: **zákazka > klient > používateľ (globálne)**. V rámci jednej úrovne vyhráva
najnovšia sadzba s `valid_from <=` dátum odpracovania. Celá história sadzieb sa pre danú
kombináciu (používateľ, klient, zákazka) načíta jedným dopytom (`sheetFor()`), zámerne mimo
globálneho scope `HasUserScope` — resolver totiž beží aj vo frontách/CLI, kde `auth()` nie
je dostupné. Kľúčový princíp: **budúca zmena sadzby nikdy neprecení minulú alebo rozpracovanú
prácu** — každý časový záznam sa ocení sadzbou platnou v deň, kedy bola práca vykonaná.

---

## 11. Modul TimeTracking — časové výkazy a výdavky

### Endpointy

Routy registruje `routes/time-tracking.php` (načítané cez `TimeTrackingServiceProvider`),
všetky pod prefixom `api/v1` a middleware `auth:sanctum`:

| Metóda | URI | Účel |
|---|---|---|
| POST | `/time-entries/sync/clockify` | synchronizácia časových záznamov z Clockify |
| POST | `/time-entries/import/csv` | import časových záznamov z Toggl/Clockify CSV exportu |
| POST | `/time-entries/start` | spustenie časovača (jeden bežiaci na účet) |
| POST | `/time-entries/{time_entry}/stop` | zastavenie bežiaceho časovača |
| — | `/time-entries` (`apiResource`) | plný REST nad časovými záznamami (`TimeEntryController`) |
| — | `/expenses` (`apiResource`) | plný REST nad výdavkami (`ExpenseController`) |
| POST/GET/DELETE | `/expenses/{expense}/attachment` | doklad k výdavku (fotka bločku/PDF) — upload/download/zmazanie |
| GET/POST/DELETE | `/exchange-rates` | kurzové lístky (`ExchangeRateController`, len `index`/`store`/`destroy`) |

### Dátové modely

- **`TimeEntry`**: `user_id, order_id, order_item_id, description, started_at, ended_at`
  (`null` = beží časovač), `duration_seconds, rate_override, vat_rate, is_billable,
  is_invoiced, source, external_id` (deduplikačný kľúč pre Clockify/Toggl sync). Metóda
  `stop()` dopočíta `ended_at`/`duration_seconds`. `is_invoiced`, `source` a `external_id`
  sa z requestu nikdy nepreberajú (mass-assignment ochrana fakturačného stavu); záznam s
  `is_invoiced = true` sa nedá upraviť ani zmazať (`time_tracking.entry_already_invoiced`).
- **`Expense`**: `description, category` (`office|travel|software|hardware|marketing|other`),
  `amount, currency, date, note`, plus voliteľná príloha dokladu: `attachment_disk,
  attachment_path, attachment_filename, attachment_mime_type, attachment_size_bytes`
  (`hasAttachment()`). Príloha sa pri soft-delete výdavku **ponecháva** na disku (restore
  musí mať doklad k dispozícii); mime allowlist `image/jpeg|png|webp`, `application/pdf`,
  limit 20 MB.
- **`ExchangeRate`**: `user_id` (nullable = systémový kurz), `base_currency, target_currency,
  rate, date, source`.

### Integrácie

`ClockifyApiClient` + CSV parsery (`ClockifyCsvParser`, `TogglCsvParser`, zdieľané
`ImportCsvAction`) pre import časových záznamov — CSV import aj Clockify sync viažu celý
import na jednu zákazku (`order_id`). `CnbApiRateClient` (Česká národná banka) poskytuje
výmenné kurzy — používa ho tento modul aj `IssueInvoiceAction` v module Invoicing pri
"zmrazení" kurzu na faktúre v cudzej mene.

### Väzba na Orders/Invoicing

Časový záznam patrí k zákazke (`order_id`), voliteľne aj k položke zákazky (`order_item_id`
— musí patriť tej istej zákazke, inak `time_tracking.item_not_in_order`). Záznamy, ktoré sú
`is_billable = true` a `is_invoiced = false`, sú presne tie, ktoré
`GenerateInvoiceFromOrderAction` pri fakturácii vezme a po vyfakturovaní označí
`is_invoiced = true`, aby sa nedali vyfakturovať dvakrát.

---

## 12. Modul Calendar — udalosti, import/export, retencia

Jednoduchý kalendár udalostí naviazaný na účet, s CSV/ICS importom aj exportom.
Podrobnejší plán v [`docs/PLAN_KALENDAR.md`](./PLAN_KALENDAR.md).

### Endpointy (`api/v1/events/*`, `routes/calendar.php`, `auth:sanctum`)

| Metóda | URI | Účel |
|---|---|---|
| — | `/events` (`apiResource`) | plný REST nad udalosťami |
| POST | `/events/import/csv` | import zo Slovak/Qasa CSV formátu (`;`-delimited) |
| POST | `/events/import/ics` | import z ICS súboru |
| GET | `/events/export/csv` | export do CSV (`from`/`to` filter) |
| GET | `/events/export/ics` | export do ICS (`from`/`to` filter) |

### Dátový model `Event`

`user_id, title, description, location, color, is_all_day, starts_at, ends_at` (exkluzívne
— polnoc sa ukladá ako nasledujúci deň 00:00), `source` (`manual|csv_import|ics_import`),
`external_uid` (ICS UID alebo import hash — dedupe kľúč). Celodenné udalosti sa normalizujú
na `[začiatok dňa, +1 deň)`; časované udalosti nesmú prekročiť polnoc a zarovnávajú sa na
mriežku `config('calendar.slot_minutes')` (default 15 min).

Import (CSV aj ICS) zdieľa rovnaký dedupe/normalizačný pipeline a vracia `{created, skipped,
errors[]}` — chyba v jednom riadku nezastaví import ostatných. Export do ICS
(`Infrastructure/Ics/IcsBuilder`, cez `sabre/vobject`) používa "plávajúci" lokálny čas (bez
`TZID`/`Z`), zhodne s tým, ako sa časy ukladajú v DB.

Presahovanie udalostí rieši rebindovateľné rozhranie `OverlapPolicyInterface` — OSS edícia
(`AllowOverlapPolicy`) povoľuje čokoľvek; kontrola presahov je pripravená ako hák pre SaaS.

### Retencia — `qasa:calendar:purge-past`

Naplánovaný denne o 4:30 (`routes/console.php`, `withoutOverlapping()->onOneServer()`),
tvrdo maže (force-delete) staré udalosti cez `PurgePastEventsAction`. Hranica podľa
`config('calendar.retention.mode')`: `current_month` (OSS default — drží od začiatku
aktuálneho mesiaca) alebo `months_after_end` (SaaS, `CALENDAR_RETENTION_MONTHS_AFTER_END`,
default 3 mesiace po skončení udalosti).

---

## 13. Modul Invoicing — fakturácia

Najkomplexnejší modul — životný cyklus faktúry (draft → vystavená → odoslaná → upomienka →
zaplatená/dobropis/storno), generovanie PDF, platobné QR kódy, bankové účty, opakovaná
fakturácia, história platieb. Od v2.1 podporuje **konfigurovateľnú masku čísel faktúr** a
**hromadný export do Pohoda XML / CSV** pre účtovníkov.

### Endpointy (`api/v1/invoices/*` a súvisiace)

| Metóda | URI | Účel |
|---|---|---|
| — | `apiResource('invoices', ...)` | plné CRUD |
| — | `apiResource('bank-accounts', ...)` | plné CRUD |
| POST | `invoices/{invoice}/status` | zmena stavu |
| POST | `invoices/{invoice}/email` | odoslanie e-mailom (throttle 10/min) |
| POST | `invoices/{invoice}/remind` | upomienka (throttle 10/min) |
| POST | `invoices/{invoice}/corrective` | dobropis/storno |
| POST | `invoices/{invoice}/settle` | vyúčtovanie zaplatenej proformy na ostrú faktúru |
| POST/DELETE | `invoices/{invoice}/items[/…]` | položky faktúry |
| POST/DELETE | `invoices/{invoice}/payments[/…]` | platby (ledger) |
| GET | `invoices/{invoice}/pdf/download`, `/preview` | PDF |
| GET/PUT/POST | `invoices/{invoice}/work-report[...]` | výkaz víceprác |
| POST | `invoices/generate-from-order` | vygenerovanie faktúry zo zákazky |
| — | `apiResource('recurring-invoice-templates', ...)` | plné CRUD |
| POST | `recurring-invoice-templates/{template}/pause`, `/resume` | pozastavenie/obnovenie |
| GET | `invoices/export/pohoda` | hromadný export faktúr do Pohoda XML (s filtrom podľa obdobia) |
| GET | `invoices/export/csv` | hromadný export faktúr do CSV (s filtrom podľa obdobia) |

### Dátové modely

- **`Invoice`**: centrálny doklad. `user_id, client_id, invoice_number, type`
  (`invoice|proforma|credit_note|storno`), `related_invoice_id` (originál pri opravnom
  doklade, alebo vyúčtovaná proforma pri vyúčtovacej faktúre), `settled_invoice_id`
  (opačný smer — nastavené na proforme, ukazuje na faktúru vzniknutú jej vyúčtovaním),
  `status` (`draft|issued|sent|reminded|paid|cancelled|credited`), `issued_at,
  taxable_supply_at` (DUZP), `due_at, variable_symbol, bank_account_id`,
  `bank_account_snapshot/supplier_snapshot/client_snapshot` (json, zmrazené pri vystavení),
  `discount_percent/discount_amount, currency, exchange_rate_snapshot` (ČNB→CZK, zmrazený),
  `subtotal/vat_amount/total, note/note_above, recurring_template_id, emailed_at/emailed_to/
  emailed_cc/email_failed_at, last_reminded_at, reminder_count, overdue_notified_at`
  (idempotencia `InvoiceOverdue` eventu), `reminders_exhausted_notified_at` (e-mail vlastníkovi
  po vyčerpaní auto-upomienok bol odoslaný). `balance()` = `total - suma platieb`.
- **`InvoiceItem`**: rovnaká štruktúra ako `OrderItem`, plus pôvod (`order_item_id,
  time_entry_id, price_list_item_id`).
- **`InvoicePayment`** — **história/ledger platieb**: `invoice_id, amount, paid_at, method`
  (`bank_transfer|cash|card|other`), `note`. Faktúra môže mať viacero čiastočných platieb.
- **`BankAccount`**: `label, bank_name, account_number, iban, bic, currency, is_default`.
- **`RecurringInvoiceTemplate`** / **`RecurringInvoiceTemplateItem`**: šablóna opakovanej
  fakturácie (`period, day_of_month/last_day_of_month, next_run_date, auto_send`), položky
  podporujú placeholdery `{BOM}/{EOM}/{MONTH}/{YEAR}`.
- **`InvoiceWorkReportLine`**: druhá strana faktúry — výkaz víceprác predvyplnený z časových
  záznamov, upraviteľný kým je faktúra v stave draft.

### Konfigurácia čísel faktúr — `InvoiceNumberMask`

Každý používateľ môže v profile nastaviť **masku** pre číslovanie faktúr s zástupnými znakmi:

- **Placeholdery**: `{YYYY}` (rok), `{YY}` (2-znakový rok), `{MM}` (mesiac), `{DD}` (deň), jeden sekvenčný token `{N}` / `{NN}` / `{NNN}` atď. (počet `N` = šírka nuly sprava).
- **Príklady**:
  - `{YYYY}{NNNN}` → `20260001`, `20260002`, … (ročný reset, 4-miestne poradie)
  - `{YY}01{NNN}` → `2601001`, `2601002`, … (ročný reset, 3-miestne poradie)
  - `{NNNNN}` → `00001`, `00002`, … (bez resetu, priebežné číslovanie)
- **Spätná kompatibilita**: keď je maska `null`, používa sa doterajší formát `{prefix}-{YYYY}-{NNN}`.
- **Počiatočné poradie** (`invoice_number_start`): konfigurovateľné na úrovni používateľa, umožňuje migráciu z iného systému.
- **Nezávislé rady**: rôzne typy dokladov (`Invoice`, `Proforma`, `CreditNote`, `Storno`) majú oddelené sekvencie — typ sa mapuje cez prefix (`PF-`, `DB-`, `ST-`).

Logika je zapuzdrená v `app/Modules/Invoicing/Domain/Services/InvoiceNumberMask` — service formátuje poradie na číslo, extrahuje poradie z existujúcich čísel, a generuje regex na vyhľadávanie faktúr v rovnakom období/type.

### Stavový automat faktúry

```
Draft      → Issued, Sent
Issued     → Sent, Paid, Cancelled, Credited
Sent       → Reminded, Paid, Cancelled, Credited
Reminded   → Paid, Cancelled, Credited
Paid       → Credited
Cancelled  → (koncový stav)
Credited   → (koncový stav)
```

### Typy dokladov

`Invoice, Proforma, CreditNote, Storno` — každý má vlastnú číselnú radu/prefix
(vlastný prefix používateľa pre bežnú faktúru; `PF`/`DB`/`ST` pre ostatné), takže sa čísla
nikdy neprekrývajú. Proforma nie je daňový doklad (nemá DUZP, na tlačive je "Není daňový doklad").

### Kľúčové akcie

- **`CreateInvoiceAction`** — pridelí ďalšie poradové číslo podľa typu/prefixu, odvodí
  variabilný symbol, predvolí bankový účet podľa meny.
- **`IssueInvoiceAction`** — okamih **draft → vystavená**: zmrazí snapshoty dodávateľa,
  klienta a bankového účtu (neskoršia úprava profilu už doklad spätne nezmení); pre cudziu
  menu zmrazí kurz ČNB (zlyhanie nie je fatálne — PDF len vynechá CZK tabuľku).
- **`GenerateInvoiceFromOrderAction`** — most Zákazka → Faktúra (pozri kapitolu 15).
- **`CreateCorrectiveInvoiceAction`** — vytvorí dobropis alebo storno s **opačným znamienkom
  množstva**, s odkazom na originál; storno navyše vynúti zrušenie originálu; povolené len
  zo stavu `sent`/`paid` (dobropis) alebo `sent` (storno).
- **`SettleProformaAction`** (`POST invoices/{invoice}/settle`) — vyúčtovanie zaplatenej
  proformy: vytvorí novú ostrú faktúru (`type = invoice`, vlastná číselná rada,
  `related_invoice_id` = proforma) s **plnými položkami** skopírovanými z proformy
  (záloha sa neodpočítava ako záporná položka — inak by `subtotal` vyšiel ~0 a
  `RevenueCostAggregator`, ktorý číta tržby zo `subtotal` typov `invoice`/`credit_note`,
  by tržbu nikdy nezaznamenal), `taxable_supply_at` = dátum poslednej platby proformy,
  vystaví ju hneď cez `IssueInvoiceAction`, prenesie platby proformy ako nové
  `InvoicePayment` riadky (s poznámkou o pôvode) a nastaví stav rovno na `Paid`
  (`InvoicePaid` event, teda aj webhook `invoice.paid`). Na proforme sa nastaví
  `settled_invoice_id` (idempotencia — druhé vyúčtovanie → `422`). Guardy: len typ
  `proforma`, len plne zaplatená, len raz.
- **`SendInvoiceEmailAction`** — ak je faktúra ešte draft, najprv ju vystaví, potom zaradí
  do fronty e-mail `InvoiceEmail`, zaznamená `emailed_at/emailed_to/emailed_cc`.
- **`RemindInvoiceAction`** — upomienka, len pre stavy `sent`/`reminded`, s vlastným
  "cooldownom" (`invoicing.reminder_cooldown_days`, predvolene 3 dni — nezávisle od
  rate-limitu na úrovni routy), posunie stav na `reminded`, zvýši `reminder_count`.
- **`RecordPaymentAction`** — zápis do platobného ledgeru: vytvorí `InvoicePayment`; stav
  sa zmení na `Paid` **len keď `balance() <= 0`** (čiastočné/preplatky ostávajú otvorené).
  Zablokované pre dobropisy a doklady mimo otvoreného/zaplateného stavu.
- **`GenerateRecurringInvoicesCommand`** — plánovaná úloha (denne o 5:00, Europe/Bratislava),
  dobieha zameškané opakované faktúry (max. 24 iterácií na šablónu); automaticky odošle
  e-mailom len **poslednú** vygenerovanú faktúru z behu (výpadok cronu tak klienta nezaplaví
  starými e-mailmi).

### Automatické upomienky — `SendAutoRemindersCommand`

Plánovaná úloha (denne o 6:00, po generovaní opakovaných faktúr), voliteľná per tenant
(`users.auto_remind_enabled`, predvolene **vypnuté** — manuálne tlačidlo `/remind` funguje
bez zmeny nezávisle od tejto úlohy). Beží pre **všetkých** používateľov, nielen opt-in:

1. **Detekcia po splatnosti** — pre faktúry v stave `sent`/`reminded` s `due_at` staršou než
   `overdue_reminder_days` a `overdue_notified_at IS NULL` nastaví marker a vyvolá event
   `InvoiceOverdue` (zatiaľ bez listenera — seam pre budúce webhooky).
2. **Odoslanie** (len ak `auto_remind_enabled`) — ak `reminder_count < auto_remind_max` a
   posledná upomienka je staršia než `auto_remind_interval_days` (alebo žiadna neexistuje),
   zavolá existujúci `RemindInvoiceAction` (jeho vlastný cooldown ostáva druhou poistkou).
3. **Vyčerpanie** — po dosiahnutí `auto_remind_max` odošle vlastníkovi e-mail
   `RemindersExhaustedMail` (raz, cez marker `reminders_exhausted_notified_at`).

Zlyhanie na jednej faktúre (napr. chýbajúci e-mail klienta) sa loguje a beh pokračuje.

### PDF a platobné QR

- **`InvoicePdfService`** (dompdf) — vykreslí Blade šablónu, zámerne vypína `isRemoteEnabled`
  a `isPhpEnabled` (ochrana proti SSRF/RCE cez injektáž do šablóny), vloží logo dodávateľa
  ako base64, rieši lokalizáciu podľa jazyka klienta/používateľa.
- **`PaymentQrService`** — SVG QR kód: **CZK → SPAYD** ("QR platba"), **EUR → SEPA EPC**,
  iné meny → bez QR. Vyžaduje IBAN na bankovom účte.
- **`VatRecapCalculator`** — DPH sa počíta **podľa sadzbovej skupiny** (rekapitulačná
  tabuľka), zľava na úrovni faktúry sa aplikuje pomerne pred výpočtom DPH (česká/slovenská
  účtovná konvencia); generuje aj CZK prepočet podľa zmrazeného kurzu.

### DPH — sadzby, reverse charge, súhrnný výkaz

Podrobnejší plán v [`docs/PLAN_DPH.md`](./PLAN_DPH.md).

- **`VatStatus`** (`app/Modules/Shared/Enums/VatStatus.php`) na `User.vat_status`:
  `non_payer` (nikdy neúčtuje DPH), `identified` (má IČ DPH pre vnútrounijné nadobudnutia,
  domácu DPH účtovať nemôže, bez nároku na odpočet), `payer` (plný platiteľ, účtuje DPH,
  má nárok na odpočet). `is_vat_payer` je zastarané, len zrkadlí `vat_status === Payer`
  (pozri pamäťovú poznámku o synchronizácii pri factory testoch).
- **`VatRate`** — per-tenant katalóg sadzieb (`user_id, code, country, rate, label,
  is_default, valid_from/valid_to`). `VatRateInCatalog` rule pri zápise overí, že číselná
  sadzba položky existuje v katalógu pre danú krajinu/dátum — položky nemajú FK na katalóg,
  takže zmrazená sadzba prežije aj neskoršiu úpravu/zmazanie katalógovej položky. **0 % je
  vždy povolené** (reverse charge / neplatiteľ / oslobodené plnenie).
- **Reverse charge polia**: `clients.reverse_charge_allowed` (domáci RC opt-in),
  `clients.vat_verified_at` (posledná úspešná VIES kontrola), `invoices.reverse_charge` +
  `reverse_charge_mode` (`domestic|eu`), `supplier_invoices.vat_regime`
  (`domestic|eu_reverse_charge|import`) + `self_assessed_vat_amount`,
  `recurring_invoice_templates.reverse_charge` (len úmysel — režim sa vždy nanovo odvodí
  od aktuálneho klienta pri každom generovaní, nikdy sa needukladá natrvalo).
- **`InvoiceVatRegimeResolver`** rozhoduje o reverse charge: neplatiteľ nikdy nemôže
  fakturovať v režime RC; EU klient s IČ DPH (iná krajina ako dodávateľ, v
  `config('countries.eu_members')`) je automaticky v režime `eu` bez ohľadu na
  `identified`/`payer` status dodávateľa; `identified` dodávateľ mimo EU/domáci klient nikdy
  RC nemá; `payer` dodávateľ môže domáci RC použiť len ak si ho explicitne vyžiada **a**
  `client.reverse_charge_allowed = true` **a** krajiny sa zhodujú, inak
  `invoicing.reverse_charge_not_allowed_for_client`. `ViesPreconditionService` podmieňuje
  vystavenie EU-RC faktúry živou VIES kontrolou, s dočasným grace-oknom
  (`config('qasa.vies_grace_days', 30)`) pri výpadku VIES — nikdy pre číslo, ktoré VIES
  aktívne odmietlo.
- **Prijaté faktúry**: `SupplierVatRegime` (`domestic|eu_reverse_charge|import`) —
  self-assessed režimy (`isSelfAssessed()`) sa nezapočítavajú do `total` (dodávateľovi sa
  nič neplatí), sledujú sa len v `self_assessed_vat_amount`.
- **Súhrnný výkaz (EU sales list)** — `EuSalesListService` cez
  `GET /api/v1/reports/eu-sales-list?year=&quarter=&month=`: zoskupí vystavené,
  vnútrounijné RC faktúry (`reverse_charge_mode = eu`) podľa mesiaca a **zmrazeného**
  `client_snapshot['vat_id']` (neskoršia úprava klienta spätne nemení už podaný výkaz),
  drafty a storná vylúčené.
- **Kontrolný výkaz DPH (SK KV DPH / CZ kontrolní hlášení)** — len pre `vat_status =
  payer` (inak `invoicing.vat_report_payer_only`, 422). Aplikácia **negeneruje ani
  nepodáva daňové priznanie** — dodáva podklad (JSON) a XML draft na kontrolu pred
  podaním; `assumptions` v odpovedi vždy obsahuje tento disclaimer.
  - **`VatControlStatementService::forPeriod()`** (`GET
    /api/v1/reports/vat-control-statement?country=SK|CZ&year=&month=|&quarter=`) triedi
    vystavené (`Invoice`) aj prijaté (`SupplierInvoice`) doklady do sekcií podľa krajiny:
    SK `A1` (tuzemské vydané per doklad), `A2` (tuzemský RC vydané, `D=0`), `B1`
    (samozdanenie — `SupplierVatRegime::isSelfAssessed()`), `B2` (prijaté s odpočtom per
    doklad), `C1` (dobropisy vydané, `FP` = číslo pôvodnej faktúry cez
    `relatedInvoice`); `B3`/`C2` sú vždy prázdne (zjednodušené doklady a dobropisy
    k prijatým faktúram tento systém neeviduje). CZ naviac delí `A1`/`B2` prahom
    10 000 Kč s DPH (`grossInCzk()` cez `exchange_rate_snapshot`/`exchange_rate`) na
    `A4`/`B2` (per doklad) a kumulatívne `A5`/`B3`. EU-RC doklady (`reverse_charge_mode
    = eu`) sú vylúčené — patria do súhrnného výkazu, nie sem.
  - **XML draft** — `GET .../vat-control-statement/xml`: `DphKh1XmlBuilder` (CZ,
    žiadny namespace, dátumy `DD.MM.RRRR`) a `KvDphXmlBuilder` (SK, namespace
    `kv_dph_2025.xsd`, dátumy ISO) — oba `DOMDocument`, overené proti reálnym XSD
    schémam z portálov Finanční správy/financnasprava.sk (`tests/Fixtures/vat-control-
    statement/*.xsd`, `schemaValidate()` v testoch). SK vyžaduje `month` alebo
    `quarter` (ročný draft nie je pre KV DPH podávateľný), CZ nie. **Placeholder polia,
    ktoré appka nezbiera** (finanční úřad, kód predmetu plnenia pri tuzemskom RC) sú
    v XML vyplnené zjavným placeholderom a vypísané v `assumptions` — pred podaním
    treba doplniť/overiť s účtovníkom. SK schéma nemá samostatnú sekciu pre tuzemský
    RC vydané — JSON sekcia `A2` sa v XML exportuje ako `<A1>` s `D=0`.

### Prijaté faktúry a scan inbox

Podrobnejší plán v [`docs/PLAN_PRIJATE_FAKTURY.md`](./PLAN_PRIJATE_FAKTURY.md) a
[`docs/PLAN_SCAN_INBOX.md`](./PLAN_SCAN_INBOX.md).

**Endpointy** (`routes/invoicing.php`): plný REST `apiResource('supplier-invoices', ...)`
plus `POST supplier-invoices/{supplier_invoice}/status`; pre inbox
`apiResource('invoice-inbox', ...)->only(['index', 'show', 'destroy'])` plus
`GET invoice-inbox/{inbox_item}/download`, `POST invoice-inbox/{inbox_item}/convert`,
`POST invoice-inbox/{inbox_item}/ignore`, `POST invoice-inbox/upload` (throttle `30,1`).

**Manuálny upload** (`POST invoice-inbox/upload`, `InvoiceInboxController::upload()`):
multipart `file` (rovnaký MIME allowlist a `max_bytes` limit ako scan), uložený do
priečinka schránky účtu pod vygenerovaným menom (pôvodné meno ide len do
`original_filename`), spracovaný **synchrónne** cez `ProcessInboxFileAction` — bez
čakania na cron a nezávisle od `invoice_inbox_enabled`. Duplicita hashu → `422`
(`invoicing.inbox.duplicate_file`); nepodporovaný typ/veľkosť → `422`
(`invoicing.inbox.upload_invalid_file`).

**Dátové modely**: `SupplierInvoice` (dodávateľ modelovaný ako `Client`,
`internal_number, supplier_invoice_number, variable_symbol, status, vat_regime, issued_at,
taxable_supply_at, due_at, received_at, paid_at, currency, exchange_rate, subtotal,
vat_amount, total, self_assessed_vat_amount, vendor_snapshot`) s riadkami DPH rekapitulácie
`SupplierInvoiceVatLine`. `InvoiceInboxItem` (`status`: pending/imported/ignored/failed,
`disk, path, original_filename, mime_type, size_bytes, file_hash` — SHA-256 dedupe,
`ocr_text, ocr_engine, suggestions, matched_client_id, scanned_at, error`).

**Scan inbox pipeline** (dokument → návrh dokladu):

1. Súbor sa dostane do priečinka schránky účtu (disk/cesta z `config('invoicing.inbox.*')`) —
   buď externým nástrojom sledujúcim priečinok, alebo manuálnym uploadom
   (`POST invoice-inbox/upload`, spracuje sa hneď, pozri vyššie).
2. `qasa:invoices:scan-inbox` (naplánované každých 15 minút,
   `withoutOverlapping()->onOneServer()`) prejde všetky účty s `invoice_inbox_enabled = true`
   a pre každý nájdený súbor validuje MIME (`application/pdf`, `image/jpeg`, `image/png`)
   a veľkosť (max 20 MB podľa `invoicing.inbox.max_bytes`), potom delegovuje na
   `ProcessInboxFileAction` (zdieľané aj s uploadom), ktoré počíta SHA-256 hash (duplicita sa
   preskočí, ale súbor sa aj tak presunie do `processed/`, aby sa už neprocesoval znova).
3. `CompositeExtractor` extrahuje text — pre PDF najprv `smalot/pdfparser` (textová vrstva);
   ak text nestačí (napr. skenované PDF bez textovej vrstvy, menej než 20 znakov), nastúpi
   **OCR fallback**: `PdfRasterizer` prevedie stránky PDF na PNG cez `pdftoppm`
   (poppler-utils, cez `Illuminate\Support\Facades\Process`, limit `ocr_max_pages` = 5
   stránok, `ocr_dpi` = 200) a každá stránka ide cez rovnaký `thiagoalessio/tesseract_ocr`
   ako fotky (`ocr_engine = 'pdftoppm+tesseract'`); pre obrázky sa `tesseract_ocr` použije
   priamo. Chýbajúca binárka (`pdftoppm` aj `tesseract`) alebo zlyhaný proces sa nikdy
   nevyhodí ako výnimka — degraduje na pôvodné správanie (prázdny text → `failed`).
4. `SupplierInvoiceParser` (čisté regex/heuristiky nad SK/CZ textom) vytiahne číslo faktúry,
   IČO/DIČ, dátumy, sumu, VS, IBAN, menu; `ScanInboxAction` k tomu dopáruje dodávateľa podľa
   IČO (`matched_client_id`).
5. Výsledok sa uloží ako `InvoiceInboxItem` v stave `pending`; prázdny extrahovaný text →
   rovno `failed` (`invoicing.inbox.extraction_failed`).
6. Používateľ návrh skontroluje (`GET invoice-inbox/{id}`) a potvrdí
   (`POST .../convert`) — `ConvertInboxItemAction` v transakcii vytvorí `SupplierInvoice`
   cez `CreateSupplierInvoiceAction` a naviaže ju späť na inbox položku (`status = imported`).
   Druhý pokus na už spracovanú položku → `invoicing.inbox.already_processed`.
   `POST .../ignore` označí položku ako `ignored` bez vytvorenia dokladu.

### Hromadný export faktúr — Pohoda XML, CSV, KROS Omega, ISDOC

Účtovníci potrebujú na konci obdobia vyviezť vydané faktúry do svojho účtovného softvéru.
Štyri endpointy (`/api/v1/invoices/export/{pohoda,csv,omega}` +
`/api/v1/invoices/{invoice}/export/isdoc`), prvé tri zdieľajúce rovnaký filter DTO:

- **`InvoiceExportData`** (Application/DTOs) — filtrovanie:
  - `dateFrom`, `dateTo` — povinné (rozsah dátumov).
  - `periodBasis` (`issue|tax`, default `issue`) — či filtrujeme podľa dátumu vystavenia alebo DUZP.
  - `types[]` (default: `invoice, credit_note, storno`) — typ dokladu; proforma a drafty sú vylúčené.
- **Proforma nie je daňový doklad** — nikdy sa neexportuje. Draft sa nikdy neexportuje.
- **Dáta zo snapshoty** — export vždy čítá zo zmrazených snapshotypov (`supplier_snapshot`, `client_snapshot`, `bank_account_snapshot`), aby sa výstup zhodoval s vystavenými PDF.

#### Pohoda XML export — `PohodaXmlBuilder`

- **`app/Modules/Invoicing/Domain/Services/PohodaXmlBuilder`** — buduje `DOMDocument`
  (nie ručné skladanie XML — zabraňuje SSRF/injection, zaručuje korektné XML escapovanie).
- **Formát**: Stormware Pohoda `dataPack` (verzia 2.0) s `ico` dodávateľa v root elemente.
- **Jednotlivé faktúry** → `<dat:dataPackItem>/<inv:invoice>`:
  - `invoiceHeader` — typ (`issuedInvoice` / `issuedCreditNotice` / vrátka), číslo, variabilný symbol, dátumy (issued, tax, due).
  - `partnerIdentity` — klient (zo snapshoty): názov, adresa, IČO, DIČ.
  - `invoiceDetail` — položky faktúry s mapovanou DPH sadzbou (`none/low/high/third`).
  - `invoiceSummary` — summary per sadzbu DPH + cudzia mena (ak je).
- **DPH mapovanie**: `PohodaVatRate` mapuje sadzby → `rateVAT` s konfigurovanými prahmi (default CZ: `high=21%, low=12%`).
- **Cudzia mena**: EUR/USD → `foreignCurrency` blok s `rate` zo snapshoty; home ekvivalent cez `czkRecap()`.

#### CSV export — `InvoiceCsvBuilder`

- **`app/Modules/Invoicing/Application/Services/InvoiceCsvBuilder`** — League/Csv Writer.
- **Formát**: UTF-8 BOM + `;` delimiter (Excel kompatibilita v CZ/SK locales).
- **Granularita**: jeden riadok = jedna faktúra (hlavičková úroveň — jednoduchá importovateľnosť).
- **Stĺpce** (lokalizované): `invoice_number`, `type`, `status`, `issued_at`, `taxable_supply_at`, `due_at`, `client_name`, `client_ico`, `client_dic`, `client_vat_id`, `currency`, `subtotal`, `discount_amount`, `vat_amount`, `total`, `paid_amount`, `balance`, `variable_symbol`, `exchange_rate`.
- **Sumárne hodnoty**: zo snapshoty / `VatRecapCalculator` (aby sa zhodovali s PDF).

#### KROS Omega text export — `OmegaExportBuilder` ⚠️ neoverené

- **`GET /api/v1/invoices/export/omega`** (vydané, rovnaký `InvoiceExportData` filter) a
  **`GET /api/v1/supplier-invoices/export/omega`** (prijaté, `SupplierInvoiceExportData`
  filter — bez `types`, prijaté faktúry majú len jeden typ dokladu) — účtovník potrebuje
  oba smery.
- **Formát**: riadky `R01` (hlavička dokladu) + `R02` (jeden riadok na DPH sadzbu), podľa
  konvencie z KROS podpory; `;` delimiter, kódovanie Windows-1250 cez `iconv` (`mbstring`
  v tomto prostredí Windows-1250/CP1250 vôbec nemá — pozor pri prípadnom refaktore).
- **⚠️ Neoverené**: presná pozícia ~166 stĺpcov je zdokumentovaná len v binárnom Excel
  súbore na KROS FTP (nedostupný ako fetchovateľný web obsah) — `config/omega.php` má
  explicitný komentár s touto výhradou. Architektúra (builder, riadkový R00/R01/R02
  vzor, DPH mapovanie cez `OmegaVatRate`/`config('omega.vat_codes')`) je pripravená;
  presné stĺpce treba overiť/doplniť pred produkčným použitím.
- **DPH mapovanie**: `OmegaVatRate::codeFor()` cez `config('omega.vat_codes')`
  (sadzba → kód), rovnaký princíp ako `PohodaVatRate` — jeden number-mapping bod.

#### ISDOC export — `IsdocBuilder`

- **`GET /api/v1/invoices/{invoice}/export/isdoc`** — per doklad (ISDOC je formát
  jednej faktúry), len `invoice`/`credit_note` (storno a proforma → `422`), len
  vystavené a novšie stavy (draft → `422`, `invoicing.isdoc_draft_not_exportable`).
- **ISDOC 6.0.2** (https://isdoc.cz), `DOMDocument` (rovnaký princíp ako
  `PohodaXmlBuilder`) — element order a povinné polia **overené priamo proti
  stiahnutej XSD schéme** (`isdoc-invoice-6.0.2.xsd`, na rozdiel od KROS Omega vyššie).
  `DocumentType` (1=faktúra/2=dobropis), `AccountingSupplierParty`/
  `AccountingCustomerParty` zo snapshotov (IČO, DIČ/IČ DPH prefixovaný ako `vat_id`),
  `InvoiceLines` s `ClassifiedTaxCategory`, `TaxTotal` per sadzba (z
  `VatRecapCalculator::recap()`, vrátane povinných `AlreadyClaimed*`/`Difference*`
  polí — nulové, keďže zálohové faktúry sa neexportujú), `LegalMonetaryTotal`,
  voliteľný `PaymentMeans` (len keď má bankový snapshot IBAN aj lomítkový formát
  účtu — inak sa celý element vynechá, keďže je nepovinný).
- Response `application/xml`, filename `{invoice_number}.isdoc`. Testy validujú
  XSD priamo (`tests/Fixtures/isdoc/isdoc-invoice-6.0.2.xsd`).

### Verejný odkaz na faktúru

Klient bez prihlásenia vidí faktúru, stiahne PDF a vidí stav úhrady; tenant vidí, či ju klient
otvoril.

- **Token, nie signed URL** — `public_token` (`Str::random(64)`, ~380 bitov), uložený
  v plaintexte (unique index); odvolanie = `NULL`, nový odkaz = nový token. Draft nikdy nemá
  odkaz (`CreateInvoicePublicLinkAction` naň hádže `DomainException`) — verejná stránka číta
  výhradne zo zmrazených snapshotov.
- **Vznik**: explicitne (`POST invoices/{invoice}/public-link`, idempotentné, voliteľné
  `regenerate`) alebo automaticky pri odoslaní e-mailom (`SendInvoiceEmailAction`/
  `RemindInvoiceAction`), podľa configu `invoicing.public_link_in_emails`.
- **`PublicInvoiceController`** (`api/v1/public/invoices/{token}`, mimo `auth:sanctum`,
  limiter `public-doc` 30/min/IP) — payload = presne to, čo je na PDF (žiadne `user_id`,
  `client_id`, interné polia), `public_status` (zjednodušená mapa `InvoiceStatus`), QR na
  **zostatok** (`balance()`), nie na `total`. Sleduje `public_first_viewed_at`/
  `public_view_count`; neznámy token → 404 (rovnako ako neexistujúca routa).

### Cenové ponuky (Quotes)

Chýbajúci začiatok obchodného lievika: ponuka → odoslanie → verejná akceptácia/odmietnutie →
konverzia na zákazku alebo faktúru jedným klikom. Samostatný model `Quote`/`QuoteItem`
(vlastné tabuľky, vlastná číselná maska `quote_number_mask`, config default `CP-{YYYY}-{NNN}`)
— nie nový `InvoiceType`, keďže ponuka má iný životný cyklus (accepted/rejected/expired), bez
platieb, DUZP či dobropisov.

- **Stavy** (`QuoteStatus`): `Draft → Sent → Accepted|Rejected|Expired`. `effectiveStatus()`
  počíta expiráciu za behu z `valid_until` (žiadny denný cron) — `Sent` po termíne sa javí a
  správa ako `Expired`, bez fyzického prepnutia stĺpca `status`.
- **Snapshoty** sa zmrazia pri prvom prechode `draft → sent` (`UpdateQuoteStatusAction`) —
  ponuka nemá samostatný krok "vystaviť" ako faktúra.
- **Verejné rozhodnutie je jednorazové**: `DecideQuoteAction::accept()/reject()` cez
  `api/v1/public/quotes/{token}/accept|reject` (limiter `public-decide`, 10/min/IP), len zo
  stavu `Sent` a pred `valid_until`; druhý pokus → `invoicing.quote_already_decided`. Ukladá
  `decision_note`/`decision_ip`; vyvolá `QuoteAccepted`/`QuoteRejected` → e-mail vlastníkovi.
- **Konverzia** povolená zo `Sent` aj `Accepted` (telefonické odsúhlasenie), nikdy z
  `Draft`/`Rejected`/`Expired`; dvojitá konverzia rovnakého typu → `invoicing.quote_already_converted`.
  - **`ConvertQuoteToInvoiceAction`** — vytvorí **draft** faktúru cez `CreateInvoiceAction`
    (rovnaké položky, zľava), uloží `converted_invoice_id`.
  - **`ConvertQuoteToOrderAction`** — vytvorí `Order` s `billing_type = mixed` (jediný typ bez
    povinnej sadzby) cez `Orders\Application\Contracts\CreateOrderActionInterface` (modulová
    hranica: Invoicing smie závisieť len na Orders kontraktoch, nie na jeho Actions/Services),
    uloží `converted_order_id`.
- **PDF** (`QuotePdfService` + `quote-pdf.blade.php`) — vždy „Nie je daňový doklad", bez QR
  a bankových údajov; zdieľa VAT rekapituláciu s faktúrou cez `VatRecapCalculator`.
- **Zdieľaná VAT matematika**: `VatRecapCalculator` bol rozšírený o item-based jadro
  (`recapFromItems`/`subtotalFromItems`/…), ktoré `Invoice`- aj `Quote`-typované metódy
  volajú ako tenké wrappery — jedna implementácia zaokrúhľovania pre oba typy dokladov.

### Platobné príkazy — hromadná úhrada prijatých faktúr

Výber neuhradených prijatých faktúr → dávka (`PaymentOrder`) → súbor pre internetbanking.
Automatické párovanie bankových výpisov je vedome mimo OSS (SaaS) — preto je „predané
k úhrade" samostatná dimenzia, nie stav: `supplier_invoices.handed_to_payment_at`
(nastaví vytvorenie dávky, zmaže zmazanie poslednej živej dávky s faktúrou), `status`
ostáva `received`/`booked`; skutočné `paid` nastaví ručné označenie alebo `mark_paid`
pri vytvorení dávky.

- **Účet príjemcu žije na prijatej faktúre** (`vendor_account_number` tuzemský
  `[predčíslie-]číslo` + `vendor_bank_code`, alebo `vendor_iban`/`vendor_bic`);
  `account_source` eviduje pôvod (`manual|ocr` — ISDOC/QR-z-PDF sú budúce hodnoty).
  `SupplierInvoiceParser` extrahuje popri VS aj IBAN (mod-97 validácia) a označený
  tuzemský účet; `ConvertInboxItemAction` prenesie nezmenený návrh ako `ocr`.
- **Dávka je snapshot**: `payment_orders` (payer_snapshot účtu platcu, mena, splatnosť,
  KS, `items_count`, `total_amount`, `marked_paid`, SoftDeletes) +
  `payment_order_items` (zmrazený dodávateľ/účet/VS/suma; `supplier_invoice_id`
  nullOnDelete — snapshot prežije zmazanie faktúry). Súbory sa neukladajú, generujú sa
  na požiadanie zo snapshotu — opakované stiahnutie je totožné.
- **Jeden príkaz = jedna mena = mena účtu platcu** (`BankAccount.currency`); guardy pri
  vytvorení: faktúra `payable()` (received/booked), má účet, zhodná mena; splatnosť
  v minulosti sa posunie na dnešok (`due_date_adjusted` v response).
- **Endpointy** (`routes/invoicing.php`, policy `PaymentOrderPolicy` =
  `invoices.view`/`manage` + `sameAccount`): `GET payment-orders/candidates`
  (`?bank_account_id=&hide_handed=`, skupiny `abo_eligible`/`sepa_eligible`/`other`,
  `selectable` + lokalizovaný `selectable_reason`), `apiResource payment-orders`
  (index/store/show/destroy), `GET payment-orders/{id}/export/{abo|sepa|csv|pdf}`.
- **Exporty**: `AboKpcBuilder` (Domain) — ABO/KPC „hromadný příkaz k úhradě", pozičný
  ASCII formát (UHL1 hlavička, veta 1501, sumy v halieroch, KS kódovaný spolu so
  smerovým kódom banky príjemcu), len CZK + tuzemské účty, inak 422; **golden-file
  test** (`tests/Fixtures/payment-order/hromadny-prikaz.kpc`, byte-by-byte).
  `SepaPain001Builder` (Domain) — SEPA credit transfer batch, formát
  **pain.001.001.03** (ISO 20022, `DOMDocument`, XSD overený testom proti oficiálnej
  schéme `tests/Fixtures/payment-order/pain.001.001.03.xsd`), len EUR dávky s IBAN
  na platcovi aj na každom riadku (pri chýbajúcom riadkovom IBAN sa skúsi
  `CzechIbanConverter` z tuzemského účtu, inak 422); `EndToEndId` = `/VS…/KS…`
  (bez špecifického symbolu — v tomto kóde sa nikde needviduje), `RmtInf/Ustrd` =
  číslo faktúry dodávateľa; chýbajúci BIC → `Othr/Id=NOTPROVIDED` (IBAN-only
  routing, schéma-validné). `PaymentOrderCsvBuilder` (UTF-8 BOM, `;`),
  `PaymentOrderPdfService` (dompdf, landscape, `payment-order-pdf.blade.php`).
- **Overenie účtu (CZ CRPDPH, prevencia ručenia podľa § 109)**: `CrpdphApiClient`
  v Clients module za `VatPayerAccountRegistryInterface` (SOAP obálka cez
  `Http::withBody`, bez ext-soap; cache deň; `services.crpdph`/`CRPDPH_API_URL`) —
  SK register sa neskôr pridá ako ďalšia implementácia. `POST
  supplier-invoices/{id}/verify-account` porovnáva uložený účet so zverejnenými
  v IBAN priestore (`CzechIbanConverter` — deterministická konverzia tuzemského tvaru),
  uloží `account_verified_at` + `account_verification_result`
  (`published|unpublished|unreliable`); pri nezhode response vypíše zverejnené účty.
  Zmena účtu cez `PUT supplier-invoices/{id}` overenie zresetuje a nastaví
  `account_source = manual`.
- **QR platba per faktúra**: `GET supplier-invoices/{id}/payment-qr` —
  `SupplierPaymentQrService` (CZK → SPAYD, EUR → EPC, inak 422; tuzemský účet sa
  prepočíta na CZ IBAN; SVG data URI).
- **Zoznam prijatých faktúr**: filter `handed=1|0` + účtové polia
  v `SupplierInvoiceResource`.

---

## 14. Štatistiky

Implementované v module Invoicing (`routes/invoicing.php`, prefix `api/v1/statistics`,
`auth:sanctum`), dokumentované samostatne kvôli rozsahu — podrobný plán v
[`docs/PLAN_STATISTIKY.md`](./PLAN_STATISTIKY.md).

| Metóda | URI | Účel |
|---|---|---|
| GET | `/statistics/overview` | KPI karty (tržby/náklady/zisk mesiac/12m/YTD s trendom a YoY %), porovnávacia tabuľka za 5 období, 12-mesačný trendový graf, ziskový graf (mesačný + kumulatívny YTD vs. minulý rok), `assumptions` (disclaimer k definícii nákladov). Cachované 5 minút (`stats:overview:v2:{ownerId}:{currency}:{today}`). |
| GET | `/statistics/tables?year=` | tabuľky tržieb/nákladov/zisku podľa roka a mesiaca, `assumptions` |
| GET | `/statistics/receivables` | vekové pásma (`not_yet_due, d1_30, d31_60, d61_90, d90_plus`) otvorených pohľadávok aj záväzkov, v hotovostnom vyjadrení vrátane DPH |
| GET | `/statistics/partners?limit=` | top klienti, top dodávatelia (natívna mena, bez prepočtu), riziko odchodu |
| GET | `/statistics/health` | DSO/DPO, platobná morálka, koncentrácia tržieb podľa klienta/dodávateľa, cyklus pracovného kapitálu (rolling 12 mesiacov) |

**Definícia tržieb/nákladov** (`RevenueCostAggregator`): tržby = typy `invoice` +
`credit_note` v stavoch `[issued, sent, reminded, paid, credited]` (proforma/storno
vylúčené; `credited` sa počíta, aby sa originál + dobropis vynulovali; zrušené originály sú
vylúčené, lebo `CreateCorrectiveInvoiceAction` ich pri stornovaní automaticky zruší) — počíta
sa na **báze platiteľa dane** (`subtotal` pre platiteľov DPH, inak `total`), datované podľa
DUZP s fallbackom na dátum vystavenia. Náklady = `supplier_invoices` (stavy `received, booked,
paid`) **plus evidované výdavky** (`Expense`, datované podľa vlastného `date`, počíta sa vždy
celá `amount` bez rozpadu DPH — výdavok žiadny rozpad nemá). Mena bez zmrazeného kurzu
(`Expense` nemá vlastný snapshot) ide cez rovnaký `StatisticsCurrencyConverter::
fallbackRateToCzk()` fallback ako pri chýbajúcom kurze faktúry. **Vedomé riziko dvojitého
započítania** (ten istý výdavok zaevidovaný aj ako prijatá faktúra, aj ako `Expense`) sa
nerieši FK ani dedupe — je to disciplína používateľa; API odpoveď preto nesie pole
`assumptions` s týmto upozornením. Cache kľúč `stats:overview:*` je verzovaný (`v2`), aby sa
po nasadení tejto zmeny nečítal starý (pred-Expense) výpočet z cache.

**Prepojenie s upomienkami** — `overdue_reminder_days` na `User` (predvolene 14, rozsah
1–365) sa **nečíta žiadnym naplánovaným príkazom**; slúži len `DashboardService`u na výpočet
zoznamu faktúr po splatnosti nad prahom používateľa (`GET /api/v1/dashboard`), s príznakom
`can_remind` na položku. Samotné odoslanie upomienky je vždy **manuálna akcia na faktúru**
(`POST invoices/{invoice}/remind` → `RemindInvoiceAction`, kapitola 13) — automatický cron
na hromadné rozposielanie upomienok neexistuje.

---

## 15. Kompletný flow: od zákazky po zaplatenú faktúru

1. **Vytvorenie zákazky** (`CreateOrderAction`): fakturovateľná zákazka potrebuje klienta;
   ak má `billing_type` predvolenú sadzbu (čokoľvek okrem `mixed`), vyžaduje sa `rate`,
   ktorá sa hneď zaznamená ako sadzba na úrovni zákazky. Osobná zákazka (bez klienta)
   sadzbu mať nesmie.
2. **Práca sa hromadí** na zákazke cez `OrderItem` (ručné položky služba/produkt/čas) a/alebo
   `TimeEntry` (`is_billable=true, is_invoiced=false`) — zapisované ručne alebo synchronizované
   z Clockify.
3. **Riešenie sadzby**: pri generovaní faktúry `RateResolver::sheetFor()` načíta celú históriu
   sadzieb pre kombináciu (zákazka, klient, používateľ) jedným dopytom; každý časový záznam
   sa ocení sadzbou platnou **v deň, kedy bola práca vykonaná** (poradie: zákazka > klient >
   používateľ; `rate_override` na zázname má vždy prednosť).
4. **`GenerateInvoiceFromOrderAction`**: v jednej DB transakcii — vytvorí draft faktúru
   (naviazanú na klienta zákazky), skopíruje každú `OrderItem` do `InvoiceItem` (so zachovaným
   pôvodom), premení každý fakturovateľný `TimeEntry` na položku faktúry (hodiny zaokrúhlené
   na 2 desatinné miesta, jednotka "hod", cena zo sadzobného hárku) a **označí spotrebované
   časové záznamy ako `is_invoiced = true`** (nemožno ich vyfakturovať dvakrát). Prepočíta
   súčty cez `VatRecapCalculator`. Výsledkom je **draft** faktúra.
5. **Vystavenie** (`IssueInvoiceAction`): zmrazí snapshoty dodávateľa/klienta/bankového účtu
   a (pre cudziu menu) kurz ČNB — neskoršie zmeny profilu už vystavený doklad nezmenia.
6. **Odoslanie** (`SendInvoiceEmailAction`): ak je draft, najprv vystaví, potom pošle e-mail
   klientovi (throttle 10/min na účet).
7. **PDF**: `InvoicePdfController` vygeneruje/zobrazí PDF vrátane platobného QR kódu (SPAYD
   pre CZK, SEPA/EPC pre EUR) a prípadne výkazu víceprác ako druhej strany.
8. **Platba** (`RecordPaymentAction`): `POST invoices/{invoice}/payments` pridá záznam do
   ledgeru; faktúra sa označí ako `Paid` až keď `balance() <= 0` (čiastočné platby ostávajú
   otvorené).
9. **Upomienky** (`RemindInvoiceAction`): len pre `sent`/`reminded`, s cooldownom
   (predvolene 3 dni), posunie stav na `reminded`.
10. **Opravné doklady** (`CreateCorrectiveInvoiceAction`): dobropis alebo storno s opačným
    znamienkom množstva, s odkazom na originál; storno navyše zruší originál.
11. **Opakovaná fakturácia**: nezávisle od zákaziek — `RecurringInvoiceTemplate` +
    denný cron job generujú faktúry priamo zo šablóny, s dobiehaním zameškaných behov a
    voliteľným automatickým odoslaním.

---

## 16. Bezpečnosť a spoločné konvencie

- **`DomainException`** (`app/Modules/Shared/Exceptions`) je celoaplikačná výnimka pre
  porušenie biznis pravidla — controllery ju vždy zachytávajú a menia na `422 JSON {message}`.
  Toto je jednotná konvencia spracovania chýb naprieč všetkými modulmi.
- **Validácia**: Spatie `Data` DTO slúžia zároveň ako typované hodnotové objekty aj ako
  validátory (`static rules()` + validačné atribúty na konštruktore). Ustálený vzor je
  `SomeData::validateAndCreate($request->all())` — zvaliduje a rovno poskladá typovaný
  objekt. (Pozor: base `Illuminate\Http\Request` nemá metódu `validated()`, len
  `FormRequest` ju má — jej volanie hodí `BadMethodCallException`.) Controllery to
  kombinujú s dodatočnými cross-account existenčnými kontrolami
  (`Rule::exists(...)->where('user_id', $ownerId)`) — týmto vzorom sa zabezpečuje, že cudzí
  kľúč (napr. `client_id`, `bank_account_id`, `order_item_id`) musí patriť do rovnakého účtu,
  čím sa predchádza IDOR útokom (prístup k cudzím dátam cez uhádnuté ID).
- **Slovenčina/čeština v používateľských textoch**: chybové hlášky (`DomainException`),
  popisky enumov aj predmety e-mailov sú v slovenčine/češtine — cieľový trh je SK/CZ.
- **Guard `admin`** je úplne oddelený autentifikačný vesmír od bežného `sanctum` guardu
  — iná tabuľka, žiadne prekrývanie oprávnení.
- **Rate limiting**: prihlasovacie/registračné endpointy `throttle:10,1`, e-mailové akcie
  na faktúrach (`invoice-email`) 10/min na používateľa.

---

## 17. Známe medzery / rozpracované časti

Pre úplnosť je dobré vedieť, čo je v kóde pripravené, ale ešte nie je (plne) zapojené —
alebo je vedomé zjednodušenie pre MVP:

- **2FA**: implementované pre bežných používateľov (kapitola 19); back-office `AdminUser`
  má vlastné (zatiaľ nevyužité) stĺpce, ale žiadnu akciu — je aj tak mimo tohto repozitára
  (kapitola 7), no mohol by neskôr znovupoužiť rovnaké akcie/službu (`TwoFactorService`).
- **GDPR — export dát a zmazanie účtu**: `GET /api/v1/profile/export` a
  `DELETE /api/v1/profile` (kapitola 6) robia len minimálny rozsah — dáta pri zmazaní účtu
  ostávajú v DB (vystavené faktúry majú zákonnú retenciu), plný purge/anonymizácia je
  vedomé produktové rozhodnutie mimo rozsahu jadra.
- **Kalendár — presahy udalostí**: `OverlapPolicyInterface` je pripravené rozhranie, OSS
  edícia (`AllowOverlapPolicy`) presahy vôbec nerieši — kontrola je hák pre SaaS (kapitola 12).

SaaS-only funkcionalita (Team, Admin back-office, Subscriptions/Stripe checkout, plná
matica rolí) **nie je medzerou tohto repozitára** — žije zámerne v `qasa_saas` (kapitola 7),
tento repozitár nesie len integračné háky (model zámena, `Gate::before`, `AbilityCatalog`).

---

## 18. Modul Integrations — token scopes a webhooky

API ako produkt: obmedzené (scoped) API tokeny + odchádzajúce webhooky pre doménové udalosti.
Modul `app/Modules/Integrations/` počúva eventy z `Invoicing`, nemá vlastnú doménovú entitu
mimo `WebhookEndpoint`/`WebhookDelivery`.

### Scoped API tokeny (`api/v1/auth/tokens`)

- `POST auth/tokens {name, abilities[]}` — `abilities` musí byť podmnožina
  `AbilityCatalog::abilities()` (validácia `Rule::in`), vráti plaintext token **len raz**
  (`PersonalAccessTokenController::store`). `GET auth/tokens` vypíše metadáta (nikdy nie
  plaintext), `DELETE auth/tokens/{id}` zruší vlastný token.
- **Vynútenie** — `AuthServiceProvider::boot()`, `Gate::before` registrovaný **pred**
  OSS allow-all (kapitola 5): ak je aktuálny `currentAccessToken()` `PersonalAccessToken`
  bez danej schopnosti → `false` (deny má prednosť). Login/register/Google-login tokeny sa
  vytvárajú s predvolenými `['*']`, teda nie sú obmedzené.

### Webhooky — dátový model

- **`WebhookEndpoint`**: `user_id, url, secret` (šifrovaný, `hidden`, vráti sa len raz pri
  vytvorení), `events` (json zoznam wire-eventov), `is_active, consecutive_failures,
  disabled_at, last_success_at, last_failure_at`. `SoftDeletes`, `HasUserScope`.
- **`WebhookDelivery`**: log každého pokusu o doručenie — `webhook_endpoint_id, event,
  payload, attempt, response_status, response_excerpt` (max 1 kB), `delivered_at/failed_at`.
  Len `created_at` (bez `updated_at`), retencia cez `qasa:integrations:purge-webhook-deliveries`
  (predvolene 14 dní, `config('integrations.webhook_delivery_retention_days')`, denne o 4:00).

### Katalóg eventov — `WebhookEventMap`

Jediný zdroj pravdy doménový event → wire meno → tenký payload (id, číslo dokladu, suma,
stav — konzument si detail dotiahne cez API):

| Doménový event | Wire meno |
|---|---|
| `InvoiceCreated` / `InvoiceSent` / `InvoicePaid` / `InvoiceReminded` | `invoice.created/sent/paid/reminded` |
| `InvoiceOverdue` (kapitola 13, automatické upomienky) | `invoice.overdue` |
| `PaymentRecorded` | `payment.recorded` |
| `InboxItemCreated` (nový, vyvolaný v `ScanInboxAction`) | `inbox.item_created` |

`DispatchWebhooks` (listener, registrovaný v `IntegrationsServiceProvider::boot()` na
všetkých 7 eventoch vyššie) beží **synchrónne** (len rýchly lookup aktívnych endpointov
daného účtu) a pre každý prihlásený endpoint zaradí `DeliverWebhookJob` do fronty.

### Doručenie — `DeliverWebhookJob`

- `POST` s JSON telom, hlavičky `X-Qasa-Event`, `X-Qasa-Delivery` (uuid),
  `X-Qasa-Signature: sha256=<HMAC-SHA256 tela so secretom endpointu>`; timeout 5 s, bez
  presmerovaní. `tries = 3`, `backoff = [60, 300, 1800]` (1/5/30 min).
- Každý pokus zapíše `WebhookDelivery` (audit log). `consecutive_failures`/
  `last_success_at`/`last_failure_at` sa menia **len** pri úspechu (reset na 0) alebo v
  `failed()` po vyčerpaní všetkých pokusov (nie po každom čiastkovom pokuse) — pri dosiahnutí
  10 za sebou sa endpoint automaticky deaktivuje (`is_active=false, disabled_at`).
- **SSRF guard** (`App\Modules\Shared\Support\WebhookUrlGuard`, zdieľaný cez
  `SafeWebhookUrl` validačné pravidlo aj re-check priamo v jobe): URL musí byť `https`
  (`http` len keď `app.env=local`), IP po DNS rezolúcii nesmie byť v privátnom/rezervovanom
  rozsahu (`FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE`).

### API endpointy (`api/v1/webhook-endpoints/*`, `auth:sanctum`, ability `integrations.manage`)

`apiResource` (index/show/store/update/destroy) + `POST {id}/test` (synchrónny `ping`,
zdieľa signing cez `WebhookSender` s `DeliverWebhookJob`, tiež zapíše `WebhookDelivery`) +
`GET {id}/deliveries` (stránkovaný log pokusov). `WebhookEndpointPolicy` cez
`sameAccount()` (kapitola 4); `WebhookEndpointResource` nikdy nevracia `secret`.

---

## 19. 2FA (TOTP) pre bežných používateľov

API-first flow (bez Fortify), `pragmarx/google2fa` pre TOTP + `chillerlan/php-qrcode`
(rovnaký balík ako platobné QR, kapitola 13) pre provisioning QR. Platí pre bežný
`sanctum` guard aj pre Google login — **nie** pre `AdminUser`/back-office (iný guard,
mimo tohto repozitára, kapitola 7).

### Dátový model a stav

Tri polia na `User` (kapitola 6): `two_factor_secret` (šifrovaný, base32 TOTP secret),
`two_factor_recovery_codes` (šifrovaný json zoznam hashovaných jednorazových kódov),
`two_factor_confirmed_at`. **2FA je aktívne iba keď `two_factor_confirmed_at` nie je
null** — `enable` samotné len uloží nepotvrdený secret a nemení prihlasovanie.

### Správa (`api/v1/auth/2fa/*`, `auth:sanctum`) — `TwoFactorController`

- `POST enable` — vygeneruje secret (`TwoFactorService::generateSecret()`), uloží ako
  nepotvrdený, vráti `{secret, otpauth_uri, qr_svg}` (SVG data URI, rovnaký
  `chillerlan\QRCode` vzor ako `PaymentQrService::dataUri()`). Odmietne, ak je 2FA už
  potvrdené (`auth.two_factor_already_enabled`).
- `POST confirm {code}` — overí TOTP (`Google2FA::verifyKey`, okno ±1 = ±30 s kvôli
  posunu hodín), nastaví `two_factor_confirmed_at`, vygeneruje a **jediný raz** vráti
  8 recovery kódov (uložené hashované cez `Hash::make`).
- `DELETE /auth/2fa {password, code}` — vyžaduje heslo (okrem Google-only účtov bez
  hesla — rovnaká výnimka ako `DeleteAccountAction`) **a** platný TOTP alebo recovery
  kód; vynuluje všetky tri stĺpce.
- `POST recovery-codes {code}` — regeneruje 8 nových kódov, stará sada prestane platiť.

`VerifyTwoFactorCodeAction` je zdieľaná overovacia logika (TOTP alebo recovery kód) pre
`disable`, `recovery-codes` aj login-challenge nižšie — nájdený recovery kód sa vždy
spotrebuje (odstráni zo zoznamu).

### Login challenge

`LoginAction`/`LoginWithGoogleAction` vracajú `App\Modules\Auth\Application\Results\
LoginResult` (hodnotový objekt namiesto rozlíšenej union pole-štruktúry — kvôli čistému
zúženiu typu na troch call-sitoch). Ak má účet potvrdené 2FA, namiesto tokenu vrátia
`LoginResult::twoFactorChallenge()` → API odpoveď `{two_factor_required: true,
challenge_token}` (bez tokenu). Challenge je **cache záznam** (`TwoFactorChallengeStore`,
`Cache::put`/`Cache::pull`, `Str::random(64)` → user id, TTL 5 min, jednorazový) —
zámerne nie Sanctum token, takže polovičné prihlásenie nemá žiadny API prístup.

`POST /api/v1/auth/2fa/verify {challenge_token, code, device_name?}` (`throttle:10,1`,
bez auth) — skonzumuje challenge (`TwoFactorChallengeStore::consume()`, atomický
get+delete), overí kód (TOTP alebo recovery), vydá plný Sanctum token (rovnaká
device-name dedup ako `LoginAction`). Nesprávny/expirovaný challenge aj nesprávny kód
vracajú `422`.

`UserResource.two_factor_enabled` (`hasTwoFactorEnabled()`) informuje klienta, či má
zobraziť krok 2 pri ďalšom prihlásení.
