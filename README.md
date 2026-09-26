# Föreningsplugin till WordPress

Ett självhostat open source-plugin som ska bli den administrativa ryggraden för små och medelstora ideella föreningar, utan att sluta vara WordPress.

[![Tests](https://img.shields.io/github/actions/workflow/status/ricande/foreningssystem-wordpress/tests.yml?branch=main&label=Tests&labelColor=d4af37)](https://github.com/ricande/foreningssystem-wordpress/actions/workflows/tests.yml) [![PHP](https://img.shields.io/badge/PHP-8.3%2B-d4af37?labelColor=d4af37)](https://www.php.net/) [![WordPress](https://img.shields.io/badge/WordPress-7.1%2B-d4af37?labelColor=d4af37)](https://wordpress.org/) [![License](https://img.shields.io/badge/License-GPL--2.0--or--later-d4af37?labelColor=d4af37)](LICENSE)

## Vad Föreningsplugin är

Föreningsplugin samlar föreningens strukturerade data på ett ställe och återanvänder den i administration, mötesarbete, dokument och den publika WordPress-webbplatsen.

Pluginet ska hjälpa föreningen att **bedriva sitt arbete**, inte bara lagra information. Kärnflödet är:

**Personer → medlemskap → styrelse → möten → dagordning → anteckningar → beslut → protokoll → justering → PDF/utskrift → signerad kopia → arkiv → publicering**

Några grundprinciper som styr bygget:

- Självhostat först. Ingen obligatorisk SaaS-tjänst.
- WordPress förblir WordPress. Pluginet är inget tema och tar inte över vanlig innehållshantering.
- En medlem är inte samma sak som ett `wp_user`-konto.
- Historiken är ett förstaklassbehov. Justerade protokoll skrivs inte över i tysthet.
- Integritet och behörigheter påverkar arkitekturen från början.
- Inga spårare, annonser, dolda anrop eller telemetri.

## Status

Aktuell version är **0.1.0** och aktuellt databasschema är **16**. Implementationen pågår.

Flera tidiga designfrågor är fortfarande öppna, bland annat migrationspolicyn och kryptering av personnummer. Schema 16 är det schema som koden använder nu. Det betyder inte att varje tidigare förslag är låst. Öppna frågor finns i `OPEN_QUESTIONS.md`.

## Viktig utvecklingsvarning

> [!WARNING]
> **Föreningsplugin 0.1.0 är en tidig utvecklingsversion. Den är INTE redo för produktion eller för skarpa föreningsuppgifter.**
>
> **Föreningsplugin 0.1.0 is an early development build. It is NOT ready for production use or live association data.**
>
> - API, datamodell och migreringar kan fortfarande ändras, och en ändring kan kräva att du börjar om från en tom installation.
> - Arbetet med integritet och säkerhet pågår. Härdningen är inte avslutad.
> - Lägg inte in riktiga medlemsuppgifter, personnummer eller arkivoriginal som signerade protokollskopior i den här versionen.
> - Versionen är avsedd för utveckling, granskning och testinstallationer.

## Funktioner

Det som finns i pluginet nu:

- föreningsprofil och en samlad inställningshubb
- personer och medlemskap, inklusive ordinarie, ungdom, familj och företag
- styrelse med historik och täckningsregeln mot medlemskap
- möten, dagordning, anteckningar, beslut, protokoll, PDF, signerad kopia och publicering
- besluts- och uppgiftsregister som utgår från mötet som enda källa
- dokument med privat lagring och medlemsbehörighet
- publika Gutenberg-block för styrelse, senaste möte, senaste protokoll, medlemsantal och dokument
- Mina sidor: en inloggad medlems egna uppgifter, medlemskap och medlemsdokument
- integritetsexport, radering och kvarhållning
- migreringar till schema 16
- första-gången-guide för uppstart och en guidad styrelseadministration

Help & Guides, kontextuell hjälp och registrerade installationer är förslag i `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md`. De är inte implementerade.

## Snabbstart

Krav: WordPress 7.1 eller senare och PHP 8.3 eller senare.

Bygg ett installerbart arkiv från källkoden:

```text
make package
```

Det skriver `dist/foreningsplugin-0.1.0.zip`. Installera arkivet i en **testinstallation** av WordPress via Tillägg → Lägg till nytt → Ladda upp tillägg, och aktivera det. Aktiveringen kör pluginets migreringar upp till schema 16 och lägger till föreningens behörigheter på administratörsrollen.

Det finns ingen publicerad release och inget releasearkiv att hämta. Se varningen ovan innan du installerar någonstans.

Ett automatiserat installationsprov i en ny, tom WordPress körs med:

```text
make release-test
make release-test-down
```

Se `docs/17_RELEASE_PACKAGING.md`.

## Integritet och åtkomst

- Behörigheter är egna capabilities, inte rollnamn. Varje tillståndsändrande åtgärd kontrollerar behörighet och nonce. Se `docs/08_CAPABILITIES.md` och `docs/CAPABILITY_MATRIX.md`.
- En person är inte ett `wp_user`. Ett konto kopplas explicit, och medlemsdokument kräver både kopplingen och ett aktivt medlemskap.
- En begäran om utdrag eller radering enligt WordPress integritetsverktyg besvaras för **en** person. En verifierad `wp_user`-koppling avgör på egen hand. En e-postadress som flera personer delar, till exempel en familjeadress, pekar inte ut någon: ingenting exporteras och ingen anonymiseras. Föreningen hanterar en sådan begäran manuellt.
- Radering anonymiserar direkta identifierare. Finalt justerade protokoll och signerade original skrivs inte om, och svaret redovisar vad som behölls.
- Kvarhållning styrs av en inställning i år, med 5 år som standard.
- Privata filer lagras utanför mediebiblioteket och lämnas ut via en behörighetskontrollerad nedladdning. Se `docs/PRIVATE_FILES.md`.
- Personnummer lagras i en egen tabell och är inte krypterat i den här versionen. Se ADR-0021.

Detta är inte juridisk rådgivning. Se `docs/07_PRIVACY_GDPR.md` och `docs/PRIVACY_MODEL.md`.

## Dokumentation

Föreslagen läsordning:

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
17. `docs/17_RELEASE_PACKAGING.md`
18. `docs/18_SETUP_HELP_GUIDES_AND_BULLETINS.md` (produktriktning / förslag — inte implementerat)
19. `OPEN_QUESTIONS.md`
20. `CURSOR_START_PROMPT.md`

Viktiga teknikval är dokumenterade som ADR:er i `adr/`. Aktuell status finns i `PROJECT_STATE.md`.

## Utveckling

Projektet följer WordPress standarder och officiell dokumentation:

- https://developer.wordpress.org/plugins/
- https://developer.wordpress.org/apis/security/
- https://developer.wordpress.org/coding-standards/wordpress-coding-standards/
- https://developer.wordpress.org/rest-api/

Arbetsreglerna för den som utvecklar här, människa eller agent, står i `AGENTS.md`. Cursor-regler: https://cursor.com/docs/rules.

### Labbmiljö

Labbet är Docker på utvecklingsdatorn, inte en separat virtuell maskin. Inventering finns i `docs/16_LAB_INVENTORY.md`.

**Bind-mount development lab** (plugin source mounted; day-to-day work):

```text
make up
make install
make snap name=ren-install
make restore name=ren-install
make wp plugin list
make test
```

Webbplatsen körs på `http://localhost:8088` när containrarna är igång. Mailpit tar emot all utgående post på `http://localhost:8025`. Lokala lösenord ligger i `.env`, som inte ska committas.

**Clean WordPress baseline** (no plugin bind-mount; snap/restore before plugin work) lives in-repo at `labs/wordpress-clean/`:

```text
make clean-lab-up
make clean-lab-install
make clean-lab-snap
make clean-lab-restore
```

Default ports are also 8088 / 8025 — stop the bind-mount lab first, or change ports in `labs/wordpress-clean/.env`. See `labs/wordpress-clean/README.md`.

### Tester

```text
make test
```

Det kör PHPUnit-sviten och därefter labbsviten mot bind-mount-labbet. Samma jobb, plus ett färskt ZIP-installationsprov, körs i GitHub Actions. Teststrategin finns i `docs/12_TEST_STRATEGY.md`.

## Licens

GPL-2.0-or-later. Se `LICENSE`.
