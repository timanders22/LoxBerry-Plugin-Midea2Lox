# Midea2Lox

Integration von Klimaanlagen der Midea-Gruppe in Loxone — als LoxBerry-Plugin.

> **Fork-Hinweis**
> Dieses Repositorium führt die Arbeit von **Harald Friedl** (`seppe912`) fort.
> Er hat die Weiterentwicklung in [Issue #15](https://github.com/seppe912/Midea2Lox/issues/15)
> ausdrücklich freigegeben, weil er selbst keinen LoxBerry mehr betreibt.
> Sein Code ist die Grundlage, die Lizenz (Apache 2.0) bleibt unverändert.
> Was sich gegenüber 3.4.8 geändert hat, steht in den Release-Beschreibungen
> ab 4.0.0.

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
4. Reiter **Test**: Selbstprüfung ansehen. Erst wenn dort alles grün ist, lohnt
   der Weg nach Loxone.
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

### Beinahe gelöscht: `config/mqtt_subscriptions.cfg`

Die Datei enthält eine einzige Zeile (`Midea2Lox/#`) und wird von **keiner Zeile
Code des Plugins** angesprochen — nach allen bisherigen Maßstäben eine
Altlast. Sie ist aber keine: LoxBerry liest
`config/plugins/<Ordner>/mqtt_subscriptions.cfg` im **MQTT-Gateway** aus und
abonniert die dort genannten Themen. Genau darüber gelangen die Werte in den
Miniserver. Ein Löschen hätte das Plugin stumm gemacht, ohne dass irgendwo ein
Fehler erschienen wäre.

Deshalb steht in der Datei auch **kein** erklärender Kommentar: `#` ist im
MQTT-Thema der Platzhalter für „alles darunter". Eine Zeile, die mit `#`
beginnt, wäre kein Kommentar, sondern ein Abonnement auf sämtliche Themen des
Brokers.

