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

Implementationen är igång. Aktuellt databasschema är version 16.

Det som finns i pluginet nu:

- föreningsprofil
- personer och medlemskap, inklusive ordinarie, ungdom, familj och företag
- styrelse och täckningsregeln mot medlemskap
- möten, dagordning, anteckningar, beslut, protokoll, PDF, signerad kopia och publicering
- dokument och privat lagring
- publika block
- integritetsexport, radering och kvarhållning
- migreringar till schema 16
- medlemsadmin: lista, detalj, flöden per medlemstyp, vårdnadshavare, skyddade identitetsuppgifter och historik

Flera tidiga designfrågor är fortfarande öppna, bland annat migrationspolicyn och kryptering av personnummer. Schema 16 är det schema som koden använder nu. Det betyder inte att varje tidigare förslag är låst.

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
17. `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` (product direction / proposal — not implemented)
18. `OPEN_QUESTIONS.md`
19. `CURSOR_START_PROMPT.md`

## Första uppgiften för Cursor

Den första leveransen var ett granskningsbart designpaket:

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

Implementationen har därefter startat från den basen. Aktuellt schema är 16.

## Referenser

Projektet ska följa aktuella WordPress-standarder och officiell dokumentation:
- https://developer.wordpress.org/plugins/
- https://developer.wordpress.org/apis/security/
- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- https://developer.wordpress.org/rest-api/

Cursor-regler:
- https://cursor.com/docs/rules

Licens: GPL-2.0-or-later. Se `LICENSE`.

## Lokal labbmiljö

Labbet är Docker på utvecklingsdatorn, inte en separat virtuell maskin. Inventering finns i `docs/16_LAB_INVENTORY.md`.

```text
make up
make install
make snap name=ren-install
make restore name=ren-install
make wp plugin list
make test
```

Webbplatsen körs på `http://localhost:8088` när containrarna är igång. Mailpit tar emot all utgående post på `http://localhost:8025`. Lokala lösenord ligger i `.env`, som inte ska committas.

Paketering och ett separat installationsprov, som inte använder labbets plugin-montering:

```text
make package
make release-test
make release-test-down
```

`make package` bygger `dist/foreningsplugin-0.1.0.zip`. `make release-test` installerar det zip-arkivet i en ny WordPress på `http://localhost:8090`. Det är inte en publicerad release. Se `docs/17_RELEASE_PACKAGING.md`.
