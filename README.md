# Midea2Lox

Integration von Klimaanlagen der Midea-Gruppe in Loxone — als LoxBerry-Plugin.

> **Fork-Hinweis**
> Dieses Repositorium führt die Arbeit von **Harald Friedl** (`seppe912`) fort.
> Er hat die Weiterentwicklung in [Issue #15](https://github.com/seppe912/Midea2Lox/issues/15)
> ausdrücklich freigegeben, weil er selbst keinen LoxBerry mehr betreibt.
> Sein Code ist die Grundlage, die Lizenz (Apache 2.0) bleibt unverändert.
> Was sich gegenüber 3.4.8 geändert hat, steht in `AENDERUNGEN_4.0.0.md`.

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
- MQTT-Gateway-Plugin *(freiwillig — ohne läuft alles über UDP)*

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
