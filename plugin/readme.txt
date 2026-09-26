=== Föreningsplugin ===
Version: 0.1.0
Requires at least: WordPress 7.1
Requires PHP: 8.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Föreningens administrativa ryggrad i WordPress: personer, medlemskap, styrelse,
möten, protokoll, dokument och publicering.


== Vad Föreningsplugin är ==

Föreningsplugin är ett självhostat open source-plugin för små och medelstora
ideella föreningar. Föreningens strukturerade data samlas på ett ställe och
återanvänds i administration, mötesarbete, dokument och den publika
webbplatsen.

Pluginet ska hjälpa föreningen att bedriva sitt arbete, inte bara lagra
information. Kärnflödet är:

Personer -> medlemskap -> styrelse -> möten -> dagordning -> anteckningar ->
beslut -> protokoll -> justering -> PDF/utskrift -> signerad kopia -> arkiv ->
publicering

Grundprinciper:

* Självhostat först. Ingen obligatorisk SaaS-tjänst.
* WordPress förblir WordPress. Pluginet är inget tema.
* En medlem är inte samma sak som ett wp_user-konto.
* Historiken är ett förstaklassbehov. Justerade protokoll skrivs inte över.
* Inga spårare, annonser, dolda anrop eller telemetri.


== Status ==

Aktuell version är 0.1.0 och aktuellt databasschema är 16. Implementationen
pågår. Flera designfrågor är fortfarande öppna, bland annat migrationspolicyn
och kryptering av personnummer.


== Viktig utvecklingsvarning ==

FÖRENINGSPLUGIN 0.1.0 ÄR EN TIDIG UTVECKLINGSVERSION. DEN ÄR INTE REDO FÖR
PRODUKTION ELLER FÖR SKARPA FÖRENINGSUPPGIFTER.

Föreningsplugin 0.1.0 is an early development build. It is NOT ready for production use or live association data.

* API, datamodell och migreringar kan fortfarande ändras, och en ändring kan
  kräva att du börjar om från en tom installation.
* Arbetet med integritet och säkerhet pågår. Härdningen är inte avslutad.
* Lägg inte in riktiga medlemsuppgifter, personnummer eller arkivoriginal som
  signerade protokollskopior i den här versionen.
* Versionen är avsedd för utveckling, granskning och testinstallationer.


== Funktioner ==

* föreningsprofil och en samlad inställningshubb
* personer och medlemskap: ordinarie, ungdom, familj och företag
* styrelse med historik och täckningsregeln mot medlemskap
* möten, dagordning, anteckningar, beslut, protokoll, PDF, signerad kopia och
  publicering
* besluts- och uppgiftsregister som utgår från mötet som enda källa
* dokument med privat lagring och medlemsbehörighet
* publika block för styrelse, senaste möte, senaste protokoll, medlemsantal
  och dokument
* Mina sidor för den inloggade medlemmens egna uppgifter och dokument
* integritetsexport, radering och kvarhållning
* migreringar till schema 16
* första-gången-guide för uppstart och en guidad styrelseadministration


== Installation ==

Krav: WordPress 7.1 eller senare och PHP 8.3 eller senare.

1. Läs utvecklingsvarningen ovan. Installera bara i en testinstallation.
2. Gå till Tillägg -> Lägg till nytt -> Ladda upp tillägg och välj
   foreningsplugin-0.1.0.zip.
3. Aktivera Föreningsplugin. Aktiveringen kör pluginets migreringar upp till
   schema 16 och lägger till föreningens behörigheter på administratörsrollen.
4. Öppna menyn Förening och gå igenom uppstartsguiden.

Att avaktivera eller ta bort pluginet tar inte bort föreningens tabeller. Ta
bort en testinstallation genom att ta bort databasen.


== Integritet och åtkomst ==

* Behörigheter är egna capabilities, inte rollnamn. Varje tillståndsändrande
  åtgärd kontrollerar behörighet och nonce.
* En person är inte ett wp_user. Ett konto kopplas explicit, och
  medlemsdokument kräver både kopplingen och ett aktivt medlemskap.
* En begäran om utdrag eller radering via WordPress integritetsverktyg
  besvaras för en person. En verifierad wp_user-koppling avgör på egen hand.
  En e-postadress som flera personer delar, till exempel en familjeadress,
  pekar inte ut någon: ingenting exporteras och ingen anonymiseras. Föreningen
  hanterar en sådan begäran manuellt.
* Radering anonymiserar direkta identifierare. Finalt justerade protokoll och
  signerade original skrivs inte om, och svaret redovisar vad som behölls.
* Kvarhållning styrs av en inställning i år, med 5 år som standard.
* Privata filer lagras utanför mediebiblioteket och lämnas ut via en
  behörighetskontrollerad nedladdning.
* Personnummer lagras i en egen tabell och är inte krypterat i den här
  versionen.
* Pluginet skickar inga data till någon extern tjänst.

Detta är inte juridisk rådgivning.


== Dokumentation ==

Projektets dokumentation följer med källkoden, inte det här arkivet. Den finns
på https://github.com/ricande/foreningssystem-wordpress under docs/ och adr/,
tillsammans med README och aktuell projektstatus.


== Utveckling ==

Projektet är open source och utvecklas på
https://github.com/ricande/foreningssystem-wordpress. Där byggs arkivet med
make package, och där körs PHPUnit-sviten, labbsviten och ett färskt
ZIP-installationsprov. Rapportera gärna problem som issues.


== Licens ==

GPL-2.0-or-later. Licenstexten finns på
https://www.gnu.org/licenses/gpl-2.0.html och som LICENSE i källkoden.
