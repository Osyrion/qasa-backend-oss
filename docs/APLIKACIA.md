# Qasa — technická špecifikácia API backendu

> Tento dokument popisuje architektúru, technologický stack a modulárnu štruktúru backendu aplikácie Qasa.

## 1. Technologický stack

Aplikácia je vyvíjaná ako moderné API-first riešenie s dôrazom na typovú bezpečnosť a čistú architektúru.

- **Jazyk / Framework:** PHP 8.4 (striktné typovanie), Laravel 13
- **Databáza:** PostgreSQL + Eloquent ORM
- **Autentifikácia:** Laravel Sanctum (Token-based) + Laravel Socialite (Google OAuth)
- **Kvalita kódu & Testy:** Pest PHP 3.0, PHPStan (Larastan, Level 8), Laravel Pint
- **Dokumentácia API:** OpenAPI (L5-Swagger)
- **Kľúčové knižnice:** Spatie Laravel Data (DTOs), Spatie Laravel Query Builder, barryvdh/laravel-dompdf, chillerlan/php-qrcode

## 2. Architektúra: Modulárny monolit

Kód je striktne rozdelený do izolovaných modulov v `app/Modules/*`. Každý modul rešpektuje vrstvenú architektúru:

- **Domain:** Entity (Eloquent modely), hodnotové objekty, enamy, biznis logika.
- **Application:** DTOs, Actions, Services, Eventy a Listenery.
- **Infrastructure:** Implementácie externých služieb, Service Providery.
- **Presentation:** API Controllery, FormRequesty, API Resources a definície rout.

## 3. Prehľad modulov (Core)

- **Auth:** Správa používateľov, registrácia, 2FA (TOTP) podpora, GDPR export/zmazanie.
- **Clients:** Správa odberateľov a dodávateľov, integrácia na štátne registre (ARES, RPO) a VIES overovanie.
- **Orders:** Kontajnery pre projekty a zákazky, riadenie stavov a rozpočtov.
- **Pricing:** Flexibilný systém sadzieb (RateResolver) s hierarchiou podľa zákazky, klienta a globálnych nastavení.
- **TimeTracking:** Časové výkazy, integrácia s externými nástrojmi (Clockify sync, CSV importy), správa firemných výdavkov.
- **Calendar:** Správa udalostí prepojených na projekty, import/export (iCal/ICS), automatická retencia dát.
- **Invoicing:** Životný cyklus daňových dokladov (draft, vydaná, proforma, dobropis). Podpora pre variabilné číselné masky, generovanie PDF s platobnými QR kódmi, správa prijatých faktúr (Scan Inbox).
- **Integrations:** Správa obmedzených (scoped) API tokenov a odchádzajúce webhooky s podpisom (HMAC-SHA256) pre integráciu s tretími stranami.
