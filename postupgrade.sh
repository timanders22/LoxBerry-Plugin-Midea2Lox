#!/bin/bash

# Wird nach einer Aktualisierung ausgefuehrt (nach postinstall).
# Holt die in preupgrade.sh gesicherten Konfigurationsdateien zurueck
# und startet den Dienst.

PTEMPDIR=$1
PSHNAME=$2
PDIR=$3
PVERSION=$4
LBHOME=$5

echo "<INFO> Stelle die gesicherten Konfigurationsdateien wieder her"
if [ -d "/tmp/${PTEMPDIR}_upgrade/config/$PDIR" ]; then
	cp -p -v -r "/tmp/${PTEMPDIR}_upgrade/config/$PDIR/." "$LBHOME/config/plugins/$PDIR/"
else
	echo "<WARNING> Keine Sicherung gefunden - es gelten die Vorgabewerte."
fi

echo "<INFO> Entferne die temporaeren Ordner"
rm -rf "/tmp/${PTEMPDIR}_upgrade"

# Der Dienst wird ueber das Startskript gestartet, nicht direkt. Bis 3.4.8
# stand hier ein "./midea2lox.py &" - das umging das Startskript und lief
# damit ohne dessen Pruefungen.
echo "<INFO> Starte Midea2Lox"
"$LBHOME/system/daemons/plugins/$PDIR" restart >/dev/null 2>&1

exit 0
