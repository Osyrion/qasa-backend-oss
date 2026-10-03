# Roadmap — Zoad

> **Čo tento súbor je:** jediné miesto, kde je vidieť *čo je ďalej* a *prečo v tomto poradí*.
> Deľba práce medzi dokumentmi:
>
> | Dokument | Odpovedá na otázku |
> | --- | --- |
> | `CHANGELOG.md` | Čo sa už stalo (per commit/release). |
> | `docs/plans/*.md` | Ako sa konkrétna vec spraví (implementačný detail). |
> | `docs/adr/*.md` | Čo sme sa vedome rozhodli **nehádať**, a od koho potrebujeme odpoveď. |
> | `docs/app/APLIKACIA.md` | Ako to funguje dnes (referencia pre ďalšieho inžiniera). |
> | `docs/app/APLIKACIA.md` kap. 18 | Čo je v kóde rozostavané — vedomé medzery, nie fronta chýb. |
> | `docs/DEPLOYMENT.md` | Ako to beží na produkcii a ako sa obnoví, keď spadne. |
> | **`ROADMAP.md`** | **Čo ide ďalej a čo to blokuje.** |
>
> Pravidlo údržby je na konci súboru.

**Posledná revízia: 2026-08-26.**

---

## Kde sme

20 modulov, ~57 implementačných plánov v `docs/plans/`. Kontrola stavu k dnešku:
**plánovaný funkčný backlog je prakticky celý odbavený** — vrátane vecí, ktoré
plán ešte vedie ako otvorené (rozpísané fázy `TAX_RESIDENCY_*` majú v texte
neodškrtnuté boxy, ale `users.country`, `complete-residency` aj
`TaxSystemResolver` v kóde existujú; `CashDocumentData`, `conversion_rate` na
faktúrach, `email_deliveries`, Stripe Connect + verejný platobný link,
`ProcessDunningCommand`, Google Calendar sync — všetko je v strome).

Z toho plynie hlavný záver pre plánovanie: **najbližší míľnik nie je ďalšia
funkcia, ale spustenie.** Otvorené položky nižšie sú z drvivej väčšiny
prevádzkové a externé — účty, kľúče, DNS, právna revízia, reálne dáta na
overenie — nie kód. Preto je H0 zoznam blokátorov, nie feature list.

Beta waitlist (`POST /api/v1/public/waitlist`) pribudol 2026-08-16/17, takže
smerovanie na beta je už aj v kóde.

---

## H0 — Spustenie bety (blokátory)

Nič z tohto sa nedá odbaviť samotným písaním kódu; každý riadok potrebuje účet,
kľúč alebo cudzie rozhodnutie. Poradie = poradie, v akom to blokuje beta účet,
ktorý si chce zaplatiť — s jednou výnimkou na prvom mieste: zálohy neblokujú
platbu, blokujú **právo držať cudzie dáta**, a to je skôr.

| # | Položka | Čo presne chýba | Referencia |
| --- | --- | --- | --- |
| 1 | **Zálohy** | Neexistujú — ani skript, ani úložisko, ani alert. Jediná položka v tomto zozname, ktorá sa **nedá opraviť dodatočne**: všetko ostatné je pokazená funkcia, toto je stratená firma. Treba samostatný Cloudflare účet + R2 bucket (Object Lock Governance, versioning, append-only token s výnimkou na `locks/*`), `ops/backup.sh` v hostiteľskom crone, alert `BackupStale` a **jednu reálnu obnovu** — podľa pravidla 3 nižšie sa riadok nezavrie skôr. Pozor na tri pasce: `pg_dump` pod `qasa_app` (RLS ho odstrihne), chýbajúci `pg_dumpall --globals-only` (obnova padne na `GRANT … TO qasa_app`) a `APP_KEY` mimo zálohy (šifrované stĺpce sú bez neho nenávratné). | `docs/DEPLOYMENT.md` §7–8 |
| 2 | **Stripe produkčné tajomstvá** | `STRIPE_WEBHOOK_SECRET` + `STRIPE_CONNECT_WEBHOOK_SECRET` nie sú nastavené. Webhook guard je od 07-28 fail-closed → bez nich neprejde ani jedna platba. Plus 12 `STRIPE_PRICE_*` (3 meny × 2 intervaly × 2 plány). | `SUBSCRIPTIONS_*_PLAN.md`, `.env.example:206–230` |
| 3 | **Twilio live** | Overenie čísla je dnes *gate na trial* — bez funkčnej SMS brány si nový účet trial nezaslúži a onboarding sa zastaví. Provider je fail-soft (503, nie 500), ale trial neudelí. | `PHONE_VERIFICATION_TRIAL_ABUSE_PLAN.md` |
| 4 | **Grafana Cloud** | Kód a konfigurácia hotové 08-18 (`docker-compose.observability.yml`, Alloy, 12 alertov). Chýba účet + EU stack, token, spustenie `docker/alloy/monitoring-role.sql` a **notification policy** — bez nej sa pravidlá vyhodnocujú a nikomu nič nepríde. Toto je jediná vec, ktorá zavolá, keď zomrie scheduler alebo dôjde disk. | `GRAFANA_OBSERVABILITY_PLAN.md`, `APLIKACIA.md` kap. 31 |
| 5 | **Sentry EU** | Chýbajú EU projekty + DSN, CSP hlavička, IP setting, podpísaná DPA. Kód (BE aj FE) je hotový od 08-14. | `SENTRY_OBSERVABILITY_PLAN.md`, `docs/legal/SUBPROCESSORS.md` |
| 6 | **Cloudflare DNS flip** | Repo-side groundwork hotový 08-17. Po prepnutí DNS **overiť, že rate-limitery vidia skutočnú klientsku IP**, nie CF edge — inak sú per-IP limity (register, phone-send, phone-verify) fikcia. | `CLOUDFLARE_INTEGRATION_PLAN.md` |
| 7 | **Právna revízia** | `docs/legal/*` (VOP, GDPR, subprocessors) čaká na právnika. Implementácia GDPR je hotová (všetkých 6 fáz, 08-07), chýba len posudok. | `GDPR_COMPLIANCE_PLAN.md` |
| 8 | **Waitlist → pozvánka (admin UI)** | Backend hotový 08-18: tokenové pozvánky viazané na adresu, dávkové pozývanie, `GET /admin/metrics/waitlist-funnel`. Zostáva obrazovka v admin UI (`zoad_frontend`) — dovtedy sa pozýva volaním API priamo, čo betu neblokuje. | `CHANGELOG.md` 08-18, `APLIKACIA.md` kap. 4 |
| 9 | **Peppol AP** | ePošťák sandbox stále bez odpovede. **Rozhodnutie, ktoré netreba odkladať:** beta ide von bez odosielania cez Peppol (UBL export/import funguje aj tak), alebo sa čaká. Odporúčanie: ísť bez toho. | `PEPPOL_MANAGED_AND_BYOK_PLAN.md` |
| 10 | **Pay by Square sken** | QR je **živý bez prepínača** — `PayBySquareBuilder` je prvý v `PaymentSchemeRegistry` (`InvoicingServiceProvider.php`, `PaymentSchemeRegistry` binding), takže SK IBAN + EUR faktúra ho dostane už dnes. Naskenovať reálnou bankovou appkou (Tatra, SLSP, VÚB, mBank SK, 365.bank) **pred prvou reálnou faktúrou**. Golden test overuje vlastný dekomprimovaný výstup, nie to, ako cudzí skener prečíta náš LZMA prúd. Zostáva **len ten sken**: „fáza 3 = zapnúť feature flag" bola fikcia (kľúč neexistoval) a „pri chybe buildera sa QR ticho vynechá" neplatilo, kým sa to 08-21 neopravilo. | `PAY_BY_SQUARE_VERIFICATION_PLAN.md` fáza 2 |
| 11 | **Mobil** | `zoad_mobile` fázy 0–6 + druhé kolo (Faktúry/Klienti/Kniha jázd/Notifikácie, reset hesla, overenie telefónu) hotové; **08-31 dorovnané na backend** (2FA stena + obrazovka Bezpečnosť, pozvánka do registrácie, read-only pruh, spec resync). Zostáva nespustiteľné bez EAS/Google/Sentry credentials a bez push *delivery*. Maestro E2E flows napísané, nikdy nespustené (žiadny simulátor v tomto prostredí). Mobil nie je blokátor bety, pokiaľ beta = web. **Pozor na drift:** appka sa generuje z toho istého spec-u ako web, ale nemá CI, ktoré by ho kontrolovalo — po zmene endpointu spustiť `npm run api:sync` aj tam. | `MOBILE_APP_FOUNDATION_PLAN.md` |

---

## H1 — Prvé mesiace prevádzky

Veci, ktoré sa **nedajú dokončiť pred spustením**, lebo potrebujú reálne dáta
alebo reálneho používateľa. Držať ich na zozname, aby sa na ne nezabudlo v deň,
keď dáta konečne budú.

- **Import výpisu na reálnych dátach** — dnes existuje Fio (CSV + API), **camt.053
  XML (09-06)** a generický mapovaný CSV; párovanie platieb
  (`PaymentMatchingService`) nikdy nebežalo na skutočnom výpise. Prvý reálny výpis
  od beta účtu = verifikačná úloha, a pre camt.053 zvlášť: parser je postavený proti
  norme a syntetickým fixtúram, nie proti exportu konkrétnej banky — čo sa reálne
  líši, je **v ktorom poli banka nesie variabilný symbol**. GPC parser stále
  neexistuje (Časť C plánu) — doplniť až podľa toho, z ktorej banky beta účty
  reálne prídu. `BANK_STATEMENT_IMPORT_PLAN.md`
- **Revízia DPH riadkov účtovníkom** — XSD validuje tvar, nie správnosť
  mapovania. Pred prvým reálnym podaním.
- **Aktivačná metrika** — registrácia → overené číslo → prvá faktúra → platba.
  Admin metriky existujú (MRR, growth), onboarding funnel nie.
- **Aktivácia e-fakturácie v onboardingu (SK + CZ)** — **backend P0 hotový
  (08-28)**, vrátane všetkých troch pôvodných tichých chýb: overovanie
  registrácie dopytom na poštára (na generickom SAPI-SK nikdy nemohlo vyjsť),
  slovenský termín posielaný českým účtom, a **neukladané odoslané UBL bajty**.
  Zostáva **frontend** (`zoad_frontend`): obrazovky S1–S6 a C1–C5 nad
  `POST …/registration/{start,path,check,delegate,acknowledge-mismatch}` — bez
  nich je funkcia dostupná len cez API. Potom potrebuje betu, aby povedala, či
  rozcestník „kto to vybaví" znižuje odpad na eID kroku; číslo na sledovanie je
  `portal_return_rate` v `/admin/peppol-registrations/funnel`.
  **Dátum, ktorý nečaká: 31. 8. 2026** prestáva odpovedať stará SML zóna
  (`edelivery.tech.ec.europa.eu`). Default už mierime na peppol.org, ale po tom
  dátume spustiť `scripts/peppol-smp-probe.py --compare` a overiť, že nová zóna
  odpovedá aj bez DNAME na starú. `EINVOICE_ACTIVATION_ONBOARDING_PLAN.md`
- **BYOK multi-provider** — dnes je za `LlmProviderDriver` jediný (Anthropic)
  driver. Druhý provider má zmysel až keď bude známa reálna spotreba a cena.
  `BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md` je stále v stave „návrh".

---

## H2 — Backlog

Nie je to blokátor ničoho; ťahať podľa toho, čo budú pýtať prví používatelia.

- **Verejné API pre integrácie** — povrch je postavený a **už sa predáva**
  (`api_access` je feature Pro plánu): scoped tokeny s vynúteným scope,
  webhooky so 7 eventmi a HMAC podpisom. Chýba mu **dokumentácia, ktorá môže
  ísť von** — dnešná spec obsahuje aj 68 admin operácií, takže publikovať sa
  nedá tak, ako je. Rez podľa `AbilityCatalog` + druhá l5-swagger dokumentácia,
  strážená testom. Rozsah v1 zámerne čaká na prvého reálneho integrátora
  (účtovný softvér, e-shop a Zapier chcú tri rôzne podmnožiny).
  **Jedna vec nečaká:** webhook hlavičky sú `X-Zoad-*` — premenovať skôr, než
  ich niekto zvonku začne overovať, potom je to breaking change v cudzom kóde.
  `PUBLIC_API_PLAN.md`
- **Documents e-mail-in** — vedome odložené v `DOCUMENTS_LIGHT_MODULE_PLAN.md`.
- **Competitor imports V2** — V1 (Superfaktúra + generický CSV) je vonku od
  08-03; ďalšie drivery podľa toho, odkiaľ ľudia reálne prídu.
- **Zdieľané modely medzi modulmi — splatené (2026-08-25), zostáva jedno rozhodnutie.**
  Baseline **424 → 7** a tých sedem už nie je dlh: šesť riadkov v `SharedServiceProvider`
  (kompozičný koreň — `Relation::morphMap()` a audit registry musia menovať konkrétne
  triedy) a `CashierBillingGateway` → `Saas\User`, zapísaná výnimka z fázy 3. História je
  v `CHANGELOG.md`.
  **Otvorené:** zjednotiť depfily tak, aby modulový vynímal `Infrastructure/Providers/`
  rovnako ako vrstvový — potom by tých šesť zmizlo úplne a baseline by klesol na jednu
  položku. Je to zmena hranice, nie kódu, čiže rozhodnutie na samostatný review.
  `MODULE_BOUNDARY_MODEL_DEBT_PLAN.md`

---

## Vedomé ne-ciele

Zapísané, aby sa nevracali ako „dobrý nápad":

- **Podvojné účtovníctvo.** Pozicionovanie je fakturácia + daňový výstup OSVČ/SZČO.
  (analýza konkurencie 2026-07-30)
- **Data Mapper refaktor domény.** Zamietnuté v `GEMINI_AUDIT_FIXES_PLAN.md` —
  Active Record + globálne scopes sú zámerná architektúra.
- **Zmena daňovej rezidencie po registrácii.** Nikdy, žiadna výnimka v API.
- **Krajiny mimo SK/CZ.** Fakturovať sa dá kamkoľvek, rezident je len SK/CZ.
- **`SK CIUS` a validácia pred odoslaním ako sľub navonok.** Kód to nerobí —
  nesmie sa to objaviť v marketingu.

---

## Údržba tohto dokumentu

1. **Nový plán v `docs/plans/`** → dostane riadok tu, v tom istom commite.
2. **Dokončený plán** → riadok sa odtiaľto zmaže a zmena sa objaví v `CHANGELOG.md`.
   Roadmap nie je archív; „čo sme spravili" patrí do changelogu.
3. **Blokátor v H0** sa nezavrie, kým nie je overený v prostredí, kde má bežať —
   nastavený kľúč ≠ funkčná platba.
4. Statusy vo vnútri `docs/plans/*.md` **klamú** (viac plánov vedie ako otvorené
   veci, ktoré sú dávno v kóde). Zdroj pravdy o stave je kód a `CHANGELOG.md`,
   nie hlavička plánu.
5. **Medzera v kóde** (nedokončená alebo neoverená implementácia) patrí do
   `docs/app/APLIKACIA.md` kap. 18. Sem sa duplikuje len vtedy, keď blokuje
   spustenie — vtedy má riadok v H0 aj tam.
