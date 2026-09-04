# Midea2Lox

Integration von Klimaanlagen der Midea-Gruppe in Loxone — als LoxBerry-Plugin.

> **Fork-Hinweis**
> Dieses Repositorium führt die Arbeit von **Harald Friedl** (`seppe912`) fort.
> Er hat die Weiterentwicklung in [Issue #15](https://github.com/seppe912/Midea2Lox/issues/15)
> ausdrücklich freigegeben, weil er selbst keinen LoxBerry mehr betreibt.
> Sein Code ist die Grundlage, die Lizenz (Apache 2.0) bleibt unverändert.
> Was sich gegenüber 3.4.8 geändert hat, steht in den Release-Beschreibungen
> ab 4.0.0.

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

### `config/mqtt_subscriptions.cfg` — was sie tut und was nicht

Die Datei enthält eine einzige Zeile (`Midea2Lox/#`, ohne Zeilenumbruch am
Ende). Bis 4.3.2 stand hier, das MQTT-Gateway lese sie aus und abonniere die
darin genannten Themen. **Das ist am Gerät gemessen worden und trifft nicht
zu:** zwei Probedateien, eine davon in einem wirklich installierten Plugin,
sind nach einem Neustart des Gateways in keinem Abonnement gelandet.

Was die Datei damit noch ist: eine Dokumentation dessen, was einzutragen
wäre, und möglicherweise die Vorlage, die der Installateur einmalig einliest
— das ist die eine Hälfte der Frage, die noch offen ist. Das Plugin schreibt
sie beim Ändern des Themen-Präfix mit, damit sie nicht auf einen Zweig zeigt,
in den niemand mehr schreibt.

**Verlassen Sie sich nicht darauf.** Ob Ihr Thema wirklich abonniert ist,
beantwortet der Reiter Test in der Zeile *Ist unser Thema im Gateway
abonniert?* — die liest `config/system/subscriptions.json` und sagt es Ihnen
schwarz auf weiß. Steht dort ein Kreuz, tragen Sie das Thema im Reiter MQTT
von Hand ein.

Deshalb steht in der Datei auch **kein** erklärender Kommentar: `#` ist im
MQTT-Thema der Platzhalter für „alles darunter". Eine Zeile, die mit `#`
beginnt, wäre kein Kommentar, sondern ein Abonnement auf sämtliche Themen des
Brokers.

