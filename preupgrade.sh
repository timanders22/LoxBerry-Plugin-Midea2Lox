#!/bin/bash

ARGV1=$1 # First argument is temp folder during install
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

# Der Sicherungsordner liegt unter data/, NICHT unter /tmp.
#
# /tmp ist auf dem LoxBerry eine Ramdisk: bricht die Installation ab oder
# startet der Rechner dazwischen neu, ist die Sicherung weg. Und /tmp ist fuer
# jeden lesbar - in der Konfiguration stehen die Zugangsdaten des Midea-Kontos.
# Geaendert am 10.08.2026 nach der Durchsicht aller Plugins.
# Die Sicherung liegt NEBEN dem Ordner, nicht darin. Gemessen an
# sbin/plugininstall.pl (Zweig master, 23.08.2026): der Installer ruft
# &purge_installation nicht nur beim Deinstallieren, sondern auch im
# Upgrade-Zweig (:886), und deren Rumpf loescht ohne jede Bedingung
# (:1629 ff.) config/plugins/<x>/, bin/plugins/<x>/, data/plugins/<x>/,
# templates/plugins/<x>/ und beide webfrontend/-Ordner. Eine Sicherung IN
# data/plugins/<x>/ wird also von genau dem Schritt vernichtet, den sie
# ueberdauern soll. Der Punkt im Namen ist der ganze Unterschied:
# "rm -rf .../<x>/" trifft den Nachbarn "<x>.upgrade_sicherung" nicht.
SICHER="$ARGV5/data/plugins/$ARGV3.upgrade_sicherung"

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

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zweitschrift NEBEN den Konfigurationsordner, zusaetzlich zur bisherigen
# Sicherung. Grund: der Installer kopiert config/* aus dem Archiv ueber
# config/plugins/<ordner> (plugininstall.pl Zeile 899, cp -r ohne -n) und
# ueberschreibt dabei die Datei des Nutzers. Bisher haing die Rettung allein
# an postupgrade.sh. Laeuft das aus irgendeinem Grund nicht durch, greift
# jetzt postinstall.sh auf diese Zweitschrift zu - sie liegt ausserhalb des
# ueberschriebenen Ordners und wird vom Installer nicht angefasst.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-Midea2Lox}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
if [ -s "$NETZ_CFG/devices.cfg" ]; then
    cp -p "$NETZ_CFG/devices.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.devices.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.devices.cfg" 2>/dev/null
fi
if [ -s "$NETZ_CFG/midea2lox.cfg" ]; then
    cp -p "$NETZ_CFG/midea2lox.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.midea2lox.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.midea2lox.cfg" 2>/dev/null
fi
if [ -s "$NETZ_CFG/mqtt_subscriptions.cfg" ]; then
    cp -p "$NETZ_CFG/mqtt_subscriptions.cfg" "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.mqtt_subscriptions.cfg" 2>/dev/null \
        && chmod 0600 "$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.mqtt_subscriptions.cfg" 2>/dev/null
fi
echo "<INFO> Zweitschrift der Einstellungen angelegt."

exit 0
