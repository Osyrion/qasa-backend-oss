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
7. [Modul Team — tímy a pozvánky](#7-modul-team--tímy-a-pozvánky)
8. [Modul Admin — administrátorský back-office](#8-modul-admin--administrátorský-back-office)
9. [Modul Clients — klienti a kontaktné osoby](#9-modul-clients--klienti-a-kontaktné-osoby)
10. [Modul Orders — zákazky](#10-modul-orders--zákazky)
11. [Modul Pricing — sadzby a cenníky](#11-modul-pricing--sadzby-a-cenníky)
12. [Modul TimeTracking — časové výkazy a výdavky](#12-modul-timetracking--časové-výkazy-a-výdavky)
13. [Modul Invoicing — fakturácia](#13-modul-invoicing--fakturácia)
14. [Moduly Subscriptions a Saas — predplatné a edícia](#14-moduly-subscriptions-a-saas--predplatné-a-edícia)
15. [Kompletný flow: od zákazky po zaplatenú faktúru](#15-kompletný-flow-od-zákazky-po-zaplatenú-faktúru)
16. [Bezpečnosť a spoločné konvencie](#16-bezpečnosť-a-spoločné-konvencie)
17. [Známe medzery / rozpracované časti](#17-známe-medzery--rozpracované-časti)

---

## 1. Prehľad a technologický stack

Qasa je API backend pre fakturačný/zákazkový systém (podobný napr. Fakturoid alebo Pohoda,
ale s dôrazom na sledovanie zákaziek a času). Postavený je na:

| Oblasť | Technológia |
|---|---|
| Jazyk / framework | PHP ^8.4, Laravel ^13.0 (`declare(strict_types=1)` povinné vo všetkých súboroch) |
| Databáza | PostgreSQL/MySQL cez Eloquent ORM |
| Autentifikácia (API) | Laravel Sanctum (token-based, nie session cookies) |
| Sociálne prihlásenie | Laravel Socialite (Google) |
| Platby / predplatné | Laravel Cashier (Stripe) |
| Role a oprávnenia | Spatie Laravel Permission |
| DTO / validácia | Spatie Laravel Data |
| Query building | Spatie Laravel Query Builder |
| PDF | barryvdh/laravel-dompdf |
| QR platby | chillerlan/php-qrcode |
| CSV import | league/csv |
| Testy | Pest PHP ^3.0 / PHPUnit ^11.3 |
| Statická analýza | PHPStan ^2.0, Psalm ^6.0, Laravel Pint ^1.27 |
| API dokumentácia | L5-Swagger (OpenAPI) |
| MCP server | `laravel/mcp` (`Mcp::local('qasa', QasaServer::class)`) |

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

Existujúce moduly:

- **Auth** — používatelia, prihlásenie, registrácia, profil
- **Team** — tímy, pozvánky, delegovanie rolí (len SaaS)
- **Admin** — back-office pre prevádzkovateľa platformy
- **Clients** — klienti (zákazníci) a ich kontaktné osoby
- **Orders** — zákazky (projekty/kontrakty)
- **Pricing** — sadzby a cenníky služieb/produktov
- **TimeTracking** — časové záznamy, výdavky, kurzové lístky
- **Invoicing** — faktúry, platby, PDF, opakovaná fakturácia
- **Subscriptions** — dátový model predplatných plánov (Stripe/Cashier)
- **Saas** — "lepidlo" pre SaaS edíciu (nie je to biznis modul)
- **Shared** — spoločné traits, enumy, politiky, výnimky

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

Role a oprávnenia (Spatie Permission) sa reálne používajú **len v SaaS edícii** — v OSS
edícii ich obchádza `Gate::before` (kapitola 3).

**Role tímu** — `App\Modules\Team\Domain\Enums\TeamRole`: `Owner`, `Admin`, `Member`, `Viewer`,
s metódou `canGrant()` implementujúcou pravidlo "môžem udeliť len rovnakú alebo nižšiu rolu,
akú mám sám".

Jediný zdroj pravdy pre matricu rolí je `App\Modules\Team\Domain\PermissionCatalog`:

| Oprávnenie | Owner | Admin | Member | Viewer |
|---|:---:|:---:|:---:|:---:|
| `clients.view` / `orders.view` / `timetracking.view` / `invoices.view` / `pricing.view` / `reports.view` | ✅ | ✅ | ✅ | ✅ |
| `clients.manage` / `orders.manage` / `timetracking.manage` | ✅ | ✅ | ✅ | ❌ |
| `invoices.manage` / `pricing.manage` / `team.manage` | ✅ | ✅ | ❌ | ❌ |
| `team.view` | ✅ | ✅ | ✅ | ✅ |
| `billing.manage` | ✅ | ❌ | ❌ | ❌ |

Samostatný guard `admin` (back-office) má vlastnú sadu oprávnení
(`admin.users.view/manage`, `admin.activity.view`, `admin.billing.manage`,
`admin.support.manage`) — v kóde sa však reálne kontroluje jednoduchšie pole
`AdminUser.role` (`super_admin | support | billing`), nie Spatie permissions
(pozri kapitolu 8).

Role a oprávnenia zakladá `RolePermissionSeeder` (idempotentný, `findOrCreate`/`syncPermissions`),
ktorý sa spúšťa cez `DatabaseSeeder` na základe zoznamu v `config('qasa.seeders')` — čisté
jadro (OSS) neseeduje nič. `AdminUserSeeder` sa **nespúšťa automaticky** a vyžaduje env
premennú `ADMIN_SEED_PASSWORD`.

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

Rovnaký route súbor (`auth.php`) registruje aj `GET /api/v1/dashboard`
(`DashboardController` → `DashboardService`, middleware `auth:sanctum`) — agregované
súhrnné dáta pre prihláseného používateľa.

### Dátový model `User`

UUID primárny kľúč, `SoftDeletes`, `HasApiTokens`, `Notifiable`. Kľúčové polia: `title, name,
surname, email, phone, password` (nullable — `null` znamená účet len cez Google),
`google_id, avatar_path, color, ico, dic, is_vat_payer, tax_flat_rate` (0–80 %, 0 = skutočné
výdavky), `default_currency, invoice_prefix, locale, country, address, city, postal_code,
logo_path, vat_id, website, invoice_footer_text, clockify_api_key` (šifrované),
`clockify_workspace_id`. Vzťahy: `clients, orders, orderNotes, orderAttachments, timeEntries,
expenses, exchangeRates, invoices`.

### Krok za krokom

1. **Registrácia** (`RegisterUserAction`): vytvorí používateľa (rešpektuje SaaS zámenu modelu),
   vyvolá event `UserRegistered` (SaaS listener `AssignOwnerRole` mu pridelí rolu `owner`),
   následne sa **mimo** DB transakcie pošle overovací e-mail (`rescue(...)`) — zlyhanie mailu
   tak nikdy nezruší úspešnú registráciu.
2. **Prihlásenie** (`LoginAction`): vyhľadá podľa e-mailu, odmietne Google-only účty
   (`password === null`), overí heslo, **zmaže existujúci token s rovnakým názvom zariadenia**
   pred vydaním nového (zabraňuje hromadeniu tokenov z jedného zariadenia).
3. **Google prihlásenie** (`LoginWithGoogleAction`): ak e-mail z Google účtu už existuje,
   len sa naň naviaže `google_id`; ak neexistuje, nový účet sa vytvorí len ak je
   `qasa.features.registration` zapnuté (inak `ValidationException` — "Účet s týmto e-mailom
   neexistuje."). Používa sa `Socialite::driver('google')->stateless()` (vhodné pre mobil/SPA).
4. **Reset hesla**: štandardný Laravel `Password` broker, `ResetPassword::createUrlUsing()`
   je prepísaný tak, aby smeroval na SPA (`{FRONTEND_URL}/reset-password?token=…`).
   Po úspešnom resete sa **zneplatnia všetky API tokeny** používateľa (vynútené opätovné
   prihlásenie na všetkých zariadeniach).
5. **Overenie e-mailu**: podpísaný (`signed`) link — nevyžaduje prihlásenie, dôkazom je HMAC
   podpis; kontrola prebieha manuálnym `hash_equals()` porovnaním hashu.

> 2FA nie je pre bežných používateľov implementované. Stĺpce pre 2FA existujú na `AdminUser`,
> ale nie sú zapojené (pozri kapitolu 17).

---

## 7. Modul Team — tímy a pozvánky

*(Platí len pre SaaS edíciu.)*

### Endpointy (`api/v1/team/*`)

| Metóda | URI | Účel | Oprávnenie |
|---|---|---|---|
| POST | `/invitations/accept` | prijatie pozvánky (verejné, token = dôkaz totožnosti) | — |
| GET | `/members` | zoznam členov tímu | `team.view` |
| GET | `/invitations` | zoznam čakajúcich pozvánok | `team.view` |
| GET | `/catalog` | katalóg rolí/oprávnení, ktoré môže aktuálny používateľ udeliť | len `auth:sanctum` |
| PUT | `/members/{member}` | zmena role/oprávnení člena | `team.manage` |
| DELETE | `/members/{member}` | odobratie člena | `team.manage` |
| POST | `/invitations` | vytvorenie pozvánky | `team.manage` |
| DELETE | `/invitations/{invitation}` | zrušenie pozvánky | `team.manage` |

### Dátový model `TeamInvitation`

`owner_id, email, role (TeamRole), permissions (json), token` (uložený ako sha256 hash),
`expires_at, accepted_at, created_by`.

### Krok za krokom

1. **Pozvanie** (`InviteMemberAction`): overí, že pozývajúci môže udeliť danú rolu/oprávnenia
   (`canGrant` + kontrola podmnožiny oprávnení), odmietne duplicitné pozvanie na existujúci
   e-mail alebo už čakajúcu pozvánku, **skontroluje limit miest** podľa predplatného plánu
   (`plan->withinLimit('max_users', seats)`), vygeneruje 64-znakový token (uloží sa hashovaný),
   odošle e-mail (`InvitationNotification`), platnosť pozvánky 7 dní.
2. **Prijatie** (`AcceptInvitationAction`): overí token (hash) + platnosť, vytvorí člena
   (`User` s `owner_id`), skopíruje predvolené hodnoty vlastníka (mena, jazyk, krajina,
   prefix faktúr), pridelí rolu/oprávnenia, označí pozvánku ako prijatú, vydá Sanctum token
   (automatické prihlásenie hneď po prijatí pozvánky).
3. **Zmena role / odobratie** (`UpdateMemberRoleAction`/`RemoveMemberAction`): vždy sa overuje
   príslušnosť k rovnakému účtu a pravidlo "rovnaká alebo nižšia rola"; vlastníka ani seba
   samého nemožno odobrať; pri odobratí sa zmažú tokeny a role/oprávnenia člena.

---

## 8. Modul Admin — administrátorský back-office

Samostatný svet od bežných používateľov — vlastný model `AdminUser` (nie je súčasťou
tabuľky `users`), vlastný guard `admin`.

### Dátové modely

- **`AdminUser`**: `name, email, password, role` (`super_admin | support | billing` — jednoduchý
  reťazec, nie enum), `is_active, last_login_at, last_login_ip, login_count`, stĺpce pre 2FA
  (nevyužité), `timezone, locale, notes, created_by`.
- **`ActivityLog`**: univerzálny audit log (`admin_user_id, action, subject_type, subject_id,
  payload, description, ip_address, user_agent, url, country, city, result` — `success/failure/error`).
  Statické helpery `record()/recordSuccess()/recordFailure()/recordError()` automaticky
  zachytávajú prihláseného admina a metadáta requestu.

### Endpointy (`api/v1/admin/*`)

| Metóda | URI | Účel | Middleware |
|---|---|---|---|
| POST | `/auth/login` | prihlásenie admina | `throttle:10,1` |
| POST | `/auth/logout` | odhlásenie | `admin.access` |
| GET | `/auth/me` | profil admina | `admin.access` |
| GET/POST/PUT/DELETE | `/users[/…]` | správa admin účtov | `admin.access` + `admin.role:super_admin` |
| POST | `/users/{user}/ban`, `/unban` | zablokovanie/odblokovanie | `admin.access` + `admin.role:super_admin` |

Správu admin účtov môže robiť **len `super_admin`** — role `support`/`billing` zatiaľ nemajú
žiadne vlastné endpointy okrem prihlásenia. Každá mutácia sa zaznamenáva do `ActivityLog`.

---

## 9. Modul Clients — klienti a kontaktné osoby

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

## 10. Modul Orders — zákazky

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

## 11. Modul Pricing — sadzby a cenníky

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

## 12. Modul TimeTracking — časové výkazy a výdavky

### Endpointy

Routy registruje `routes/time-tracking.php` (načítané cez `TimeTrackingServiceProvider`),
všetky pod prefixom `api/v1` a middleware `auth:sanctum`:

| Metóda | URI | Účel |
|---|---|---|
| POST | `/time-entries/sync/clockify` | synchronizácia časových záznamov z Clockify |
| — | `/expenses` (`apiResource`) | plný REST nad výdavkami (`ExpenseController`) |
| GET/POST/DELETE | `/exchange-rates` | kurzové lístky (`ExchangeRateController`, len `index`/`store`/`destroy`) |

### Dátové modely

- **`TimeEntry`**: `user_id, order_id, order_item_id, description, started_at, ended_at`
  (`null` = beží časovač), `duration_seconds, rate_override, vat_rate, is_billable,
  is_invoiced, source, external_id` (deduplikačný kľúč pre Clockify/Toggl sync). Metóda
  `stop()` dopočíta `ended_at`/`duration_seconds`.
- **`Expense`**: `description, category` (`office|travel|software|hardware|marketing|other`),
  `amount, currency, date, note`.
- **`ExchangeRate`**: `user_id` (nullable = systémový kurz), `base_currency, target_currency,
  rate, date, source`.

### Integrácie

`ClockifyApiClient` + CSV parsery (`ClockifyCsvParser`, `TogglCsvParser`) pre import časových
záznamov. `CnbApiRateClient` (Česká národná banka) poskytuje výmenné kurzy — používa ho
tento modul aj `IssueInvoiceAction` v module Invoicing pri "zmrazení" kurzu na faktúre
v cudzej mene.

### Väzba na Orders/Invoicing

Časový záznam patrí k zákazke (`order_id`). Záznamy, ktoré sú `is_billable = true` a
`is_invoiced = false`, sú presne tie, ktoré `GenerateInvoiceFromOrderAction` pri fakturácii
vezme a po vyfakturovaní označí `is_invoiced = true`, aby sa nedali vyfakturovať dvakrát.

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
  doklade), `status` (`draft|issued|sent|reminded|paid|cancelled|credited`), `issued_at,
  taxable_supply_at` (DUZP), `due_at, variable_symbol, bank_account_id`,
  `bank_account_snapshot/supplier_snapshot/client_snapshot` (json, zmrazené pri vystavení),
  `discount_percent/discount_amount, currency, exchange_rate_snapshot` (ČNB→CZK, zmrazený),
  `subtotal/vat_amount/total, note/note_above, recurring_template_id, emailed_at/emailed_to/
  emailed_cc/email_failed_at, last_reminded_at, reminder_count`. `balance()` = `total - suma
  platieb`.
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

### PDF a platobné QR

- **`InvoicePdfService`** (dompdf) — vykreslí Blade šablónu, zámerne vypína `isRemoteEnabled`
  a `isPhpEnabled` (ochrana proti SSRF/RCE cez injektáž do šablóny), vloží logo dodávateľa
  ako base64, rieši lokalizáciu podľa jazyka klienta/používateľa.
- **`PaymentQrService`** — SVG QR kód: **CZK → SPAYD** ("QR platba"), **EUR → SEPA EPC**,
  iné meny → bez QR. Vyžaduje IBAN na bankovom účte.
- **`VatRecapCalculator`** — DPH sa počíta **podľa sadzbovej skupiny** (rekapitulačná
  tabuľka), zľava na úrovni faktúry sa aplikuje pomerne pred výpočtom DPH (česká/slovenská
  účtovná konvencia); generuje aj CZK prepočet podľa zmrazeného kurzu.

### Hromadný export faktúr — Pohoda XML a CSV

Účtovníci potrebujú na konci obdobia vyviezť vydané faktúry do svojho účtovného softvéru.
Dva independentné endpointy (`/api/v1/invoices/export/pohoda` a `/api/v1/invoices/export/csv`),
zdieľajúce rovnaký filter DTO:

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

---

## 14. Moduly Subscriptions a Saas — predplatné a edícia

**Subscriptions** obsahuje zatiaľ **len doménové modely**, žiadnu Application/Presentation
vrstvu:

- **`SubscriptionPlan`**: `name, slug, stripe_price_id, price, currency, interval, limits`
  (json: `max_clients/max_orders/max_users/…`), `is_active, is_public, sort_order,
  trial_days, features, badge_text/badge_color`. `getLimit()/withinLimit()` (`-1` = bez limitu).
- **`Subscription`/`SubscriptionItem`**: lokálne zrkadlo Cashier/Stripe stavu (`stripe_id,
  stripe_status, stripe_price, quantity, trial_ends_at, ends_at`).

V tomto repozitári **nie je žiadny SubscriptionController, checkout endpoint ani Stripe
webhook** — v súlade s konceptom otvoreného jadra to zjavne žije v uzavretom `qasa_saas`
repozitári; tento repozitár nesie len dátový model plánov/limitov.

**Vynucovanie limitov funkcií** prebieha priamo na `Saas\User`: `currentPlan()` =
`accountOwner()->subscription('default')?->plan` (Cashier vzťah), s helpermi
`isOnPlan()/isOnStarter()/isOnPro()/hasFeature()/withinLimit()`. Napríklad limit počtu
členov tímu (`max_users`) kontroluje `InviteMemberAction` presne cez tento mechanizmus —
ak účet nemá žiadne predplatné, limit sa nevynucuje.

**Saas** modul je len "lepidlo" edície (nie biznis modul): migrácie pre admin/activity/
subscription/permission tabuľky, stĺpce `owner_id` na `users`, a seedery
(`RolePermissionSeeder`, `AdminUserSeeder`). Jediný integračný bod je `SaasServiceProvider`
(kapitola 3).

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

Pre úplnosť je dobré vedieť, čo je v kóde pripravené, ale ešte nie je (plne) zapojené:

- **2FA**: stĺpce existujú na `AdminUser` (`two_factor_secret`, `two_factor_recovery_codes`,
  `two_factor_enabled`), ale žiadny controller/akcia ich reálne neoveruje.
- **Subscriptions modul**: chýba `SubscriptionController`, checkout endpoint aj Stripe
  webhook handler — očividne súčasť uzavretého `qasa_saas` repozitára, tento repozitár
  nesie len dátový model.
- **Admin oprávnenia**: deklarované Spatie oprávnenia (`admin.*`) v `PermissionCatalog`
  sa v kóde reálne nekontrolujú — autorizácia beží cez pole `AdminUser.role` a middleware
  `admin.role:...` (`EnsureAdminRole`).

Nedávno doplnené (predtým uvádzané ako medzery, dnes už zapojené):

- **TimeTracking V2 routy** — `ExpenseController` (`apiResource expenses`) a
  `ExchangeRateController` (`exchange-rates`, `index`/`store`/`destroy`) sú registrované
  v `routes/time-tracking.php`.
- **Dashboard** — `DashboardController`/`DashboardService` sú napojené na
  `GET /api/v1/dashboard` (`auth.php`, middleware `auth:sanctum`).
- **Google OAuth konfigurácia** — `config/services.php` obsahuje kľúč `google` a
  `.env.example` má `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`/`GOOGLE_REDIRECT_URI`.
- **Invoice number mask** — používatelia si môžu definovať vlastnú masku číslovania faktúr
  (napr. `{YYYY}{NNNN}`), podporuje reset podľa roku/mesiaca, spätne kompatibilný (null maska
  = doterajší formát).
- **Hromadný export faktúr** — endpointy `invoices/export/pohoda` (Stormware XML) a
  `invoices/export/csv` s filtrom podľa obdobia a typu dokladu — určeno pre účtovníkov.
- **Exports pre Pohodu** — mapovanie DPH sadzieb na `rateVAT`, zmrazené snapshoty,
  CZK prepočet cez `czkRecap()` pre cudzie meny.
