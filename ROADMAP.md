# Roadmap — Flok

> **Čo tento súbor je:** jediné miesto, kde je vidieť *čo je ďalej* a *prečo v tomto poradí*.
> Deľba práce medzi dokumentmi:
>
> | Dokument | Odpovedá na otázku |
> | --- | --- |
> | `CHANGELOG.md` | Čo sa už stalo (per commit/release). |
> | `docs/plans/*.md` | Ako sa konkrétna vec spraví (implementačný detail). |
> | `docs/app/APLIKACIA.md` | Ako to funguje dnes (referencia pre ďalšieho inžiniera). |
> | `docs/app/APLIKACIA.md` kap. 18 | Čo je v kóde rozostavané — vedomé medzery, nie fronta chýb. |
> | **`ROADMAP.md`** | **Čo ide ďalej a čo to blokuje.** |
>
> Pravidlo údržby je na konci súboru.

**Posledná revízia: 2026-08-25.**

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
ktorý si chce zaplatiť.

| # | Položka | Čo presne chýba | Referencia |
| --- | --- | --- | --- |
| 1 | **Stripe produkčné tajomstvá** | `STRIPE_WEBHOOK_SECRET` + `STRIPE_CONNECT_WEBHOOK_SECRET` nie sú nastavené. Webhook guard je od 07-28 fail-closed → bez nich neprejde ani jedna platba. Plus 12 `STRIPE_PRICE_*` (3 meny × 2 intervaly × 2 plány). | `SUBSCRIPTIONS_*_PLAN.md`, `.env.example:206–230` |
| 2 | **Twilio live** | Overenie čísla je dnes *gate na trial* — bez funkčnej SMS brány si nový účet trial nezaslúži a onboarding sa zastaví. Provider je fail-soft (503, nie 500), ale trial neudelí. | `PHONE_VERIFICATION_TRIAL_ABUSE_PLAN.md` |
| 3 | **Grafana Cloud** | Kód a konfigurácia hotové 08-18 (`docker-compose.observability.yml`, Alloy, 12 alertov). Chýba účet + EU stack, token, spustenie `docker/alloy/monitoring-role.sql` a **notification policy** — bez nej sa pravidlá vyhodnocujú a nikomu nič nepríde. Toto je jediná vec, ktorá zavolá, keď zomrie scheduler alebo dôjde disk. | `GRAFANA_OBSERVABILITY_PLAN.md`, `APLIKACIA.md` kap. 31 |
| 4 | **Sentry EU** | Chýbajú EU projekty + DSN, CSP hlavička, IP setting, podpísaná DPA. Kód (BE aj FE) je hotový od 08-14. | `SENTRY_OBSERVABILITY_PLAN.md`, `docs/legal/SUBPROCESSORS.md` |
| 5 | **Cloudflare DNS flip** | Repo-side groundwork hotový 08-17. Po prepnutí DNS **overiť, že rate-limitery vidia skutočnú klientsku IP**, nie CF edge — inak sú per-IP limity (register, phone-send, phone-verify) fikcia. | `CLOUDFLARE_INTEGRATION_PLAN.md` |
| 6 | **Právna revízia** | `docs/legal/*` (VOP, GDPR, subprocessors) čaká na právnika. Implementácia GDPR je hotová (všetkých 6 fáz, 08-07), chýba len posudok. | `GDPR_COMPLIANCE_PLAN.md` |
| 7 | **Waitlist → pozvánka (admin UI)** | Backend hotový 08-18: tokenové pozvánky viazané na adresu, dávkové pozývanie, `GET /admin/metrics/waitlist-funnel`. Zostáva obrazovka v admin UI (`flok_frontend`) — dovtedy sa pozýva volaním API priamo, čo betu neblokuje. | `CHANGELOG.md` 08-18, `APLIKACIA.md` kap. 4 |
| 8 | **Peppol AP** | ePošťák sandbox stále bez odpovede. **Rozhodnutie, ktoré netreba odkladať:** beta ide von bez odosielania cez Peppol (UBL export/import funguje aj tak), alebo sa čaká. Odporúčanie: ísť bez toho. | `PEPPOL_MANAGED_AND_BYOK_PLAN.md` |
| 9 | **Pay by Square sken** | QR je **živý bez prepínača** — `PayBySquareBuilder` je prvý v `PaymentSchemeRegistry` (`InvoicingServiceProvider.php`, `PaymentSchemeRegistry` binding), takže SK IBAN + EUR faktúra ho dostane už dnes. Naskenovať reálnou bankovou appkou (Tatra, SLSP, VÚB, mBank SK, 365.bank) **pred prvou reálnou faktúrou**. Golden test overuje vlastný dekomprimovaný výstup, nie to, ako cudzí skener prečíta náš LZMA prúd. Zostáva **len ten sken**: „fáza 3 = zapnúť feature flag" bola fikcia (kľúč neexistoval) a „pri chybe buildera sa QR ticho vynechá" neplatilo, kým sa to 08-21 neopravilo. | `PAY_BY_SQUARE_VERIFICATION_PLAN.md` fáza 2 |
| 10 | **Mobil** | `flok_mobile` fázy 0–6 + druhé kolo (Faktúry/Klienti/Kniha jázd/Notifikácie, reset hesla, overenie telefónu) hotové, ale nespustiteľné bez EAS/Google/Sentry credentials a bez push *delivery*. Maestro E2E flows napísané, nikdy nespustené (žiadny simulátor v tomto prostredí). Mobil nie je blokátor bety, pokiaľ beta = web. | `MOBILE_APP_FOUNDATION_PLAN.md` |

---

## H1 — Prvé mesiace prevádzky

Veci, ktoré sa **nedajú dokončiť pred spustením**, lebo potrebujú reálne dáta
alebo reálneho používateľa. Držať ich na zozname, aby sa na ne nezabudlo v deň,
keď dáta konečne budú.

- **Import výpisu na reálnych dátach** — dnes existuje Fio (CSV + API) a generický
  mapovaný CSV; párovanie platieb (`PaymentMatchingService`) nikdy nebežalo na
  skutočnom výpise. Prvý reálny výpis od beta účtu = verifikačná úloha.
  **CAMT.053/GPC parsery neexistujú vôbec** (Časť C plánu) — doplniť až podľa
  toho, z ktorej banky beta účty reálne prídu. `BANK_STATEMENT_IMPORT_PLAN.md`
- **Revízia DPH riadkov účtovníkom** — XSD validuje tvar, nie správnosť
  mapovania. Pred prvým reálnym podaním.
- **Aktivačná metrika** — registrácia → overené číslo → prvá faktúra → platba.
  Admin metriky existujú (MRR, growth), onboarding funnel nie.
- **BYOK multi-provider** — dnes je za `LlmProviderDriver` jediný (Anthropic)
  driver. Druhý provider má zmysel až keď bude známa reálna spotreba a cena.
  `BYOK_MULTI_PROVIDER_EXTRACTION_PLAN.md` je stále v stave „návrh".

---

## H2 — Backlog

Nie je to blokátor ničoho; ťahať podľa toho, čo budú pýtať prví používatelia.

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
