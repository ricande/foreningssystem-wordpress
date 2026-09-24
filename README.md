# Föreningsplugin till WordPress — Cursor handoff

Detta paket är projektets startpunkt och källa till produktkontext för Cursor.

## Projektets idé

Vi bygger ett modernt, självhostat open source-system för små och medelstora ideella föreningar i WordPress.

Produkten började som ett medlemsregister men har en större ambition:

> Föreningens strukturerade data ska finnas på ett ställe och återanvändas i administration, mötesarbete, dokument och den publika WordPress-webbplatsen.

Pluginet ska hjälpa föreningen att **bedriva sitt arbete**, inte bara lagra information.

Kärnflödet är:

**Personer → medlemskap → styrelse → möten → dagordning → anteckningar → beslut → protokoll → justering → PDF/utskrift → signerad kopia → arkiv → publicering**

## Viktig status

Projektet är fortfarande i **produkt- och arkitekturfas**.

Det finns ännu inga låsta implementationstekniska beslut om exempelvis:
- exakt databasschema
- vilka objekt som ska vara egna tabeller
- vilka objekt som eventuellt ska vara Custom Post Types
- protokolleditorns tekniska lösning
- PDF-bibliotek
- REST-API-struktur
- frontend/admin-JS-stack

Cursor får inte välja sådant permanent enbart för att komma igång snabbt.

## Läsordning

1. `AGENTS.md`
2. `DECISIONS.md`
3. `docs/01_PRODUCT_VISION.md`
4. `docs/02_SCOPE_MVP.md`
5. `docs/03_GLOSSARY_DRAFT.md`
6. `docs/04_DOMAIN_MODEL_DRAFT.md`
7. `docs/05_MEETING_WORKFLOW.md`
8. `docs/06_DOCUMENT_PDF_SIGNING.md`
9. `docs/07_PRIVACY_GDPR.md`
10. `docs/08_CAPABILITIES.md`
11. `docs/09_DATA_ARCHITECTURE.md`
12. `docs/10_ADMIN_UX.md`
13. `docs/11_GUTENBERG_STRATEGY.md`
14. `docs/12_TEST_STRATEGY.md`
15. `docs/13_MIGRATIONS.md`
16. `docs/14_VM_LAB.md`
17. `OPEN_QUESTIONS.md`
18. `CURSOR_START_PROMPT.md`

## Första uppgiften för Cursor

Cursor ska **inte börja implementera pluginet direkt**.

Första leveransen ska vara ett granskningsbart designpaket:

- färdig terminologi
- domänmodell
- möteslivscykel
- privacy/livscykelmodell
- capabilitiesmodell
- rekommenderad datalagringsstrategi per domänobjekt
- ADR:er för viktiga teknikval
- föreslagen pluginstruktur
- teststrategi
- implementation roadmap

Efter det kan implementation starta från en uttryckligen godkänd bas.

## Referenser

Projektet ska följa aktuella WordPress-standarder och officiell dokumentation:
- https://developer.wordpress.org/plugins/
- https://developer.wordpress.org/apis/security/
- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- https://developer.wordpress.org/rest-api/

Cursor-regler:
- https://cursor.com/docs/rules

## Lokal labbmiljö

Labbet är Docker på utvecklingsdatorn, inte en separat virtuell maskin. Inventering finns i `docs/16_LAB_INVENTORY.md`.

```text
make up
make install
make snap name=ren-install
make restore name=ren-install
make wp plugin list
```

Webbplatsen körs på `http://localhost:8088` när containrarna är igång. Lokala lösenord ligger i `.env`, som inte ska committas.
