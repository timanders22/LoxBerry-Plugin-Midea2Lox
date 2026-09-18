# Midea2Lox

Integration von Klimaanlagen der Midea-Gruppe in Loxone — als LoxBerry-Plugin.

> **Fork-Hinweis**
> Dieses Repositorium führt die Arbeit von **Harald Friedl** (`seppe912`) fort.
> Er hat die Weiterentwicklung in [Issue #15](https://github.com/seppe912/Midea2Lox/issues/15)
> ausdrücklich freigegeben, weil er selbst keinen LoxBerry mehr betreibt.
> Sein Code ist die Grundlage, die Lizenz (Apache 2.0) bleibt unverändert.
> Was sich gegenüber 3.4.8 geändert hat, steht in den Release-Beschreibungen
> ab 4.0.0.

## Neu in 4.5.8

### Ein zweiter Aktualisierungsversuch löschte die einzige Sicherung

`preupgrade.sh` sichert den ganzen Konfigordner nach
`data/plugins/Midea2Lox.upgrade_sicherung`. Bis 4.5.7 löschte es die
vorhandene Sicherung dabei **zuerst** und baute die neue erst danach. Bricht
eine Aktualisierung ab, nachdem der Installer den Konfigordner abgeräumt hat,
ist diese Sicherung die einzige Abschrift der Einstellungen — und der zweite
Versuch hat sie gelöscht, bevor er feststellte, dass es nichts mehr zu
sichern gab. Übrig blieben nur die drei Zweitschriften neben dem
Konfigordner; alles andere im Ordner war fort.

Jetzt entsteht die neue Sicherung neben der alten (`….upgrade_sicherung.neu`),
jede Datei wird byteweise gegen das Original gehalten, und erst dann tauscht
sie den Platz mit der alten. Gibt es keinen Konfigordner, scheitert das
Kopieren oder fehlt auch nur eine Datei, bleibt die bisherige Sicherung
unangetastet, und das Protokoll sagt warum. Eine Sicherung mit eigenen
Einstellungen wird außerdem nie durch eine ersetzt, die nur noch die
mitgelieferte Vorgabe enthält — so sieht der Konfigordner aus, wenn der
Installer nach dem Einspielen der neuen Dateien abgebrochen ist.

### Die Zweitschrift wurde nach Größe beurteilt, nicht nach Inhalt

Neben dem Konfigordner liegen Zweitschriften von `devices.cfg`,
`midea2lox.cfg` und `mqtt_subscriptions.cfg`. Ob sie erneuert wird, entschied
bis 4.5.7 allein die Frage „ist die Datei leer?". Eine abgeschnittene Datei
ist nicht leer, die mitgelieferte Vorgabe auch nicht — beide haben die heile
Zweitschrift überschrieben, in `devices.cfg` samt Schlüssel und Token der
Klimageräte, in `midea2lox.cfg` samt Zugangsdaten des Midea-Kontos.

Erneuert wird eine Zweitschrift jetzt nur noch aus einer Datei, die etwas
Eigenes trägt: vollständig geschrieben (sie endet mit einem Zeilenumbruch,
wie jede Datei, die das Plugin selbst schreibt), im Aufbau, den der Dienst
liest, und nicht zeichengenau eine mitgelieferte Vorgabe. Geschrieben wird in
eine Nebendatei, verglichen und dann umbenannt — ein direktes `cp` kappte die
Zweitschrift, bevor die neue stand; nach einem Abbruch beim Schreiben blieb
eine leere Datei. Die Meldung „Zweitschrift der Einstellungen angelegt" kam
bis 4.5.7 immer, auch wenn nichts angelegt war; jetzt steht je Datei da, was
geschehen ist.

Grenze: eine Datei, die genau an einem Zeilenende abgeschnitten wurde,
erkennt die Prüfung nur, wenn dabei einer der Pflichtschlüssel verloren ging.

### Nach einem gescheiterten Zurückstellen war auch die Sicherung weg

`postupgrade.sh` stellt die Sicherung zurück und löscht sie danach. Bis
4.5.7 löschte es sie auch dann, wenn das Zurückstellen gescheitert war — nach
dem Skript gab es weder die Einstellungen noch ihre Sicherung. Jetzt wird
jede Datei der Sicherung im Konfigordner nachgesehen; weicht eine ab, bleibt
die Sicherung liegen, und das Protokoll nennt ihren Ort.

Ohne Sicherung meldete `postupgrade.sh` „Die Einstellungen sind vorhanden
(aus der Zweitschrift)", sobald `midea2lox.cfg` nicht leer war — auch für die
Vorgabe, die der Installer gerade eingespielt hatte, und auch dann, wenn es
gar keine Zweitschrift gab. Die Meldung kommt jetzt nur noch, wenn die Datei
etwas Eigenes trägt; sonst steht dort eine Warnung.

`postinstall.sh` spielt eine Zweitschrift zurück, wenn die Einstellungen
verloren sind. Eine abgeschnittene Datei galt dort als „gültige
Konfiguration" und wurde nicht ersetzt; eine abgeschnittene Zweitschrift
wurde ungeprüft über die Vorgabe kopiert. Beides entscheidet jetzt dieselbe
Inhaltsprüfung wie in `preupgrade.sh` — sie steht wortgleich in allen drei
Hakenskripten, und der Prüfstand hält die drei Abschriften gegeneinander.

### Wie das geprüft ist

Alles in WSL/Ubuntu gemessen, **nicht** am Gerät; der Installer ist
nachgestellt (Abräumen des Konfig- und Datenordners, Einspielen der
Vorgabe), das Startskript ist eine Attrappe. Ein Prüfstand mit **20 Fällen
und 37 Prüfzeilen**: der Abbruch nach dem Abräumen, der Abbruch beim
Schreiben, der Abbruch nach dem Einspielen der Vorgabe, ein Kopieren ohne
volle Wirkung, je eine abgeschnittene Datei, der ganze Ablauf am Stück und
die Kontrollfälle. Gegen den Stand vor der Behebung sind 22 davon rot,
danach keine. Jede der zwölf Korrekturen ist einzeln in einer Kopie
zurückgebaut worden; jede macht genau die vorher benannten Zeilen rot. Die
Prüfstände der Fassungen 4.5.6 und 4.5.7 laufen unverändert weiter.

## Neu in 4.5.7

### Ein bewusst angehaltener Dienst lief nach dem Systemstart wieder

Das Plugin merkt sich in `data/plugins/Midea2Lox/soll_laufen`, ob der Dienst
laufen soll. „Dienst anhalten" entfernt die Datei, „Dienst starten" legt sie
an; der minütliche Wächter sieht seit 4.3.0 zuerst dort nach und lässt einen
angehaltenen Dienst in Ruhe.

Das Startskript tat das nur im Zweig `waechter`. Beim Systemstart — und bei
jedem `start` und `restart` — startete es bedingungslos und legte die
Merkdatei dabei gleich wieder an. Gemessen am 18.09.2026 in WSL: Merkdatei
entfernt, `daemon start` aufgerufen, danach **ein** laufender Prozess
(erwartet null) und die Merkdatei wieder da. Wer den Dienst absichtlich
anhielt, hatte ihn nach dem nächsten Neustart des LoxBerry zurück.

Seit 4.5.7 achten alle Startwege dieselbe Merkdatei. Zwei Wege setzen sie
ausdrücklich außer Kraft, und nur diese zwei:

* `postupgrade.sh` — der Start nach der Installation. Der Installer räumt
  den Datenordner und mit ihm die Merkdatei beim Upgrade ab; ohne Ausnahme
  käme nach jedem Update kein Dienst mehr hoch.
* die Oberfläche — bis dorthin kommt nur ein angemeldeter Mensch, der einen
  Knopf gedrückt oder Einstellungen gespeichert hat. Ohne Ausnahme wäre der
  Knopf „Dienst starten" nach einem „Dienst anhalten" wirkungslos.

Ein übersprungener Start ist kein Fehlschlag: das Skript sagt im Klartext,
warum es nichts getan hat, schreibt eine Zeile ins Protokoll und endet
mit 0.

### Während einer Aktualisierung startet nichts mehr

Zwischen `preupgrade.sh` und `postupgrade.sh` liegt eine Lücke von rund
einer Minute, in der der Installer den Datenordner löscht, die neuen
Dateien einspielt und die Hakenskripte durchläuft. Wer in dieser Zeit
startet — ein Systemstart, der minütliche Takt, ein Knopf in der Oberfläche
—, fährt den Dienst mit den Vorgabewerten hoch, und der nächste Schritt des
Installers zieht ihm die Dateien unter den Füßen weg.

`preupgrade.sh` legt deshalb als Allererstes
`data/plugins/Midea2Lox.upgrade_laeuft` mit der Unixzeit an — **neben** dem
Datenordner, denn den löscht der Installer mitsamt Inhalt. Solange die Marke
gilt, startet das Startskript nicht. Nur eine Marke, die höchstens eine
Stunde alt ist, zählt: eine abgebrochene Installation darf den Dienst nicht
für immer stilllegen. Ältere, unlesbare und in der Zukunft liegende Marken
gelten nicht. Lässt sich die Uhr nicht lesen, fällt die Prüfung
**geschlossen** aus — dann gilt die Marke.

`postupgrade.sh` startet am Ende mit beiden Ausnahmen und entfernt die Marke
erst **danach**. Umgekehrt bliebe zwischen „Marke weg" und „gestartet" ein
Fenster, in dem ein Minutentakt weder Marke noch Dienst sieht und einen
eigenen startet. `uninstall` räumt die Marke weg.

Gemessen, ob in der Lücke überhaupt etwas anläuft — in beiden Lagen:

* Im Regelfall nicht. Der Minutentakt steigt aus, sobald
  `data/plugins/Midea2Lox` fehlt, und der Installer hat es gerade gelöscht.
* Überlebt der Merker die Lücke aber doch, dann schon. Der Prüfstand der
  Upgradelücke stellt genau das her: 4.5.6 fuhr dort einen Dienst mit der
  **Vorgabekonfiguration** hoch — Präfix `Midea2Lox` statt des
  eingestellten —, und nach dem Update liefen zwei Bindungen statt einer.
  Mit 4.5.7 bleibt es bei null Prozessen in der Lücke und genau einem
  danach, mit dem zurückgespielten Präfix.

Die Marke deckt außerdem die Wege ab, die der Minutentakt gar nicht sieht:
Systemstart mitten im Update, ein Aufruf des Startskripts von Hand, ein
Knopf in der Oberfläche.

### Die Oberfläche behauptet keinen Start mehr, den es nicht gab

Weil ein übersprungener Start mit 0 endet, hätte die Oberfläche während
einer Aktualisierung „Der Dienst wurde neu gestartet" gemeldet. Sie fragt
die Marke jetzt selbst — mit derselben Frist wie das Startskript — und sagt
stattdessen, dass gerade eine Aktualisierung läuft. Im Reiter *Test* stehen
zwei neue Zeilen: *Soll der Dienst laufen?* und *Läuft gerade eine
Aktualisierung?*

### Wie das geprüft ist

Alles in WSL/Ubuntu gemessen, **nicht** am Gerät; paho, msmart und requests
sind Attrappen, es gibt weder Broker noch Klimagerät. Ein Prüfstand mit
**68 Prüfzeilen** in fünf Gruppen: der Wille bei `start`, ohne Argument, bei
`restart`, `waechter`, `stop` und im Minutentakt; die Marke frisch, alt, aus
der Zukunft, leer, unlesbar und ohne lesbare Uhr; der ganze Upgrade-Ablauf
am Stück samt Lücke; die beiden Wege der Oberfläche; die zwei Zeilen im
Reiter Test. Gegen das veröffentlichte Archiv 4.5.6 sind 20 davon rot, gegen
4.5.7 keine. Jede der zwölf Korrekturen ist außerdem einzeln in einer Kopie
zurückgebaut worden; jede macht genau die vorher benannten Zeilen rot.

## Neu in 4.5.6

### Ein Dienst ohne PID-Datei überlebte Update, Neustart und Deinstallation

`stop` im Startskript beendete nur den Prozess, dessen Nummer in
`data/plugins/Midea2Lox/dienst.pid` stand. Lief ein Dienst ohne diese Datei
— am Startskript vorbei gestartet, oder die Datei war verloren —, meldete
`stop` „Midea2Lox laeuft nicht" und ließ ihn stehen. Nachgestellt in WSL mit
den echten Hakenskripten in der Reihenfolge des Installers (17.09.2026): der
alte Dienst überlebte `preupgrade.sh` und hielt den UDP-Port, der neue
meldete „Socket konnte nicht gebunden werden" und endete, und der minütliche
Wächter gab nach fünf Versuchen auf — während der alte Prozess weiter
Herzschläge schickte und `status/dienst` 0 meldete. Derselbe Dienst blieb
beim Neustart über die Oberfläche und nach dem Deinstallieren übrig.

`stop` sucht jetzt zusätzlich jeden Prozess, der genau dieses Programm
ausführt: er gehört dem Dienstbenutzer (als root aufgerufen `loxberry`), das
erste Argument ist ein Python-Interpreter, und das zweite ist, gegen den
Arbeitsordner des Prozesses aufgelöst, genau `midea2lox.py` im eigenen
Datenordner. Ein Teilwort der Befehlszeile genügt nicht. Ein solcher Prozess
bekommt SIGTERM und fünf Sekunden Zeit; SIGKILL folgt nur, wenn seine
Befehlszeile dann noch dieselbe ist. Das wirkt in `preupgrade.sh`, in
`postupgrade.sh` und beim Neustart über die Oberfläche. `uninstall` hält den
Dienst jetzt ebenfalls über das Startskript an; der bisherige Weg über die
PID-Datei bleibt als Rückfall, falls das Startskript fehlt.

Gemessen in WSL, nicht am Gerät, in 11 Fällen mit 28 Prüfzeilen: vor der
Änderung 10 rot, danach alle grün. Stehen bleiben, wie sie sollen: `tail` auf
die Datei, `python3 -c` mit dem Dateinamen als Argument, dasselbe Programm in
einem Nachbarordner und unter einer anderen Wurzel, und ein Prozess eines
anderen Benutzers.

### Das Startskript meldete Erfolg, auch wenn der Dienst nicht hochkam

Am Ende von `daemon/daemon` stand ein nacktes `exit 0`. `start` prüft seit
4.3.0 die Wirkung — es wartet zwei Sekunden und sieht nach, ob der Prozess
noch lebt — und endet in `start_dienst` mit 1, wenn nicht; dieser Wert wurde
eine Zeile später weggeworfen. Gemessen in WSL (17.09.2026) mit einem
Programm, das sofort aufgibt: Ausgabe „FEHLER: Midea2Lox wurde gestartet,
lief aber nach zwei Sekunden nicht mehr", Rückgabewert **0**. Die Oberfläche
fragt genau diesen Wert ab (`mi_dienst()` in `mi_lib.php`) und meldete
darauf „Der Dienst wurde neu gestartet".

`start`, `restart` und `waechter` geben jetzt weiter, was `start_dienst`
gemessen hat; `stop` und ein laufender `status` bleiben bei 0, ein
gestoppter `status` und ein unbekanntes Argument bei 1. Die Oberfläche
meldet in derselben Lage jetzt `DIENST_FEHLGESCHLAGEN`. Der Kommentar in
`mi_lib.php`, der bis 4.5.5 das Gegenteil behauptete („daemon/daemon … endet
mit 1"), ist berichtigt.

`postupgrade.sh` startet den Dienst am Ende und warf den Rückgabewert
ebenfalls weg (`>/dev/null 2>&1`). Es sieht ihn jetzt an und schreibt
entweder `<OK> Midea2Lox laeuft.` oder die Ausgabe des Startskripts mit
`<WARNING>` ins Installationsprotokoll — das Einzige, was der Anwender von
den Hakenskripten je zu sehen bekommt.

### Die PID-Datei wurde nur am Dateinamen gegengeprüft

Für Dienste **ohne** PID-Datei erkennt 4.5.6 den eigenen Prozess pfadgenau
(siehe oben). Für die Nummer **aus** der PID-Datei stand daneben eine
zweite, losere Prüfung: es genügte, dass irgendein Argument der
Befehlszeile `midea2lox.py` hieß. Gemessen in WSL mit der Nummer eines
fremden Prozesses in der PID-Datei: `tail -f ./midea2lox.py` im Datenordner,
`python3 -c '…' ./midea2lox.py`, ein gleichnamiges Programm im
Nachbarordner und `tail ./midea2lox.py -f` wurden alle als laufender Dienst
gemeldet — im Startskript und in der Oberfläche. Prozessnummern werden
wiederverwendet, und die Oberfläche bietet einen Stopp-Knopf.

Beide Stellen fragen jetzt dasselbe: erstes Argument ein Python-Interpreter,
zweites Argument gegen den Arbeitsordner des Prozesses aufgelöst genau
`data/plugins/Midea2Lox/midea2lox.py`. Der **Benutzer** wird dabei
absichtlich nicht geprüft — die Nummer stammt aus dem eigenen Datenordner,
und eine zusätzliche Bedingung hätte einen laufenden Dienst als gestoppt
angezeigt und den Wächter eine zweite Ausfertigung starten lassen. Geprüft
ist das in beide Richtungen: als root mit gefundenem und mit nicht
auffindbarem Dienstbenutzer meldet `status` weiter „läuft".

Dieselbe lose Prüfung stand in den Rückfallwegen von `preupgrade.sh` und
`uninstall` — den Wegen, die greifen, wenn das Startskript fehlt. Dort war
sie gefährlicher, denn dort folgt ein `kill`. Beide sind umgestellt.

### `kill -9` ohne zweiten Blick auf die Befehlszeile

In denselben Rückfallwegen stand `kill`, dann Warten, dann `kill -9` — ohne
noch einmal nachzusehen, wem die Nummer inzwischen gehört. Nachgestellt mit
einem Prozess, der beim SIGTERM seine Befehlszeile wechselt (`execv` auf
`sleep`), die Nummer aber behält: bis 4.5.5 traf das `kill -9` das fremde
Programm. Vor jedem Tötungsschritt wird die Befehlszeile jetzt erneut
geprüft; `uninstall` meldet außerdem nicht mehr „Dienst beendet", ohne
nachgesehen zu haben, sondern nennt einen überlebenden Prozess mit seiner
Nummer.

### Eine Fehlerzeile aus `/proc`, die niemand abstellen konnte

`tr '\0' '\n' < "/proc/$1/cmdline" 2>/dev/null` sieht aus, als wäre der
Fehlerkanal stillgelegt. Die Schale führt Umleitungen aber von links nach
rechts aus: scheitert das Öffnen der Datei, ist `2>/dev/null` noch nicht in
Kraft, und `/proc/<pid>/cmdline: No such file or directory` steht in der
Ausgabe. Das trat auf, sobald in der PID-Datei die Nummer eines beendeten
Prozesses stand — also nach jedem unsauberen Ende des Dienstes — und landete
so auch im Installations- und im Deinstallationsprotokoll. Gemessen in WSL
mit beiden Formen nebeneinander. Im Startskript ist die Stelle mit der losen
Prüfung entfallen, in `preupgrade.sh` und `uninstall` steht die Umleitung
jetzt vor der Eingabe.

### Der Wächter zählte einen laufenden Dienst weg

Fehlte die PID-Datei, während der Dienst lief, sah `start` nur die fehlende
Datei und startete eine zweite Ausfertigung. Die kam wegen des belegten
UDP-Ports nicht hoch; der minütliche Wächter schrieb fünfmal „Neustart ohne
Wirkung" und danach „aufgegeben" — über einen Dienst, der die ganze Zeit
lief und weiter Werte lieferte.

`start` sucht jetzt zuerst nach genau diesem Fall. Findet es **einen**
eigenen Prozess ohne PID-Datei, trägt es dessen Nummer nach, startet nichts
und beendet nichts, und meldet das einmal — im Plugin-Protokoll und über den
Systemlogger, mit dem Hinweis, dass dieser Prozess die Dateien ausführt, mit
denen er gestartet wurde, und ein „Dienst neu starten" in der Oberfläche die
neue Fassung holt. Finden sich **mehrere**, wird nichts angefasst und der
Doppelbetrieb gemeldet; eine Nummer davon nachzutragen würde ihn zudecken.
`status` nennt einen solchen Prozess jetzt ebenfalls, ohne etwas zu ändern.

Beendet wird an dieser Stelle absichtlich nicht: ein Wächter, der einen
Dienst abschießt, der Werte liefert, ist schlimmer als der Zustand, den er
beheben soll. Der Weg zum Beenden ist ausdrücklich — `stop` und `restart`
räumen solche Prozesse ab, und `preupgrade.sh` ruft `stop`. Gemessen in
beide Richtungen: mit dem Nachtragen endet das Karussell beim ersten Takt
(ein Dienst, Zähler fort, sechs Takte hintereinander ruhig); ohne es läuft
es bis „aufgegeben". Ein zweiter Dienst entsteht in keinem der Fälle.

Die Zeile des Wächters heißt deshalb nicht mehr „Dienst lief nicht —
Neustart", sondern „status meldete den Dienst nicht als laufend —
Startversuch": was daraus wurde, schreibt das Startskript selbst.

### Beim Systemstart gehörten Datenordner und Merkdatei root

`daemon/daemon` läuft in zwei Rollen: beim Systemstart als root (es liegt
unter `system/daemons/plugins/`), im Betrieb als `loxberry` — so rufen es
die Oberfläche und der minütliche Wächter. Angelegt hat es im root-Zweig
Protokollordner, Datenordner und die Merkdatei `soll_laufen`, und übereignet
hat es davon nur die PID-Datei. Was root anlegt, gehört root; `loxberry`
kann es danach weder überschreiben noch entfernen. Betroffen wäre vor allem
`soll_laufen`: ohne sie zu löschen lässt sich der Dienst über die Oberfläche
nicht anhalten, der Wächter startet ihn binnen einer Minute wieder.

Alles, was das Skript unter `data/` und `log/` anlegt, wird jetzt dem
Dienstbenutzer übereignet. Gemessen ist der Aufruf, nicht der
Eigentümerwechsel: der Prüfbenutzer in WSL ist nicht root. Die Messung
benutzt eine `chown`-Attrappe auf dem Suchpfad, die ihre Argumente
mitschreibt — bis 4.5.5 genau ein Aufruf (die PID-Datei), jetzt
Protokollordner, Datenordner, Merkdatei und PID-Datei. Ohne root wird
weiterhin kein `chown` gerufen.

### Wie das geprüft ist

Alles in WSL/Ubuntu gemessen, **nicht** am Gerät; paho, msmart und requests
sind Attrappen, der root-Zweig ist über eine `id`-Attrappe nachgestellt.
Zwei Prüfstände mit zusammen 112 Prüfzeilen: 28 zum Dienst ohne PID-Datei,
84 zu Rückgabewerten, Erkennung, Wächter, Rückfallwegen und Eigentümer.
Gegen das veröffentlichte Archiv 4.5.5 sind 42 der 84 rot, gegen 4.5.6 keine.
Jede der sechzehn Korrekturen ist außerdem einzeln in einer Kopie
zurückgebaut worden; jede macht genau die vorher benannten Zeilen rot.

## Neu in 4.5.5

Dieser Abschnitt ist am 17.09.2026 nachgetragen worden — er fehlte bei der
Veröffentlichung. Was hier steht, stammt aus dem Vergleich der
veröffentlichten Archive 4.5.4 und 4.5.5, Eintrag für Eintrag: acht Dateien
unterscheiden sich, davon `plugin.cfg`, `release.cfg` und `prerelease.cfg`
nur in der Fassungsnummer.

### Die Fassungsnummer in der Kopfzeile fehlte, je nach Einstieg

`LBSystem::pluginversion()` ohne Argument beantwortet die Frage nicht aus
dem eigenen Ablageort, sondern aus dem zuerst eingebundenen Skript. Am Gerät
gemessen (17.09.2026): aus einem fremden Einstieg heraus kam `NULL` zurück,
mit dem Ordnernamen die installierte Fassung. Beide Stellen —
`index.php` für die Kopfzeile und `mi_lib.php` für den Reiter Test — fragen
seither mit `basename(__DIR__)`; installiert liegt diese Datei unter
`webfrontend/htmlauth/plugins/<ordner>/`.

### `REQUEST_METHOD` gibt es unter der Kommandozeile nicht

Drei Stellen in `index.php` lasen `$_SERVER['REQUEST_METHOD']` unmittelbar:
der Vorlagen-Download, „Einstellungen sichern" und „Einstellungen
zurückspielen". Unter der PHP-Kommandozeile ist der Schlüssel nicht
gesetzt, und jede Prüfung, die die Seite dort durchläuft, meldete eine
Beanstandung, die es auf dem Webserver nicht gibt. Gelesen wird jetzt mit
`isset()` davor — so, wie `mi_wachposten()` in `mi_lib.php` es schon tat. Am
Verhalten im Browser ändert sich nichts.

### Sprachdateien neu sortiert, kein Schlüssel geändert

In `language_de.ini` und `language_en.ini` sind 21 Schlüssel an das
Dateiende gewandert. Nachgezählt: vorher wie nachher **503** Schlüssel je
Sprache, keiner hinzugekommen, keiner fortgefallen, kein Wert geändert.

### Ein Platzhalter im Kommentar

Im Kopfkommentar von `cron/cron.01min` stand `REPLACELBPDATADIR` als
Beispiel. Der Installateur ersetzt diese Zeichenfolge überall in der Datei,
auch im Kommentar; der Satz erklärte danach etwas anderes als gemeint. Er
nennt den Platzhalter jetzt nicht mehr beim Namen.

## Neu in 4.5.4

Vier Berichtigungen, alle am Gerät gemessen — und eine davon nimmt eine
Aussage zurück, die seit 4.3.2 in dieser README stand.

### Retain wird jetzt je Thema entschieden

Bis 4.5.3 ging **jede** Veröffentlichung zurückbehalten hinaus. Am Broker
gemessen (13.09.2026): fünf retained Themen unter `Midea2Lox/#` — und
**vier davon waren das Lebenszeichen**.

Der Hausstandard seit 03.09.2026 trennt: **Zustände** zurückbehalten,
**Messwerte mit Zeitbezug** nicht, das **Lebenszeichen nie**. Die Begründung
für das Lebenszeichen ist die wichtigste: zurückbehalten meldete es nach
einem Neustart des Miniservers sofort wieder „läuft" — auch dann, wenn der
Dienst längst tot ist. Ein Lebenszeichen, das den eigenen Tod überlebt, ist
keines.

Ohne Retain gehen jetzt hinaus: `status/ts`, `status/zaehler`, `status/ok`,
`status/dienst` sowie `indoor_temperature`, `outdoor_temperature`,
`indoor_humidity`, `total_energy_usage`, `current_energy_usage` und
`real_time_power_usage`. Alles Übrige bleibt zurückbehalten. Die
Themen-Tabelle im Reiter MQTT führt die Angabe jetzt als eigene Spalte.

Dienst und Oberfläche führen dieselbe Liste an zwei Orten — damit sie nicht
auseinanderlaufen, hält eine neue Prüfzeile im Reiter Test sie gegeneinander.

### Die Abo-Prüfzeile sah in der falschen Datei nach

Sie las `config/system/subscriptions.json`. Am Gerät war das eine Leiche vom
28.08. mit fünf fremden Einträgen; die Liste, die das Gateway heute führt,
ist `mqttgateway.json` mit 52. Jetzt wird die erste lesbare der beiden
genommen — eine feste Entscheidung für eine wäre die nächste Wette auf eine
LoxBerry-Fassung.

Und sie kennt einen zweiten Weg: steht unser Thema nicht in der Liste des
Anwenders, aber unsere mitgelieferte Datei stimmt, ist das ebenfalls ein
Haken — denn das Gateway liest sie.

### Der Installateur liest die Abo-Datei nicht, das Gateway schon

`sbin/plugininstall.pl` enthält den Namen `mqtt_subscriptions` **kein
einziges Mal**; er kopiert die Datei nur. Das Einlesen macht allein das
Gateway — siehe den berichtigten Abschnitt weiter unten.

### Die Loxone-Vorlage baute einen leeren virtuellen Eingang

Ein Fehler von mir, seit 4.5.0 veröffentlicht. `mi_automatik_werte()` führt
`automatik/grund` bewusst mit `null` als Vorlage — ein Satz gehört in keinen
virtuellen Eingang, genau wie `operational_mode`. Die Geräte-Schleife prüft das,
die Status-Schleife nicht: sie baute daraus einen `VirtualInHttpCmd` mit leerem
`Signed`, `MinVal`, `MaxVal` und `Unit`. Beim Rendern unter 7.4 und 8.4 stand
dreimal „Trying to access array offset on value of type null“ im Protokoll.
Die erzeugte Vorlage hat jetzt sieben statt acht Eingänge, keinen leeren davon.

## Neu in 4.5.3

**Der Grund einer abgelehnten Broker-Anmeldung stand nur unter paho 1.x im
Klartext.** `on_connect` kannte die Rückmeldecodes 1 bis 5 aus MQTT 3.1.1.
Dieser Dienst legt den Client aber mit `CallbackAPIVersion.VERSION2` an, sobald
paho 2.x vorliegt — und dort kommen dieselben Fälle als Ursachencodes von
MQTT 5 an (132 bis 136). Im Protokoll stand dann „Ungueltiger Rueckgabecode
135" statt „Nicht autorisiert": die Zahl statt des Grundes, ausgerechnet bei
falschen Zugangsdaten.

Aufgefallen ist es nicht im Betrieb, denn im Venv dieses Plugins steckt heute
paho **1.6.1** (am Gerät gemessen, 11.09.2026). Die paho-Fassung hängt an der
Python-Umgebung jeder Linie einzeln; im selben Haus laufen 1.6.1 und 2.1.0
nebeneinander. Eine Linie kann sich also nicht darauf verlassen, welche
Zählweise ankommt — die Tabelle kennt jetzt beide.

Geprüft mit `Werkzeuge/connack_klartext_pruefen.py`, das den Rückruf aus der
Datei schneidet und beide Zählweisen durchspielt: gegen 4.5.3 grün (15 von 15),
gegen 4.5.2 rot an genau den Codes 134 und 135. **Am Verhalten ändert sich
nichts** — abgelehnt wurde vorher wie nachher, nur der Grund ist jetzt lesbar.

## Neu in 4.5.2

- **Nur Schreibweise.** Die Sprachdateien führten für sichtbare Zeichen
  noch HTML-Entitäten (`&mdash;`, `&auml;`, `&bdquo;`); jetzt stehen dort die
  Zeichen selbst — in dieser Fassung **10** Stück. Das ist der Hausbeschluss
  vom 14.08.2026: mit direkten Zeichen darf `htmlspecialchars` folgenlos
  zweimal laufen, und die Doppelmaskierung fällt als Fehlerklasse weg.
  `&nbsp;` und `&shy;` bleiben Entität (unsichtbares Zeichen im Quelltext ist
  eine Wartungsfalle), ebenso die bedeutungstragenden `&amp;`, `&lt;`, `&gt;`,
  `&quot;` und `&apos;`. **Am Verhalten ändert sich nichts.**

## Neu in 4.5.1

- **Das Auswahlfeld zeichnet seinen Pfeil selbst.** Bis 4.5.0 kam er von der
  Oberfläche des LoxBerry. Am 05.09.2026 am Gerät gemessen (LoxBerry 4.0.0.15,
  `system/css/components.css`): deren Regel `.lb-content select`
  gibt es erst seit der neuen Oberfläche, und jede eigene Feldregel mit der
  Kurzform `background:` löscht sie wieder. Darauf soll sich eine
  Plugin-Oberfläche nicht verlassen (`Regeln/04`). Sonst ist an dieser
  Fassung nichts geändert.

### Der Dienst konnte sein Protokoll verlieren, ohne dass es auffiel

`log/plugins` liegt auf einer Ramdisk (`/dev/zram0`). Wird sie geleert — beim
Neustart, durch LoxBerrys `log_maint`, oder von Hand —, ist die Datei fort. Ein
`RotatingFileHandler`, der sie beim Start **einmal** geöffnet hat, schreibt
danach bis zum nächsten Neustart in einen gelöschten Inode: keine
Fehlermeldung, keine Datei, kein Hinweis. Auch die Rotation greift dann nicht
mehr.

Diese Fassung benutzt deshalb `WachsameRotation` in `data/midea2lox.py` — einen
umlaufenden Handler, der vor jeder Zeile Gerätenummer und Inode vergleicht und
nötigenfalls neu öffnet. Die Standardbibliothek hat für den einen Fall den
`WatchedFileHandler` und für den anderen den `RotatingFileHandler`, aber
nichts, was beides kann; deshalb die eigene Klasse.

Auf dem LoxBerry geeicht, vier Prüfungen und in beide Richtungen: schreiben,
nach dem Löschen weiterschreiben, Umlauf bei Überlänge, nach dem Umlauf erneut
löschen. Mit dem alten Handler ist die Zeile nach dem Löschen verloren und
bleibt es, mit dem neuen steht sie in der wieder angelegten Datei. Auf einem
Windows-Arbeitsplatz lässt sich das nicht messen — dort kann eine offene Datei
gar nicht gelöscht werden.

Aufgefallen ist die Bauart am Heimkino-Plugin, dessen Dienst sieben Stunden
ohne Protokolldatei lief, und am laufenden Gerät belegt: der
Midea2Lox-Dienst hielt `midea2lox.log (deleted)` offen, während unter
demselben Namen längst eine neue Datei fortgeschrieben wurde — von außen sah
das Plugin gesund aus. Elf Linien tragen dieselbe Bauart; alle elf sind am
06.09.2026 nachgezogen worden.

## Was 4.5.0 bringt

**Das Plugin hört jetzt zu.** Bis 4.4.0 hat der Dienst ausschließlich
gesendet; neu ist ein MQTT-Abo und daraus eine Automatik, die die
Solltemperatur verschiebt, wenn der Strom gerade günstig ist oder die
eigene Anlage mehr liefert, als das Haus braucht.

### Es holt die Preise nicht selbst — mit Absicht

Das Abrufen von Spotpreisen ist im LoxBerry-Bestand bereits dreimal gebaut,
geprüft und veröffentlicht: **Spotpreis-aWATTar**, **Spotpreis-Tibber**
und **Spotpreis-Octopus**. Diese Plugins bringen Zugangsdaten, Morgenpreise,
Rangfolge, Zeitfenster und eine vollständige Regelmaschine mit und
veröffentlichen alles über MQTT. Den PV-Überschuss kennt die
**Einspeisebremse** und veröffentlicht ihn ebenso.

Ein vierter Abruf im Midea-Plugin wäre eine zweite Stelle zum Pflegen, ein
zweiter Satz Zugangsdaten und eine zweite Abrufgrenze. Midea2Lox abonniert
deshalb, was ohnehin auf dem Broker liegt:

| Feld | übliches Thema |
|---|---|
| Preissignal | `spot_awattar/regel/1/aktiv` oder `tibber/regel/1/aktiv` |
| PV-Überschuss | `einspeisebremse/ueberschuss` (in Watt) |

Beide Felder sind freiwillig. Ein leeres Feld heißt: diese Quelle wird
nicht benutzt. Die Automatik greift, sobald **eine** von beiden zutrifft.

### Was sie am Gerät tut

Die Verschiebung wirkt **je nach Betriebsart** in die sinnvolle Richtung:
beim Kühlen wird der Sollwert gesenkt (vorkühlen), beim Heizen angehoben
(vorwärmen). Betriebsarten ohne Sollwert werden übersprungen. Ober- und
Untergrenze sind einstellbar; darüber hinaus klemmt der Dienst ohnehin auf
die Grenzen, die das Gerät selbst nennt.

Auf Wunsch schaltet die Automatik zusätzlich Turbo ein oder das Gerät
selbst an. Beides steht ab Werk auf **aus**.

### Die drei Zusagen, auf die es ankommt

1. **Sie macht nur rückgängig, was sie selbst getan hat.** Ein Gerät,
   das Sie eingeschaltet vorgefunden haben, schaltet sie nicht aus. Gemessen.
2. **Handbetrieb hat Vorrang — und wird nicht rückabgewickelt.** Jeder
   Befehl aus Loxone oder vom Reiter Test setzt die Automatik für die
   eingestellte Sperrzeit aus; eine reine Statusabfrage nicht. Dabei stellt
   sie den Sollwert zurück, **schaltet aber nicht**: ein Gerät, das Sie
   gerade eingeschaltet haben, würde sie sonst Sekunden später wieder
   ausschalten. Bleibt das Signal dagegen einfach aus, ist niemand da, der
   etwas anderes wollte — dann schaltet sie zurück. Gemessen.
3. **Im Zweifel lässt sie los.** Kommt ein Signal nicht mehr nach, ist es
   unlesbar oder älter als die eingestellte Höchstdauer, gilt es als nicht
   vorhanden, und der vorgefundene Zustand wird wiederhergestellt. Gemessen.

### Ab Werk aus

`auto_ein` steht auf 0. Eine Funktion, die von sich aus in ein Klimagerät
greift, wird nicht durch ein Update eingeschaltet. Tragen Sie zuerst die
Themen ein, sehen Sie im Reiter **Test** nach, ob wirklich etwas ankommt —
dort stehen drei neue Zeilen dafür —, und schalten Sie sie erst dann ein.

### Was das Plugin selbst veröffentlicht

Vier neue Themen, damit in Loxone sichtbar ist, was die Automatik tut:
`automatik/aktiv`, `automatik/gesperrt`, `automatik/geraete` und
`automatik/grund` (Klartext). Die ersten drei stehen auch in der erzeugten
Loxone-Vorlage für die virtuellen Eingänge; `automatik/grund` ist ein
Satz und bekommt deshalb keinen virtuellen Eingang.

### Nebenbei behoben

Eine Protokollzeile nannte die Statusabfrage noch mit Komma
(`<ID>,status`) — ein Überbleibsel des Trennzeichen-Befunds aus 4.4.0.

## Was 4.4.0 bringt

Diese Fassung behebt Befunde einer vollständigen Durchsicht. Zwei davon
haben beim Anwender wirklich etwas kaputtgemacht.

### Die erzeugte Befehlsvorlage schaltete nichts

Die Vorlage für den virtuellen Ausgang und die ganze Anleitung schrieben den
Befehlstext als `<Nummer>,<Befehl>` — mit **Komma**. Der Dienst zerlegt das
Datagramm aber an **Leerzeichen** und sucht die Gerätenummer argumentweise;
ein Komma dazwischen macht daraus ein einziges Argument, das keine reine
Ziffernfolge ist. Am laufenden Dienst gemessen: `missing device_id`, und
keiner der 27 Befehle je Gerät hat je etwas geschaltet. Der Knopf *Senden* im
Reiter Test arbeitete die ganze Zeit richtig — er benutzt das Leerzeichen.

**Wer die alte Vorlage importiert hat, muss sie neu erzeugen und neu
importieren.** Wer die virtuellen Ausgänge von Hand mit einem Leerzeichen
angelegt hat, ist nicht betroffen.

### Der Dienst galt als tot, während er lief

`daemon/daemon` schrieb im root-Zweig die falsche Prozessnummer in die
PID-Datei: das `&` beendete die ganze UND-Liste statt nur den `nohup`-Aufruf,
und `$!` lieferte damit die Hüllschale statt `python3`. Gemessen unter bash
5.3: in der Datei stand 4077, das arbeitende `python3` war 4080. Folge:
*Dienst gestoppt* bei laufendem Dienst, ein Neustartversuch pro Minute,
Aufgabe nach fünf — und `stop`, Update und Deinstallation erreichten den
Prozess nie mehr.

### Weiter behoben

* **Die Baustein-Liste war nicht 1:1 nachbaubar.** Der ODER-Baustein, auf den
  die Benachrichtigungszeile verwies, fehlte als eigene Zeile; und das Alter
  des Lebenszeichens ist ein analoger Sekundenwert, der ohne
  Schwellwertschalter gar nicht an ein ODER passt. Beide Zeilen sind jetzt da.
* **Eine Sicherung ohne alle Schlüssel setzte den Rest still auf die
  Werksvorgabe** — das hinterlegte Midea-Kennwort war danach weg. Jetzt bleibt
  unverändert, was nicht in der Datei steht, und die Seite sagt, welche
  Schlüssel das waren.
* **Das Deinstallieren ließ die Zweitschriften liegen**, in denen Kennwort und
  Gerätetoken stehen: abgeräumt wurde ein Dateiname, den niemand schreibt.
* **Ein Aktualisierungsversuch ohne Internet machte ein laufendes Plugin
  tot.** Die virtuelle Umgebung wird jetzt beiseitegelegt statt weggeworfen
  und bei einem Fehlschlag zurückgerollt.
* **MQTT wurde genau einmal verbunden.** Kam der Dienst nach einem Neustart
  vor dem Broker hoch, sendete er bis zum nächsten Dienstneustart über HTTP —
  mit anderen Zielnamen, also stumm in Loxone. Jetzt baut der Netzwerkfaden
  die Verbindung selbst wieder auf.
* **Der Sendeweg blockierte die Ereignisschleife.** Schwieg der Miniserver,
  stand der Empfang, und der Herzschlag alterte über seine Grenze hinaus —
  roter Herzschlag bei kerngesundem Dienst.
* **Ein einziges großes UDP-Paket konnte das Protokoll wegrotieren.** Es gibt
  jetzt eine Längengrenze, und ein zu langes Paket wird gemeldet, nicht
  stillschweigend verarbeitet.
* **„Der Dienst wurde neu gestartet“ war eine Behauptung**, kein Befund: der
  Rückgabewert des Startskripts wurde geholt und weggeworfen.
* **Die Gerätenummer** darf jetzt überall 10 bis 19 Ziffern haben — dieselbe
  Grenze wie im Dienst. Die Oberfläche nahm vorher 6 bis 20 an, und die
  Beispielnummer hatte neun.
* Dazu: ein `#`-Abonnement im Gateway wird als Treffer erkannt; Zugangsdaten
  gehen durch dieselbe Positivliste wie alles andere; zwei Vorgänge in einer
  Anfrage werden abgewiesen statt beide ausgeführt; ungültiges UTF-8 in der
  Gerätedatei lässt den Namen nicht mehr verschwinden; Protokollzeitstempel
  tragen Jahr und Sekunden.

## Was 4.3.0 bringt

Diese Fassung behebt fünf Stellen, an denen das Plugin still das Falsche tat,
und ergänzt sechs Funktionen. Alles Schwere ist gemessen; was nicht gemessen
werden konnte, steht unten unter *Offene Punkte*.

### Behoben

* **Der Knopf „Einstellungen sichern" lieferte eine leere Seite.**
  `index.php` rief `mi_cfg()` ohne Argumente auf; die Funktion verlangt zwei.
  Gemessen unter PHP 7.4.33 und 8.4.24: `ArgumentCountError`, Rückgabewert
  255, 0 Byte Ausgabe — über einen Webserver HTTP 500. Auf dem Gerät steht
  `display_errors` aus, es gab also nicht einmal einen Text zum Suchen.
* **Das Zurückspielen wurde vom nächsten Speichern rückgängig gemacht.**
  Nach dem Schreiben wurde die Konfiguration nicht neu gelesen, und die
  Erfolgsmeldung landete in einer Variablen, die nirgends ausgegeben wird.
  Die Seite sah danach aus wie vorher; wer daraufhin „Speichern" drückte,
  schrieb alles zurück — außer dem Kennwort, das aus der Sicherung stehen
  blieb. Genau die halb zurückgespielte Konfiguration, die der Code an
  anderer Stelle mit großem Aufwand verhindert.
* **Ein einziges UDP-Paket, das kein UTF-8 war, beendete den Dienst.**
  Die Umwandlung stand vor der Absicherung. Nachgestellt mit einem echten
  Socket: das erste Paket wurde verarbeitet, das zweite tötete den Dienst,
  das dritte kam nie an — und im Protokoll stand kein Grund. Der Empfang
  läuft jetzt über einen Datagramm-Endpunkt mit Warteschlange; ein
  unbrauchbares Paket wird gemeldet und verworfen.
* **Die Gerätesuche starb an dem Fall, für den sie geschrieben war.**
  `discover.py` fragte einen gerade erst angelegten Abschnitt nach dem
  Schlüssel `token`; `RawConfigParser` wirft dort `NoOptionError`. Das nackte
  `except` fing ihn und beendete das Skript **vor** den beiden
  Schreibbefehlen — `devices.cfg` wurde nie geschrieben, auch nicht für die
  Geräte, die vorher schon gefunden waren. Betroffen war jedes V2-Gerät und
  jedes V3-Gerät ohne Wolkenantwort.
* **Die eingetragenen Zugangsdaten und die Region erreichten `msmart-ng`
  nicht.** `MideaUser`, `MideaPassword` und `region` kamen im ganzen
  `data/`-Baum kein einziges Mal vor, während vier Stellen des Plugins
  sagten, sie würden für die Gerätesuche gebraucht. Sie werden jetzt
  übergeben — und die Regionsliste ist vorher auf das gebracht worden, was
  `msmart-ng` überhaupt kennt (siehe unten).

Dazu: fehlendes Formularmerkmal an allen Formularen, ungeprüfte Werte in der
Sicherungsdatei, ein Wiederholungszähler, den Auffrischen und Anwenden sich
teilten, eine Bedingung, die wegen des Operatorrangs vor jedem Setzbefehl ein
überflüssiges Auffrischen auslöste, `rate_select`, das nie funktionieren
konnte, die Lüfterstufe `Full`, die als `Max` zurückkam, und ein Gerät, das
bei mitgesendeter IP ohne Token angesprochen wurde.

### Neu

* **Abfragetakt.** Das Plugin war rein reaktiv: ohne ein UDP-Paket aus Loxone
  passierte nichts. Jetzt gibt es ein Feld *Abfragetakt* — **ab Werk 0, also
  aus**, damit eine bestehende Anlage nicht die doppelte Last bekommt.
* **Lebenszeichen.** Vier Themen: `status/ok`, `status/ts`, `status/zaehler`
  und `status/dienst`. Ein virtueller Eingang behält seinen letzten Wert;
  ohne Lebenszeichen sieht ein toter Dienst in der App aus wie ein ruhiger.
  `status/dienst` misst der minütliche Cron-Lauf, nicht der Dienst selbst —
  ein Dienst, der seinen eigenen Tod melden soll, ist der falsche Zeuge.
* **Die Sicherung trägt den Aktionstoken.** Sie enthält jetzt auch die
  Geräteliste samt `token` und `key` je Gerät. Ohne sie standen nach dem
  Zurückspielen alle Felder richtig, und man musste trotzdem neu suchen
  lassen — die Datei war für ihren eigentlichen Zweck, den Umzug, wertlos.
  **Damit trägt sie ein Geheimnis; der Warnkasten am Knopf sagt das.**
* **Zweite Loxone-Vorlage für die Befehle.** Bisher erzeugte das Plugin nur
  die virtuellen Eingänge. Jetzt gibt es einen zweiten Knopf für den
  virtuellen Ausgang samt allen 27 Befehlen.
* **Die Themenliste ist vollständig.** Der Dienst sendet 28 Werte je Gerät;
  die Tabelle nannte 7. Alle 28 stehen jetzt im Reiter MQTT, gegliedert nach
  Grundwerten, Komfort und Energie — die drei Energiewerte waren nirgends
  dokumentiert.
* **Schalten aus dem Reiter Test**, mit Trockenlauf. Der Befehl geht über
  denselben Weg wie ein Befehl aus Loxone; was hier funktioniert,
  funktioniert dort.

Dazu: eigene Bezeichnung je Gerät, einstellbares MQTT-Themenpräfix,
einstellbare Wartezeit auf den Miniserver, eine Aufgabegrenze für den
Wächter, und eine Selbstprüfung, die statt acht Zeilen den ganzen
Einrichtungsweg abfragt.

### Die Region — warum die Liste kürzer geworden ist

Die Oberfläche bot elf Regionen an. Am Quelltext von `msmart-ng` nachgesehen
(`mill1000/midea-msmart`, Zweig `main`, abgerufen am 27.08.2026) kennt die
Bibliothek genau **drei** Wolkenbereiche: `DE`, `KR` und `US`. Ohne Angabe
gilt die Vorgabe `US`; eine unbekannte Region beendet die Suche mit einem
`ValueError`.

Wer die Felder einfach „anschließt", ohne das vorher zu messen, macht damit
die Gerätesuche kaputt, die heute wenigstens läuft: neun von elf Einträgen
hätten den Fehler ausgelöst. Deshalb bildet das Plugin die Länder auf den
Serverbereich ab — alle europäischen auf `DE` — und zeigt die Zuordnung im
Reiter Test an. China ist entfallen: dafür hat `msmart-ng` keine Zugangsdaten,
und ein Eintrag, der nur einen Fehler erzeugen kann, ist kein Angebot. Eine
bestehende Konfiguration mit `region=CN` wird beanstandet und **nicht** still
auf etwas anderes gebogen.

Nebenbei ist damit ein möglicher Grund für ausbleibende Token beseitigt: ohne
Regionsangabe nahm `msmart-ng` seine Vorgabe **US** — für ein europäisches
Konto der falsche Server.

### Am Gerät gemessen (29.08.2026)

Drei Fragen standen hier bis 4.3.2 als offen. Sie sind inzwischen an einem
Raspberry mit LoxBerry 4.0.0.15 beantwortet — gemessen, nicht gelesen:

1. **Liest das MQTT-Gateway die mitgelieferte Abo-Datei?** **Nein.** Zwei
   Probedateien, eine davon in einem wirklich installierten Plugin als
   Kontrollfall, sind nach einem Neustart des Gateways in keinem Abonnement
   gelandet. Die Abonnements stehen in
   `config/system/subscriptions.json`, nicht unter `config/system/mqtt/`.
   Der Reiter Test hat deshalb seit 4.3.2 **zwei** Zeilen dazu: eine sagt, ob
   die mitgelieferte Datei da ist und zum Präfix passt, die andere, ob das
   Thema wirklich abonniert **ist**.
2. **Welche Fassung hat das MQTT-Gateway?** Gemessen: `Mqtt.Gatewayversion`
   steht als **Zahl** in der `general.json`. Ist sie nicht lesbar, nennt die
   Oberfläche weiterhin **beide** Fälle, statt einen zu behaupten.
3. **Welche Fassung hat `paho-mqtt`?** Gemessen: im venv dieses Plugins
   **1.6.1**, im System-Python 2.1.0. Der Dienst übergibt die Rückruf-Fassung,
   wenn die Bibliothek sie kennt; `postinstall.sh` klemmt paho auf kleiner
   2.0.0, und die Selbstprüfung zeigt die Fassung an.

### Was weiterhin offen ist

* **Liest der Installateur `plugininstall.pl` die Abo-Datei einmalig beim
  Installieren?** Gemessen ist nur, dass das *Gateway* sie im laufenden
  Betrieb nicht liest. Bis das geklärt ist, sagt die Oberfläche genau das —
  und die Zeile *Ist unser Thema im Gateway abonniert?* beantwortet die Frage,
  auf die es ankommt, ohne diese Antwort zu brauchen.
* **Die drei Energiewerte** (`total_energy_usage`, `current_energy_usage`,
  `real_time_power_usage`) stehen in der Themenliste und in der Loxone-Vorlage,
  sind aber an keinem Gerät nachgemessen.
* **Alles, was ein echtes Klimagerät braucht** — Anmeldung, Auffrischen,
  Anwenden, die Fähigkeitsabfrage und die tatsächlichen Messwerte. Gemessen
  wurde stattdessen gegen Attrappen und über einen echten Webserver: die
  Oberfläche in allen Zuständen und beiden Sprachen, jeder Knopf, die
  Sicherung in sieben Fällen, der Dienst mit Attrappen für msmart, paho und
  requests.

**Die Energiewerte sind ausdrücklich ungemessen.** `msmart-ng` schreibt
selbst, viele Geräte meldeten Energiewerte, ohne sie anzukündigen. Wer sie
als Zählerstand nach Loxone gibt, misst sie vorher an einem Gerät nach — eine
Zahl, die richtig aussieht, ist schlimmer als keine.

---

## Was 4.1.0 behebt

Acht Meldungen eines Mitlesers, jede einzeln nachgestellt. Drei davon haben
sich beim Messen als etwas anderes herausgestellt als gemeldet — das steht
hier genauso wie die bestätigten Funde.

### Ausführung fremden Codes über `eval()` — bestätigt, aber enger

Gemeldet als „`eval()` an unzähligen Stellen, jede davon eine Lücke". Gezählt
waren es **27**. Die meisten stehen hinter einer Weißliste (`power.True` /
`power.False` und ähnlich) und lassen nur `True` oder `False` durch. **Drei**
standen nicht dahinter:

```
Zeile 317  elif eachArg.split(".")[0] == "humidity":
               device.target_humidity = eval(eachArg.split(".")[1])
Zeile 323  ... == "h_swing_angle":  eval('ac.SwingAngle.' + eachArg.split(".")[1])
Zeile 329  ... == "v_swing_angle":  dasselbe
```

Geprüft wurde jeweils nur das Wort **vor** dem ersten Punkt. Mit einer
Attrappe nachgestellt — beide Muster haben Code ausgeführt:

```
humidity.exec(chr(105)+chr(109)+…)            -> ausgeführt
h_swing_angle.A if 0 else exec(chr(105)+…)    -> ausgeführt
```

Punkte im Schadcode braucht es nicht, `chr()` setzt jede Zeichenkette
zusammen; `A if 0 else …` sorgt dafür, dass der unbrauchbare Vorspann
`ac.SwingAngle.A` gar nicht erst ausgewertet wird. Die vierte verdächtige
Stelle (`fan_speed_enum`, eine **Teilstring**-Prüfung) scheiterte am
`.upper()` — dort blieb ein Absturz, keine Ausführung.

Alle 27 Aufrufe sind durch feste Zuordnungstabellen und `getattr()` ersetzt.
Gegenprobe mit denselben Nutzlasten: nichts wird mehr ausgeführt, gültige
Befehle wirken unverändert (`ac.fan_speed_enum.Full` → `MAX`,
`h_swing_angle.BOTH` → der Aufzählungswert).

Einschränkung der Ehrlichkeit halber: Die drei Stellen liegen hinter einer
Fähigkeitsabfrage des Geräts. Ein Angreifer muss Gerätenummer und Geräte-IP
mitschicken — beides steht ihm frei, die IP darf sein eigener Rechner sein.

### `sys.exit()` tötet den Dienst — **nicht bestätigt**

Gemeldet: „Ein einziges falsches Paket killt den Prozess unwiderruflich."
Nachgestellt mit dem tatsächlichen Aufbau: `send_to_midea()` fängt mit
`except Exception`, das `SystemExit` **nicht** abdeckt — aber `start_server()`
fängt mit einem **nackten** `except:`, und das fängt `BaseException` und damit
auch `SystemExit`. Ergebnis: **5 von 5 Paketen überlebt**, der Dienst läuft
weiter.

Geändert wurde es trotzdem, aus zwei anderen Gründen: Die Meldung geht
verloren (protokolliert wird `sys.exc_info()`, also eine SystemExit-Spur statt
des Klartexts), und es ist eine Falle — wer das nackte `except:` später zu
`except Exception:` präzisiert, was jeder Ratgeber empfiehlt, macht den Dienst
damit unbeabsichtigt tötbar.

### Der Dienst starb woanders — eigener Fund

Der Weg, auf dem er wirklich stirbt, stand zwei Zeilen über der
Empfangsschleife:

```python
while True:
    if os.path.getsize(log_path + '/midea2lox.log') > 500000:
```

`getsize` steht **innerhalb** der Schleife und **außerhalb** jedes `try`.
Fehlt die Logdatei — gelöscht, wegrotiert, Verzeichnis neu angelegt —, wirft
sie einen `FileNotFoundError`, der aus `start_server()` herausfliegt.
`asyncio.run()` endet, der Dienst ist tot. Nachgestellt: genau so.

### Protokollrotation — richtige Empfehlung, falsche Begründung

Gemeldet: das Leeren der Datei im laufenden Betrieb erzeuge „ein Logfile
voller NULL-Bytes". Nachgemessen: **0 NULL-Bytes**. `logging` öffnet mit
`O_APPEND`, jeder Schreibvorgang geht ans tatsächliche Dateiende. Umgestellt
auf `RotatingFileHandler` wurde trotzdem — aus dem eigentlichen Grund: Das
Leeren wirft den gesamten Verlauf weg, und zwar genau dann, wenn er gebraucht
wird. Der Handler hält eine Sicherung vor.

### URL ohne Maskierung — bestätigt, mit einer Korrektur

Mit `urlparse` gegen die ungeschützte Fassung gemessen:

| Passwort | Rechner, der angesprochen wird |
|---|---|
| `Pass#wort` | `admin` (!) — Passwort verloren |
| `x/y` | `admin` — Passwort verloren |
| `was?` | `admin` — Passwort verloren |
| `a@b` | `192.168.1.10` — geht gut |

Das Ergebnis ist keine Fehlermeldung, sondern eine Anfrage an den falschen
Rechner. `@` bricht die Adresse allerdings **nicht** — der Parser nimmt das
letzte `@` als Trenner. Maskiert wird jetzt beides mit `quote()`.

### Fehlende Zeitgrenze, blockierendes `recvfrom` — bestätigt

`requests.get()` ohne `timeout` wartet unbegrenzt. Jetzt 5 Sekunden je Aufruf,
mit eigener Meldung bei Zeitüberschreitung. Das blockierende `recvfrom` in
einer `async def` bleibt architektonisch unsauber; da nichts sonst auf der
Ereignisschleife läuft, hat es keine messbare Folge — angefasst wird es nicht,
solange es keinen zweiten Nutzer der Schleife gibt.

### Einschleusung über `mi_python()` — **nicht nachstellbar**

Der Melder räumt selbst ein, dass der Fall „hier nicht zutrifft". Vier
Versuche (`; touch …`, `$(touch …)`, `a && touch …`) gegen PHP 7.4 und 8.1:
alle kamen wörtlich als Argument an, keiner wurde ausgeführt — `escapeshellarg`
greift. Umgestellt auf `proc_open` mit Argumentfeld wurde es dennoch: ohne
Zeichenkette gibt es gar keine Shell mehr, die etwas auslegen könnte, und
`escapeshellarg` verwirft Bytes, die in der eingestellten Locale kein gültiges
Zeichen ergeben — bei Pfaden mit Umlauten eine stille Falle.

### `mi_log_tail()` — Speicher ja, Geschwindigkeit nein

Gemessen an einer 522-kB-Datei, 200 Zeilen Ausgabe:

| Verfahren | Zeit | Speicher |
|---|---|---|
| `file()` + `array_reverse` (bisher) | 0,8 ms | **1436 KB** |
| `exec("tail -n 200")` (empfohlen) | **1,7 ms** | 34 KB |
| Rückwärts lesen mit `fseek` (jetzt) | **0,3 ms** | **34 KB** |

Der empfohlene Weg über `tail` spart zwar den Speicher, ist aber wegen des
zusätzlichen Prozesses **langsamer als das, was er ersetzen soll**. Rückwärts
lesen ist bei beidem besser und kommt ohne fremdes Programm aus. Die Ausgabe
ist Zeile für Zeile dieselbe; nachgeprüft.

### Weitere eigene Funde

- **`uninstall` räumte die venv nie weg.** Die Bedingung lautete
  `[ -d "$LBPBIN/$PDIR/venv" ]`. `$LBPBIN` ist in einem uninstall-Skript aber
  nicht gesetzt — LoxBerry übergibt die Pfade als Argumente. Geprüft wurde
  damit auf `/<ordner>/venv`, also einen Pfad direkt unter der Wurzel. Der
  Block lief nie, die 60 bis 100 MB blieben liegen. Dazu entfernt die Datei
  jetzt die Konfigurationssicherung (darin stehen Token und Schlüssel der
  Klimageräte).
- **Kein Wächter.** Zu Recht angemerkt: Fiel der Dienst aus, blieb das Plugin
  stumm, bis jemand die Oberfläche öffnete. Jetzt ein minütlicher Cron, der
  nur startet, wenn der Dienst laufen **soll** — die Merkdatei `soll_laufen`
  legt das daemon-Skript an und räumt sie bei `stop` fort, damit ein bewusst
  angehaltener Dienst nicht nach einer Minute wieder hochkommt.

### Hausstandard

Die Reiter waren `<div>` ohne Verweis, und `sm-active` vergab allein das
JavaScript — ohne JavaScript war die Seite leer und die Reiter nicht einmal
anklickbar. Jetzt echte Verweise mit serverseitigem `sm-active`. Alle 20
Bedienelemente haben `data-role="none"` (vorher: keines).

**Zweisprachig.** Die Oberfläche war deutsch: `mi_t()` und `mi_sprache()`
waren gebaut, ein Dutzend Schlüssel angelegt — die übrigen **225** sichtbaren
Texte standen fest im Quelltext. Jetzt gehen alle durch `mi_t()`, 238
Schlüssel deutsch und englisch deckungsgleich. Zwei INI-Fallen sind dabei
aufgelaufen und stehen als Warnung im Kopf der Sprachdateien: Werte mit `&`,
`(`, `)` oder `!` **müssen** in Anführungszeichen stehen, sonst fällt die
ganze Datei aus; und ein Schlüssel darf nicht `TRUE` heißen — das ist für den
INI-Parser ein Schlüsselwort.

Da es eine Abspaltung wird, sind auch die Symbol-Dateien neu: Die `icon.svg`
war bereits neu gezeichnet, die vier PNG stammten aber noch vom Original und
zeigten dessen Motiv. Sie sind jetzt aus der SVG erzeugt.

Beide PHP-Fassungen liefern zeichengleiche Ausgabe ohne eine einzige Meldung,
in beiden Sprachen.

## Was das Plugin macht

Auf dem LoxBerry läuft ein UDP-Dienst. Er nimmt Befehle vom Miniserver entgegen
und schickt sie an die Klimageräte im lokalen Netz — **ohne Umweg über die
Wolke des Herstellers**. Umgekehrt meldet er deren Zustand zurück: per UDP und,
falls das MQTT-Gateway installiert ist, zusätzlich per MQTT.

Die Zugangsdaten des Hersteller-Kontos werden **nur einmal** gebraucht, um beim
Suchen der Geräte Token und Schlüssel abzuholen. Danach läuft alles lokal.

## Installation

1. Archiv im LoxBerry-Plugin-Verwalter installieren.
2. Reiter **Einstellungen**: Miniserver, UDP-Port und die Zugangsdaten des
   Midea-Kontos eintragen, Region wählen.
3. **Speichern und Geräte suchen** — das dauert bis zu einer Minute.
4. Reiter **Test**: Selbstprüfung ansehen. Erst wenn dort kein **Kreuz** mehr
   steht, lohnt der Weg nach Loxone. Ein **Strich** ist kein Kreuz - er heißt
   nur, dass sich die Frage nicht messen ließ.
5. Reiter **Einbindung in Loxone**: Schritt für Schritt, mit vollständiger
   Baustein-Liste zum Nachbauen.

## Voraussetzungen

- LoxBerry 4 empfohlen (LoxBerry 3 auf Debian 12 funktioniert ebenfalls)
- **Python ≥ 3.10** — das prüft `preroot.sh` und bricht sonst mit einer
  Meldung ab. Ältere, über Jahre hochgezogene LoxBerry-Installationen bringen
  teils noch Python 3.7 oder 3.9 mit; hier hilft nur ein aktuelles Abbild.
- **Kein zusätzliches Plugin.** Der MQTT-Gateway ist seit LoxBerry 3 Bestandteil
  des Systems (`webfrontend/htmlauth/system/mqtt.cgi`, erreichbar unter
  *System → MQTT Gateway* bzw. `/admin/system/mqtt.cgi`). In der
  Vorgabekonfiguration steht der Broker auf `localhost:1883` und der Gateway
  startet automatisch mit.

Die Python-Module werden in eine **eigene virtuelle Umgebung** unter
`bin/plugins/Midea2Lox/venv` installiert. Das System-Python bleibt unangetastet
— und PEP 668, an dem systemweite pip-Installationen auf Debian 12/13
scheitern, spielt keine Rolle.

## Unterstützte Geräte

Klimageräte der Midea-Gruppe und ihrer Handelsmarken (Comfee, Kaisai, Senville,
Pioneer, Qlima, Rotenso, Inventor und viele weitere). Die eigentliche Arbeit
leistet [msmart-ng](https://github.com/mill1000/midea-msmart) von mill1000 —
dort steht auch, welche Geräte bekannt sind.

---

## Ursprüngliche README (Stand 3.4.8)

# Midea2Lox

Integration von Mideagroup Klimaanlagen in Loxone.
----- mit Loxone nicht getestet, folgende Hersteller können aber funktionieren----
Custom Integration for Midea Group(Ariston, Hualing, Senville, Klimaire, Kaysun, AirCon, Century, Pridiom, Thermocore, Comfee, Alpine Home Air, Artel, Beko, Electrolux, Galactic, Idea, Inventor, Kaisai, Mitsui, Mr. Cool, Neoclima, Olimpia Splendid, Pioneer, QLIMA, Royal Clima, Qzen, Toshiba, Carrier, Goodman, Friedrich, Samsung, Kenmore, Trane, Lennox, LG and much more) Air Conditioners via LAN.
----- nicht getestet----

Dieses Loxberry Plugin ermöglicht eine kommunikation zwischen dem Loxberry/Loxone zu Midea Klimaanlagen.

Der Hauptpart, das Python3 Midea Script, stammt im Ursprung von NeoAcheron https://github.com/NeoAcheron/midea-ac-py (Cloud Version bis Midea2Lox 1.1) . Vielen Dank dafür, ohne dieses Plugin hätte das nicht funktioniert.
Für die Steuerung über LAN (ab Midea2Lox V2.0) hat mac_zhou mit msmart https://github.com/mac-zhou/midea-msmart großartige leistung geleistet. Danke dafür! (Thanks mac-zhou!)
msmart wird nun weiterentwickelt von mill1000 --> https://github.com/mill1000/midea-msmart

# Installation:
Plugin herunterladen und im Pluginmanager des Loxberry installieren.
Anschließend gewünschten Empfangsport angeben,danach kann über start der Service gestartet werden.

Das Plugin übernimmt die Kommunikation zwischen Midea und Loxberry.Auf dem Loxberry läuft ein UDP Server, der bei Befehlseingang diese an Midea schickt. Der Aktuelle Status wird über die Virtuellen Eingänge des Loxberry direkt ausgegeben/geschalten,
daher müssen die Eingänge in Loxone genau den Wortlaut wie in der Beispielkonfig haben.Die Beispielkonfig für Loxone ist auch hier zu finden.

Weitere Infos sind unter https://www.loxwiki.eu/display/LOXBERRY/Midea2Lox zu finden

Ab Midea2Lox V2.0 findet die kommunikation direkt über LAN ohne Cloud statt. 

# Midea 8370 Protocol / V3, bspw. EU-OSK103
Ab Midea2Lox V3.0 werden die neueren Sticks mit Protokoll Version 3 über LAN unterstützt.

## Aufgeräumt und dabei repariert

### Der Dienst liess sich weder erkennen noch beenden

`daemon/daemon` prüfte mit `ps -C "midea2lox.py"` und beendete mit
`killall midea2lox.py`. Beides sucht nach dem **Prozessnamen** — und der ist bei
einem Skript mit Shebang-Zeile der *Interpreter*, hier also `python3`, nicht der
Dateiname. Live nachgemessen: `ps -C midea2lox.py` liefert keinen Treffer,
`killall midea2lox.py` meldet „no process found", während der Prozess
weiterläuft. `killall -r midea2lox` übrigens auch nicht — `killall` vergleicht
den Prozessnamen, nicht die Kommandozeile.

Die Folgen:

- `status` meldete **immer** „Midea2Lox is stopped", auch im laufenden Betrieb,
- `start` startete deshalb jedes Mal eine **weitere** Ausfertigung,
- `stop` beendete **gar nichts**.

Zwei parallel laufende Dienste melden sich beide beim selben MQTT-Broker an und
schreiben abwechselnd in dieselben Themen.

Dieselbe untaugliche Erkennung stand an drei weiteren Stellen: in
`mi_lib.php` (`mi_dienst_pid()` — die Oberfläche zeigte den Dienst dauerhaft als
gestoppt), in `preupgrade.sh` und in `uninstall/uninstall`. Alle vier arbeiten
jetzt über eine PID-Datei (`data/plugins/<Ordner>/dienst.pid`), die das
Startskript anlegt, mit argumentweiser Gegenprobe über `/proc/<pid>/cmdline` —
nicht als Teilzeichenkette, denn eine fremde Kommandozeile kann den Namen
zufällig enthalten.

### Sprachdateien: gebaut, aber nie angeschlossen

`mi_t()` und `mi_sprache()` waren vorhanden, `templates/lang/language_de.ini`
und `language_en.ini` ebenfalls — **keiner der 25 Schlüssel wurde benutzt**. Die
Oberfläche trug ihre Texte fest im Quelltext; auf Englisch erschien alles
deutsch.

Dreizehn Schlüssel sind jetzt angeschlossen: die fünf Reiterbeschriftungen,
sechs Überschriften und der Dienstzustand. Die übrigen zwölf haben in der
Oberfläche noch keine eindeutige Stelle — sie bleiben stehen, statt geraten zu
werden. Ein falsch platzierter Text ist schlimmer als ein ungenutzter Schlüssel.
Ein Kopfkommentar in beiden Dateien hält das fest.

### Weiteres

- **`icons/icon.svg`** ergänzt — es lagen nur die vier PNG vor. Nach
  Hausstandard: runde Scheibe in LoxBerry-Grün, weißes Motiv (Innengerät mit
  drei Luftströmen), keine fremde Wortmarke.
- **Sieben tote Vorlagenzuweisungen** aus `postinstall.sh`, `preupgrade.sh` und
  `postupgrade.sh` entfernt (`PTEMPDIR`, `PSHNAME`, `PVERSION`, `ARGV0`,
  `ARGV2`, `ARGV4`).
- **`.gitignore`** ergänzt — sie schließt vor allem `venv/` aus: die virtuelle
  Python-Umgebung entsteht bei der Installation und belegt 60 bis 100 MB.

### `config/mqtt_subscriptions.cfg` — sie wirkt, und wie sie es tut

Die Datei enthält eine einzige Zeile (`Midea2Lox/#`, ohne Zeilenumbruch am
Ende). **Das MQTT-Gateway liest sie und abonniert daraus.**

> **Berichtigung, 13.09.2026.** In 4.3.2 bis 4.5.3 stand hier das Gegenteil:
> „am Gerät gemessen … trifft nicht zu". Diese Messung sah in
> `config/system/subscriptions.json` nach — und **dort landen Plugin-Abos
> nie**, das Gateway hält sie ausschließlich im Arbeitsspeicher. Ein Blick an
> die falsche Stelle, und „nicht gefunden" wurde als „wird nicht gelesen"
> gelesen. Wer es nachmisst, findet dasselbe wie wir.

Belegt im Quelltext des MQTT-Gateways (`sbin/mqttgateway.pl`), als
durchgehende Kette:
`get_plugins()` über alle installierten Plugins → `watch` auf
`config/plugins/<Ordner>/mqtt_subscriptions.cfg` → `read_file` →
`push @subscriptions` → `$mqtt->subscribe`. Und am laufenden Gateway
nachgerechnet: 52 Abos der Oberfläche plus die eine Zeile aus unserer Datei
ergaben `Uniquify subscriptions: Before 53 / Afterwards 52` — eine Dopplung,
weil das Thema hier auch von Hand eingetragen war.

Das Plugin schreibt die Datei beim Ändern des Themen-Präfix mit, damit sie
nicht auf einen Zweig zeigt, in den niemand mehr schreibt. **Der Installateur
liest sie nicht** — `sbin/plugininstall.pl` enthält den Namen kein einziges
Mal; er kopiert die Datei nur. Das Einlesen macht allein das Gateway, und es
tut es im laufenden Betrieb, sobald sich die Datei ändert.

Ob es bei Ihnen trägt, sagt der Reiter Test in der Zeile *Ist unser Thema im
Gateway abonniert?*

Deshalb steht in der Datei auch **kein** erklärender Kommentar: `#` ist im
MQTT-Thema der Platzhalter für „alles darunter". Eine Zeile, die mit `#`
beginnt, wäre kein Kommentar, sondern ein Abonnement auf sämtliche Themen des
Brokers.

