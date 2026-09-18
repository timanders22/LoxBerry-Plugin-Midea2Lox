#!/bin/bash

ARGV1=$1 # First argument is temp folder during install
ARGV3=$3 # Third argument is Plugin installation folder
ARGV5=$5 # Fifth argument is Base folder of LoxBerry
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

# ===================================================================
# DIE MARKE - ALS ALLERERSTES
# ===================================================================
#
# Zwischen diesem Skript und postupgrade.sh liegt die Upgradeluecke: der
# Installer raeumt mit &purge_installation den Datenordner ab, spielt die
# neuen Dateien ein und laeuft die Hakenskripte durch. Wer in dieser Zeit
# startet - ein Systemstart, ein Aufruf des Startskripts, der Minutentakt -
# faehrt den Dienst mit der Vorgabekonfiguration hoch, und beim naechsten
# Schritt loescht der Installer die Dateien unter ihm weg. Gemessen am
# 17.09.2026 (Pruefung-Upgradeluecke-2026-09-17): zwei Dienste, der alte
# hielt den UDP-Port, der neue kam nicht hoch.
#
# Deshalb eine Marke mit der Unixzeit, NEBEN dem Datenordner (derselbe
# Grund wie beim Sicherungsordner unten: der Punkt im Namen rettet sie vor
# "rm -rf .../<x>/"). daemon/daemon und damit auch der Minutentakt lesen
# sie; postupgrade.sh entfernt sie nach dem Start wieder. Bauweise nach
# Einspeisebremse 0.9.20, Entscheidung des Hausherrn vom 18.09.2026.
#
# Die Zeile steht VOR allem anderen: eine Marke, die erst nach der
# Sicherung entstuende, liesse genau das Zeitfenster offen, das sie
# schliessen soll. Schreibt sie sich nicht, geht die Installation trotzdem
# weiter - eine Aktualisierung an einer nicht schreibbaren Marke scheitern
# zu lassen waere der groessere Schaden.
#
# Die Uhr wird gemessen, nicht angenommen: eine Marke mit leerem Inhalt
# gilt nirgends (dort steht dann 0, und 0 ist aelter als eine Stunde). Ein
# blindes "date +%s > MARKE" haette die Datei auch dann angelegt, wenn der
# fork scheitert - eine Marke, die nicht wirkt.
MARKE="$ARGV5/data/plugins/$ARGV3.upgrade_laeuft"
mkdir -p "$ARGV5/data/plugins" 2>/dev/null
MARKE_ZEIT=$(date +%s 2>/dev/null)
case "$MARKE_ZEIT" in
    ''|*[!0-9]*) MARKE_ZEIT="" ;;
esac
if [ -n "$MARKE_ZEIT" ] && printf '%s\n' "$MARKE_ZEIT" > "$MARKE" 2>/dev/null; then
    chmod 0644 "$MARKE" 2>/dev/null
    echo "<INFO> Marke fuer die laufende Aktualisierung gesetzt ($MARKE)."
else
    rm -f "$MARKE" 2>/dev/null
    echo "<WARNING> Die Marke $MARKE liess sich nicht setzen."
    echo "<WARNING> Startet der Rechner waehrend der Aktualisierung neu, kann"
    echo "<WARNING> der Dienst mit den Vorgabewerten anlaufen."
fi

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
    #
    # Die Erkennung ist dieselbe wie in daemon/daemon (eigener_dienst):
    # argv[0] ein python-Interpreter, argv[1] gegen den Arbeitsordner des
    # Prozesses aufgeloest genau das eigene Programm. Bis 4.5.5 reichte der
    # DATEINAME irgendeines Arguments - gemessen 17.09.2026 in WSL kam damit
    # ein "tail -f ./midea2lox.py" im Datenordner durch und wurde beendet.
    #
    # Und "2>/dev/null" stand HINTER der Eingabeumleitung. Die Schale fuehrt
    # Umleitungen von links nach rechts aus: scheitert das Oeffnen von
    # /proc/<pid>/cmdline, ist der Fehlerkanal noch nicht umgelenkt, und die
    # Zeile "/proc/<pid>/cmdline: No such file or directory" steht im
    # Installationsprotokoll. Gemessen 17.09.2026 in WSL, beide Formen
    # nebeneinander.
    PIDDATEI="$ARGV5/data/plugins/$ARGV3/dienst.pid"
    PROGRAMM="$ARGV5/data/plugins/$ARGV3/midea2lox.py"
    unser_prozess() {  # $1 PID
        local a0 a1 wd ziel
        { IFS= read -r -d '' a0 && IFS= read -r -d '' a1; } 2>/dev/null < "/proc/$1/cmdline" || return 1
        case "${a0##*/}" in
            python|python3|python3.[0-9]|python3.[0-9][0-9]) ;;
            *) return 1 ;;
        esac
        [ "${a1##*/}" = "midea2lox.py" ] || return 1
        case "$a1" in
            /*) ziel=$a1 ;;
            *)  wd=$(readlink "/proc/$1/cwd" 2>/dev/null) || return 1
                ziel="${wd% (deleted)}/$a1" ;;
        esac
        ziel=${ziel//\/.\//\/}
        [ "$ziel" = "$PROGRAMM" ]
    }
    if [ -f "$PIDDATEI" ]; then
        PID=$(cat "$PIDDATEI" 2>/dev/null)
        case "$PID" in
            ''|*[!0-9]*) PID="" ;;
        esac
        if [ -n "$PID" ] && unser_prozess "$PID"; then
            kill "$PID" 2>/dev/null
            WARTE=0
            while [ $WARTE -lt 20 ] && kill -0 "$PID" 2>/dev/null; do
                sleep 0.25
                WARTE=$((WARTE + 1))
            done
            # Vor dem KILL erneut nachsehen: nach fuenf Sekunden kann die
            # Nummer schon einem anderen Programm gehoeren, und ein kill -9
            # an den Falschen ist nicht rueckgaengig zu machen. Bis 4.5.5
            # stand hier "sleep 2; kill -9" ohne jede weitere Pruefung.
            if unser_prozess "$PID"; then
                kill -9 "$PID" 2>/dev/null
            fi
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
