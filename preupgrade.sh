#!/bin/bash

ARGV1=$1 # First argument is temp folder during install
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry

echo "<INFO> Creating temporary folders for upgrading"
mkdir -p /tmp/$ARGV1\_upgrade
mkdir -p /tmp/$ARGV1\_upgrade/config
#mkdir -p /tmp/$ARGV1\_upgrade/log
#mkdir -p /tmp/$ARGV1\_upgrade/files

echo "<INFO> Backing up existing config files"
cp -p -v -r $ARGV5/config/plugins/$ARGV3/ /tmp/$ARGV1\_upgrade/config

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
