# Midea2Lox 4.0.0 — Änderungen gegenüber 3.4.8

Dies ist ein **Fork** von [seppe912/Midea2Lox](https://github.com/seppe912/Midea2Lox).
Der ursprüngliche Autor **Harald Friedl** hat die Weiterentwicklung in
[Issue #15](https://github.com/seppe912/Midea2Lox/issues/15) ausdrücklich
freigegeben, weil er selbst keinen LoxBerry mehr betreibt:

> „Leider werde ich das Plugin nicht mehr updaten/anpassen da ich keinen
> Loxberry mehr habe. […] Gerne darf das aber jemand weiter entwickeln der will."

Sein Code bleibt die Grundlage, die Lizenz (Apache 2.0) unverändert.

---

## 1. Zwei echte Fehler behoben

**`support_msmart_ng['ac.fan_speed.auto']` löste einen `KeyError` aus.** Diesen
Schlüssel gibt es in der Tabelle nicht — sie kennt nur
`'ac.fan_speed_enum.Auto'`. Die Zeile steht in der Fehlerbehandlung, die nach
**jedem** Befehl durchlaufen wird.

Dieselbe Zeile verglich außerdem `device.operational_mode.name` (also `'AUTO'`)
mit dem Tabellenwert `'ac.OperationalMode.AUTO'` — das konnte nie gleich sein.
Beide Bedingungen vergleichen jetzt die Aufzählungswerte direkt:

    if device.operational_mode == ac.OperationalMode.AUTO and device.fan_speed != ac.FanSpeed.AUTO:

**`'ac.FanSpeed.FULL'`** stand in der Umsetzungstabelle, existiert in msmart-ng
aber nicht — weder in 2025.5.1 noch heute. Eine Loxone-Konfiguration mit
`fan_speed_enum.Full` lief damit in einen `AttributeError`. Richtig ist `MAX`.

## 2. msmart-ng von 2025.5.1 auf 2026.7.0 angehoben

Der ursprüngliche Autor hatte die Fassung bewusst festgenagelt, weil msmart
schon Bezeichnungen geändert hat. Ich habe deshalb **beide Fassungen installiert
und die Schnittstelle verglichen**, statt zu hoffen:

| | 2025.5.1 | 2026.7.0 |
|---|---|---|
| öffentliche Attribute | 91 | 114 |
| davon entfernt | — | **keine** |
| Enums `OperationalMode`, `FanSpeed`, `SwingMode`, `SwingAngle`, `BreezeMode`, `RateSelect` | | **alle unverändert** |

Es wurden 23 Attribute **hinzugefügt** und **keines entfernt**. Zusätzlich lösen
alle 15 Einträge der Umsetzungstabelle gegen 2026.7.0 auf — geprüft, nicht
vermutet.

msmart-ng verlangt jetzt Python ≥ 3.10. Auf LoxBerry 4 (Debian 12/13 mit
Python 3.11 bzw. 3.13) ist das gegeben.

## 3. `preroot.sh` entrümpelt

Die Funktion `install_python()` baute bei zu altem Python **OpenSSL 1.1.1w** aus
dem Quelltext und schob es mit `sudo make install` ins System — OpenSSL 1.1.1
ist seit dem 11.09.2023 abgekündigt. Dazu ein `sudo apt upgrade` ohne `-y`.

Das ist ersatzlos entfallen. Stattdessen prüft das Skript die Python-Fassung und
**bricht mit einer verständlichen Meldung ab**, wenn sie nicht reicht.

*Der ursprüngliche Autor hat zu Recht angemerkt, dass ihm damals eine Lösung für
alte Systeme fehlte. Die Antwort lautet heute: ein Plugin darf das System-Python
nicht austauschen — der Weg führt über ein aktuelles LoxBerry-Abbild.*

## 4. `postinstall.sh` prüft jetzt jeden Schritt

Bisher konnte das Anlegen der virtuellen Umgebung fehlschlagen, `source
…/activate` ins Leere laufen, die `pip3`-Aufrufe gegen das System-Python gehen
(und dort an PEP 668 scheitern) — und das Skript endete trotzdem mit `exit 0`.
Die Installation meldete Erfolg, das Plugin war tot.

Jetzt wird das Anlegen der Umgebung geprüft, jede Modulinstallation einzeln, und
zum Schluss wird gegengeprüft, ob sich `msmart` und `paho.mqtt` wirklich **laden**
lassen. Jeder Fehlschlag endet mit `exit 2` und einer Meldung, die sagt, was zu
tun ist.

## 5. Oberfläche auf den Hausstandard umgebaut

Statt zweier fester Sprachvorlagen (`templates/de|en/content.html`) und
handgebauter Kopf- und Fußzeilen läuft die Seite jetzt über `HTML::Template` mit
einer `settings.html` und fünf Reitern:

| Reiter | Inhalt |
|---|---|
| **Einstellungen** | Dienststeuerung, Miniserver, UDP-Port, Midea-Konto, Region, Lebensdauer der Verbindung, Protokollstufe, Geräteliste |
| **MQTT** | Zustand des Gateways, Broker-Daten, das einzutragende Abo, alle veröffentlichten Themen mit Bedeutung |
| **Einbindung in Loxone** | Sieben Schritte, inkl. **kompletter Baustein-Liste zum 1:1-Nachbauen** |
| **Test** | Selbstprüfung in sieben Punkten, Gerätesuche, Zustandsabfrage |
| **Logdateien** | Logliste des LoxBerry |

Die Selbstprüfung beantwortet genau die Fragen, die sonst im Log gesucht werden:
Gibt es die virtuelle Umgebung? Lässt sich msmart laden, und in welcher Fassung?
Läuft der Dienst? Sind Zugangsdaten und Geräte hinterlegt? Ist ein MQTT-Gateway
da?

## 6. Kleineres

- **`uninstall`** entfernt die virtuelle Umgebung. *(Der ursprüngliche Autor
  merkte an, dass LoxBerry den bin-Ordner ohnehin löscht — das deckt sich mit
  meiner Beobachtung. Es schadet aber nicht, und auf Systemen, wo etwas
  liegenbleibt, ist es damit erledigt.)*
- **`postupgrade.sh`** startet den Dienst über das Startskript statt
  `./midea2lox.py &` direkt — so greifen dessen Prüfungen.
- **`release.cfg`** nennt jetzt dieselbe Fassung wie die `plugin.cfg`. In 3.4.8
  stand dort noch 3.4.7.
- **`prerelease.cfg`** zeigt nicht mehr auf ein Archiv, das es nicht gibt.
- **`dpkg/apt`**: `python3-pip` entfernt (die virtuelle Umgebung bringt ihr
  eigenes pip mit), `python3-venv` bleibt.

---

## Was **nicht** geprüft werden konnte

**Ein Lauf an einer echten Klimaanlage.** Alles oben ist am Quelltext geprüft,
per `py_compile` übersetzt und gegen die tatsächlich installierten
msmart-Fassungen abgeglichen — aber es hat kein Gerät geantwortet.

Vor dem ersten Einsatz deshalb: Plugin installieren, im Reiter **Test** die
Selbstprüfung ansehen, dann **Geräte suchen** lassen. Erst wenn dort IDs
erscheinen, lohnt der Weg nach Loxone.
