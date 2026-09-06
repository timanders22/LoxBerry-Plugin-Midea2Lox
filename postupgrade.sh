#!/bin/bash

# Wird nach einer Aktualisierung ausgefuehrt (nach postinstall).
# Holt die in preupgrade.sh gesicherten Konfigurationsdateien zurueck
# und startet den Dienst.

PTEMPDIR=$1
PDIR=$3
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBHOME=$5

# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
#
# Nebenbei behoben: der alte Pfad trug ein zusaetzliches /$PDIR am Ende, weil
# 'cp -r quelle/ ziel' das Quellverzeichnis MIT anlegt. preupgrade sichert
# jetzt mit 'cp -a quelle/. ziel/' den Inhalt - ohne die Zwischenebene.
SICHER="$LBHOME/data/plugins/$PDIR.upgrade_sicherung"

echo "<INFO> Stelle die gesicherten Konfigurationsdateien wieder her"
if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
	mkdir -p "$LBHOME/config/plugins/$PDIR" 2>/dev/null
	cp -a "$SICHER/config/." "$LBHOME/config/plugins/$PDIR/" 2>/dev/null \
	    && echo "<OK> Konfiguration zurueckgestellt."
else
	# Kein blinder Alarm: der Installer loescht beim Update AUCH
	# data/plugins/<ordner> und damit die Sicherung, die preupgrade.sh
	# dorthin geschrieben hat - diese Kette kann hier gar nichts finden.
	# Gerettet wird aus der Zweitschrift neben dem Ordner, und das tut
	# postinstall.sh, das VOR postupgrade laeuft. Also erst nachsehen,
	# wie es wirklich steht; eine Warnung bei heiler Konfiguration
	# erschreckt ohne Grund und entwertet die echte.
	NETZ_PRUEF="${5:-$LBHOMEDIR}/config/plugins/${3:-Midea2Lox}/midea2lox.cfg"
	if [ -s "$NETZ_PRUEF" ]; then
	    echo "<OK> Die Einstellungen sind vorhanden (aus der Zweitschrift)."
	else
	echo "<WARNING> Keine Sicherung gefunden - es gelten die Vorgabewerte."
	fi
fi

echo "<INFO> Entferne den Sicherungsordner"
rm -rf "$SICHER"

# Der Dienst wird ueber das Startskript gestartet, nicht direkt. Bis 3.4.8
# stand hier ein "./midea2lox.py &" - das umging das Startskript und lief
# damit ohne dessen Pruefungen.
echo "<INFO> Starte Midea2Lox"
"$LBHOME/system/daemons/plugins/$PDIR" restart >/dev/null 2>&1

exit 0
