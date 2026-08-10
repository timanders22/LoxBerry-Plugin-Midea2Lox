#!/bin/bash

ARGV1=$1 # First argument is temp folder during install
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar - in der Konfiguration stehen die Zugangsdaten des Midea-Kontos.
# Geaendert am 10.08.2026 nach der Durchsicht aller Plugins.
SICHER="$ARGV5/data/plugins/$ARGV3/upgrade_sicherung"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$SICHER" 2>/dev/null
mkdir -p "$SICHER/config"
chmod 0700 "$SICHER" 2>/dev/null

echo "<INFO> Backing up existing config files"
cp -a "$ARGV5/config/plugins/$ARGV3/." "$SICHER/config/" 2>/dev/null \
    && echo "<OK> Konfiguration gesichert (Rechte 0700)."

echo "<INFO> stoppe Midea2Lox"
# Ueber das Startskript beenden, nicht ueber killall.
#
# "killall midea2lox.py" hat nie etwas beendet: killall sucht nach dem
# PROZESSNAMEN, und der ist bei einem Skript mit Shebang-Zeile der
# Interpreter (python3), nicht der Dateiname. Nachgemessen meldet es
# "no process found", waehrend der Dienst weiterlaeuft - das Update lief
# also mit einem noch laufenden Dienst weiter.
if [ -x "$ARGV5/system/daemons/plugins/$ARGV3" ]; then
    "$ARGV5/system/daemons/plugins/$ARGV3" stop
else
    # Rueckfallebene: PID-Datei unmittelbar auswerten.
    PIDDATEI="$ARGV5/data/plugins/$ARGV3/dienst.pid"
    if [ -f "$PIDDATEI" ]; then
        PID=$(cat "$PIDDATEI" 2>/dev/null)
        case "$PID" in
            ''|*[!0-9]*) PID="" ;;
        esac
        if [ -n "$PID" ] && tr '\0' '\n' < "/proc/$PID/cmdline" 2>/dev/null \
             | sed 's#.*/##' | grep -qx "midea2lox.py"; then
            kill "$PID" 2>/dev/null
            sleep 2
            kill -9 "$PID" 2>/dev/null
        fi
        rm -f "$PIDDATEI"
    fi
fi

# Exit with Status 0
exit 0
