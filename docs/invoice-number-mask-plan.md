# Nastavenie masky číselného radu

> Implementačný plán · Qasa Core · modul **Invoicing** + **Auth**

## Kontext

Každá firma čísluje faktúry vlastným systémom (napr. `20260001`, `2601001`, `FA-2026-001`). Dnes je formát **napevno zadrôtovaný** v `EloquentInvoiceRepository::nextInvoiceNumber()` ako `{prefix}-{YYYY}-{NNN}` (napr. `FA-2026-001`), kde `prefix` je stĺpec `users.invoice_prefix`. Používateľ vie zmeniť len prefix, nie štruktúru čísla.

Cieľom je umožniť používateľovi v profile definovať **masku** so zástupnými znakmi (napr. `{YYYY}{NNNN}` → `20260001`, `{YY}01{NNN}` → `2601001`), ktorú generovanie čísla zohľadní.

**Potvrdené rozhodnutia:**

- **Rozsah:** maska sa vzťahuje na **všetky typy dokladov**. Oddelenie číselných radov zabezpečí typový prefix vložený pred masku (`PF-`, `DB-`, `ST-`) — každý typ tak má samostatnú sekvenciu.
- **Placeholdery:** `{YYYY}` (2026), `{YY}` (26), `{MM}` (07), `{DD}` (09) a jeden sekvenčný token `{N}`/`{NN}`/`{NNN}`/`{NNNN}`… kde počet `N` = šírka doplnenia núl.
- **Počiatočné číslo (`invoice_number_start`):** konfigurovateľná spodná hranica poradia. Pokrýva migráciu z iného systému (napr. ďalšia faktúra = `501`) aj vlastný štart. **Reset určuje maska**, nie start: token `{YYYY}`/`{MM}` v maske → ročný/mesačný reštart; maska bez dátumu → priebežné číslovanie. Floor sa aplikuje pri každom novom období bez faktúr: `next = max(existujúceMax, start-1) + 1`.

| Požiadavka | Maska | Start |
|---|---|---|
| Štart od `00001` | `{YYYY}{NNNNN}` | 1 (default) |
| Migrácia — pokračovať od 501 | `{NNNNN}` (bez roku) | 501 |
| Ročný reset od 1 | `{YYYY}{NNNN}` | 1 |

**Spätná kompatibilita:** existujúci používatelia majú masku `null` → použije sa doterajší formát `{prefix}-{YYYY}-{NNN}`, výstup ostáva bitovo identický.

---

## Návrh

### 1. Jadro: value object `InvoiceNumberMask` (Domain/Services)

Nový súbor `app/Modules/Invoicing/Domain/Services/InvoiceNumberMask.php` — celá logika formátovania aj extrakcie sekvencie na jednom mieste (nahrádza dnešný `sprintf` + `explode` v repozitári). `final`, `declare(strict_types=1)`.

Placeholdery a jeden povinný sekvenčný token `{N+}`:

- **`format(int $sequence, CarbonImmutable $date): string`** — nahradí dátumové tokeny fixnými hodnotami a `{N+}` → `str_pad` na šírku podľa počtu `N`. Napr. maska `{YYYY}{NNNN}`, seq 1 → `20260001`.
- **`sequenceRegex(CarbonImmutable $date): string`** — z masky s dosadeným dátumom vyrobí PCRE: literály `preg_quote`-nuté, `{N+}` → `(\d+)`, zakotvené `^…$`. Slúži na vytiahnutie poradia z existujúcich čísel **daného obdobia**.
- **`extractSequence(string $number, CarbonImmutable $date): ?int`** — aplikuje `sequenceRegex`; `null` ak nezhoduje.
- **`likePrefix(CarbonImmutable $date): string`** — literál pred sekvenčným tokenom s dosadeným dátumom (na zúženie SQL `LIKE`).
- **`public static function isValid(string $mask): bool`** — práve jeden `{N+}` token, iba povolené placeholdery, žiadne neznáme `{...}`, neprázdne.

> Rozdelenie masky okolo sekvenčného tokenu je jednoznačné: dátumové tokeny (`{YYYY}`, `{YY}`, `{MM}`, `{DD}`) sa neprekrývajú s `{N+}`, kotvenie `^…$` odstraňuje nejednoznačnosť medzi rokom a poradím (napr. `^2026(\d+)$` na `20260001` korektne zachytí `0001`).

### 2. Voľba masky podľa typu — `InvoiceType`

`app/Modules/Invoicing/Domain/Enums/InvoiceType.php` — pridať `numberMask(User $user): string`:

```php
$mask = $user->accountOwner()->invoice_number_mask;
if ($mask !== null && $mask !== '') {
    return match ($this) {
        self::Invoice    => $mask,
        self::Proforma   => 'PF-'.$mask,
        self::CreditNote => 'DB-'.$mask,
        self::Storno     => 'ST-'.$mask,
    };
}
// legacy default – zachová súčasné výstupy
return $this->numberPrefix($user->accountOwner()->invoice_prefix).'-{YYYY}-{NNN}';
```

Ponecháva existujúcu `numberPrefix()` pre default vetvu (`FA`/`PF`/`DB`/`ST`), takže null-maska = doterajšie správanie.

### 3. Repozitár — `nextInvoiceNumber` cez masku

`app/Modules/Invoicing/Infrastructure/Repositories/EloquentInvoiceRepository.php` + interface `app/Modules/Invoicing/Application/Contracts/InvoiceRepositoryInterface.php`.

Zmeniť signatúru z `nextInvoiceNumber(string $userId, string $prefix)` na `nextInvoiceNumber(string $userId, InvoiceNumberMask $mask, int $start = 1)`. Nová implementácia zachová dnešnú logiku zamykania a „vrátane trashed" (nikdy nereciklovať čísla) a pridá **floor** cez `$start`:

```php
$now = CarbonImmutable::now();
DB::table('users')->where('id',$userId)->lockForUpdate()->value('id'); // serializácia per účet
$last = (int) Invoice::withoutGlobalScope('user')->withTrashed()
    ->where('user_id',$userId)
    ->where('invoice_number','like', addcslashes($mask->likePrefix($now),'%_').'%')
    ->pluck('invoice_number')
    ->map(fn(string $n) => $mask->extractSequence($n,$now))
    ->filter(fn(?int $s) => $s !== null)
    ->max();
$next = max($last, max(1, $start) - 1) + 1; // start = spodná hranica poradia
return $mask->format($next, $now);
```

Nezávislé rady: rôzne obdobie (`{YYYY}`/`{MM}` sa zmení → iný literál → žiadna zhoda → poradie začne od 1) aj rôzny typ (`PF-`/`DB-`/`ST-` prefix) automaticky oddelia sekvencie. `variable_symbol` sa naďalej odvodí z čísla cez `CreateInvoiceAction::variableSymbolFromNumber()` — bez zmeny.

### 4. Aktualizovať volajúcich

- `CreateInvoiceAction.php:32` — `prefix: $data->type->numberPrefix(...)` → `mask: $data->type->numberMask($user), start: $user->accountOwner()->invoice_number_start ?? 1`.
- `CreateCorrectiveInvoiceAction.php:56` — analogicky `mask: $type->numberMask($user), start: $user->accountOwner()->invoice_number_start ?? 1`.

*(Iní volajúci nie sú — `GenerateInvoiceFrom*Action` delegujú na `CreateInvoiceAction`.)*

### 5. Perzistencia stĺpcov `invoice_number_mask` + `invoice_number_start`

- **Migrácia** (nová, `database/migrations/2026_07_09_000001_add_invoice_number_mask_to_users.php`):
  ```php
  $table->string('invoice_number_mask', 40)->nullable()->after('invoice_prefix');
  $table->unsignedInteger('invoice_number_start')->nullable()->after('invoice_number_mask');
  ```
- **Model** `app/Modules/Auth/Domain/Models/User.php` — pridať oba stĺpce do `$fillable` (riadok ~144), `@property ?string $invoice_number_mask` a `@property ?int $invoice_number_start` do docblocku, `invoice_number_start` do `$casts` ako `'integer'`.
- **Factory** `database/factories/.../UserFactory.php` — oba default `null` (dedia legacy správanie); netreba meniť.

### 6. Profilový endpoint (Auth)

- `app/Modules/Auth/Application/DTOs/UpdateProfileData.php` — pridať polia `?string $invoice_number_mask = null` a `?int $invoice_number_start = null`; do `rules()`:
  ```php
  'invoice_number_mask'  => ['sometimes','nullable','string','max:40', new ValidInvoiceNumberMask],
  'invoice_number_start' => ['sometimes','nullable','integer','min:1','max:99999999'],
  ```
  do `fromRequest()` mapovanie cez `$request->string(...)` resp. `$request->integer(...)`.
- `app/Modules/Auth/Application/Actions/UpdateProfileAction.php` — pridať `'invoice_number_mask'` a `'invoice_number_start'` do updateu. **Pozn.:** dnešný `array_filter(fn($v)=>$v!==null)` zahodí `null`, čiže cez DTO sa nedá pole *vymazať* späť na default. Aby šlo masku/start zrušiť (návrat na legacy `FA-{YYYY}-{NNN}` / start 1), spracovať tieto dve polia mimo filtra: prázdny reťazec `""` → `null` a zapísať priamo do `$updateData`.
- `app/Modules/Auth/Presentation/Resources/UserResource.php` — vystaviť `invoice_number_mask` a `invoice_number_start` (+ `OA\Property`).
- `app/Modules/Auth/Presentation/Controllers/AuthController.php` — doplniť `OA\Property` pre obe polia do schémy update-profile requestu.

### 7. Validačné pravidlo

Nový súbor `app/Modules/Invoicing/Domain/Rules/ValidInvoiceNumberMask.php` (implementuje `Illuminate\Contracts\Validation\ValidationRule`), deleguje na `InvoiceNumberMask::isValid()`; chybová hláška cez `__('invoicing.invalid_number_mask')`.

> **Architektonická pozn.:** Auth DTO tu odkazuje na Invoicing pravidlo. Je to zámerné — sémantika masky je doménová znalosť Invoicing; `Invoicing` už závisí na `Auth\User`, opačný odkaz je len pri validácii jedného poľa.

### 8. Lokalizácia

- `lang/en/invoicing.php` + `lang/sk/invoicing.php` — pridať kľúč `invalid_number_mask` (EN aj SK). Príklad SK: *„Maska musí obsahovať práve jeden sekvenčný token ({N}, {NN}, …) a len povolené polia {YYYY}, {YY}, {MM}, {DD}."*
- Bez zadrôtovaných reťazcov v kóde (dodržať CLAUDE.md pravidlo).

---

## Kritické súbory

| Súbor | Zmena |
|---|---|
| `Invoicing/Domain/Services/InvoiceNumberMask.php` | **nový** – formát + extrakcia + validácia |
| `Invoicing/Domain/Rules/ValidInvoiceNumberMask.php` | **nový** – Laravel rule |
| `Invoicing/Domain/Enums/InvoiceType.php` | `numberMask(User)` |
| `Invoicing/Infrastructure/Repositories/EloquentInvoiceRepository.php` | `nextInvoiceNumber` cez masku |
| `Invoicing/Application/Contracts/InvoiceRepositoryInterface.php` | signatúra |
| `Invoicing/Application/Actions/CreateInvoiceAction.php` | volanie |
| `Invoicing/Application/Actions/CreateCorrectiveInvoiceAction.php` | volanie |
| `Auth/Domain/Models/User.php` | fillable + property + cast (mask, start) |
| `Auth/Application/DTOs/UpdateProfileData.php` | polia + rules + fromRequest |
| `Auth/Application/Actions/UpdateProfileAction.php` | uloženie (+ `""`→`null`) |
| `Auth/Presentation/Resources/UserResource.php` | výstup (mask, start) |
| `Auth/Presentation/Controllers/AuthController.php` | OA anotácia (mask, start) |
| `database/migrations/2026_07_09_000001_add_invoice_number_mask_to_users.php` | **nové** stĺpce (mask, start) |
| `lang/{en,sk}/invoicing.php` | kľúč `invalid_number_mask` |

---

## Verifikácia

1. **Statická analýza:** `composer phpstan` (level 8) + `vendor/bin/pint --dirty`.
2. **Unit test** `tests/Unit/Invoicing/InvoiceNumberMaskTest.php` (nový) — pokryť:
   - `{YYYY}{NNNN}` seq 1 → `20260001`; seq 42 → `20260042`.
   - `{YY}01{NNN}` → `2601001`.
   - legacy `FA-{YYYY}-{NNN}` → `FA-2026-001` (spätná kompatibilita).
   - `extractSequence` round-trip a nezhoda pri inom období/type.
   - `isValid`: odmietne 0 alebo 2 sekvenčné tokeny, neznámy `{XX}`.
   - **start floor:** výpočet `next` s `start=501` na prázdnom období → prvé číslo 501; s existujúcim max 600 → 601.
3. **Feature test** (rozšíriť štýl `tests/Feature/Invoicing/InvoiceDocumentTypeTest.php`):
   - Používateľ s `invoice_number_mask = '{YYYY}{NNNN}'` → faktúry `20260001`, `20260002`; proforma `PF-20260001` (nezávislý rad).
   - Používateľ s maskou `{NNNNN}` a `invoice_number_start = 501` → prvá faktúra `00501`, druhá `00502` (migračný scenár).
   - Používateľ **bez** masky/startu → stále `FA-2026-001` (regresný dôkaz).
   - `PATCH /api/v1/profile` s neplatnou maskou (`{YYYY}` bez `{N}`) → 422; `invoice_number_start = 0` → 422.
4. **Ručne:** `PATCH /api/v1/profile { "invoice_number_mask": "{YY}01{NNN}" }` → `POST /api/v1/invoices` → očakávané číslo `2601001`; nastavenie `{ "invoice_number_mask": "{NNNNN}", "invoice_number_start": 501 }` → `00501`; zmazanie masky (poslať `""`) → návrat na `FA-2026-001`.
