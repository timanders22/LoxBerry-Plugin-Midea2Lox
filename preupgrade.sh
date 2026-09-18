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

# ---- MI-INHALTSBLOCK ANFANG (wortgleich in preupgrade.sh, postinstall.sh, postupgrade.sh) ----
# Traegt eine Konfigurationsdatei etwas EIGENES des Anwenders? Entschieden
# wird nach dem INHALT, nicht nach der Groesse. Bis 4.5.7 stand an diesen
# Stellen "[ -s datei ]": eine abgeschnittene Datei ist nicht leer, und die
# mitgelieferte Vorgabe auch nicht. Beide verdraengten die heile
# Zweitschrift (Bestand-2026-09-18/klasse-C; nachgemessen 18.09.2026 in WSL,
# Pruefung-Midea2Lox-4.5.8, Faelle C1-C3 und D3).
#
# "Eigenes" heisst: die Datei ist vollstaendig geschrieben (jeder Schreiber
# dieser Linie - mi_config_write(), mi_devices_write(), discover.py - endet
# mit einem Zeilenumbruch), sie hat den Aufbau, den der Dienst liest, und
# sie ist NICHT zeichengenau eine mitgelieferte Vorgabe. Die Pruefsummen
# sind die der Vorgaben dieser und der frueheren Fassungen (die Liste stand
# bis 4.5.7 nur in postinstall.sh).
# Grenze: eine Datei, die genau an einem Zeilenende abgeschnitten wurde,
# erkennt die Pruefung nur, wenn dabei einer der Pflichtschluessel fehlt.
MI_VORGABE_DEVICES="db6bd81a12e08bbc0182a54b6af10e28ef6ab47b75e79ed968cad04003cf88c7"
MI_VORGABE_MIDEA="fb75336280d50f2c24f0c86a15ce7b9f8096ac11aa4db9d71a25275287fa93e9 1ebad3fa1da1408accd52c3a680c8d7de799c3da50aefb4993fd4dca11475460 cfcdf81105a935a872636e4400cdcd97f9f907f7d4b460f8ee92009292dbe0f5"
MI_VORGABE_ABO="a5cc6d64cc2ad24c25a747efd2105a1be007b8111ef84fea241d2c08d32e30de"
mi_vorgabe() {  # $1 Dateiname, $2 Pfad -> 0, wenn die Datei eine mitgelieferte Vorgabe ist
    local ist soll liste
    case "$1" in
        devices.cfg)            liste=$MI_VORGABE_DEVICES ;;
        midea2lox.cfg)          liste=$MI_VORGABE_MIDEA ;;
        mqtt_subscriptions.cfg) liste=$MI_VORGABE_ABO ;;
        *) return 1 ;;
    esac
    [ -f "$2" ] || return 1
    ist=$(sha256sum "$2" 2>/dev/null | cut -d" " -f1)
    [ -n "$ist" ] || return 1
    for soll in $liste; do
        [ "$ist" = "$soll" ] && return 0
    done
    return 1
}
mi_inhalt() {  # $1 Dateiname, $2 Pfad -> 0, wenn die Datei Eigenes des Anwenders traegt
    local k
    [ -f "$2" ] && [ -r "$2" ] && [ -s "$2" ] || return 1
    mi_vorgabe "$1" "$2" && return 1
    case "$1" in
        midea2lox.cfg)
            # Endet mit Zeilenumbruch, Abschnitt [default], und die Schluessel,
            # die schon die Vorgabe von 4.3.x trug.
            [ -z "$(tail -c 1 "$2")" ] || return 1
            grep -q '^[[:space:]]*\[default\][[:space:]]*$' "$2" || return 1
            for k in MINISERVER UDP_PORT DEBUG LoxberryIP maxConnectionLifetime region \
                     MideaUser MideaPassword mqtt_praefix abfragetakt lox_timeout; do
                grep -q "^[[:space:]]*$k[[:space:]]*=" "$2" || return 1
            done ;;
        devices.cfg)
            # Endet mit Zeilenumbruch, mindestens ein Abschnitt, jede Zeile ist
            # leer, Kommentar, Abschnitt oder "schluessel = wert".
            [ -z "$(tail -c 1 "$2")" ] || return 1
            grep -q '^[[:space:]]*\[[^]]*\][[:space:]]*$' "$2" || return 1
            if grep -v -e '^[[:space:]]*$' -e '^[[:space:]]*[#;]' \
                    -e '^[[:space:]]*\[[^]]*\][[:space:]]*$' -e '=' "$2" | grep -q .; then
                return 1
            fi ;;
        mqtt_subscriptions.cfg)
            # Je Zeile ein Thema, das auf "/#" endet (mi_abo_datei_schreiben()
            # schreibt "<praefix>/#", ohne Zeilenumbruch).
            grep -q '/#' "$2" || return 1
            if grep -v '^[[:space:]]*$' "$2" | grep -qv '^[^[:space:]#+][^[:space:]#+]*/#$'; then
                return 1
            fi ;;
        *) return 1 ;;
    esac
    return 0
}
# ---- MI-INHALTSBLOCK ENDE ----

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

# Die neue Sicherung entsteht NEBEN der alten und ersetzt sie erst, wenn sie
# vollstaendig steht. Bis 4.5.7 stand hier "rm -rf $SICHER" VOR dem
# Kopieren: brach ein Update nach purge_installation ab und wurde erneut
# angestossen, gab es nichts mehr zu sichern, und die einzige Abschrift war
# schon geloescht (Bestand-2026-09-18/klasse-D: 11 von 12 Dateien; in WSL
# nachgemessen 18.09.2026, Pruefung-Midea2Lox-4.5.8, Fall D1: 4 von 7,
# Fall D2: Abbruch beim Schreiben). Reihenfolge wie GardenaSmartSystem
# 1.2.10 und Chromecast4lox 1.3.11: in $SICHER.neu bauen -> jede Datei
# byteweise pruefen -> die alte nach $SICHER.alt -> die neue an ihren Platz
# -> die alte wegwerfen. "mv -T" schiebt nie IN ein vorhandenes Verzeichnis.
mi_abweichend() {  # $1 Quellordner, $2 Kopie -> Dateien, die in der Kopie fehlen oder abweichen
    [ -d "$1" ] || return 0
    ( cd "$1" && find . -type f | while IFS= read -r mi_f; do
          cmp -s "$mi_f" "$2/$mi_f" || printf '%s ' "${mi_f#./}"
      done ) 2>/dev/null || echo "(nicht lesbar: $1)"
}
mi_sicherung_traegt() {  # $1 Konfigordner einer Sicherung -> 0, wenn dort Eigenes liegt
    mi_inhalt midea2lox.cfg "$1/midea2lox.cfg" || mi_inhalt devices.cfg "$1/devices.cfg"
}
MI_NEU="$SICHER.neu"
MI_QCFG="$ARGV5/config/plugins/$ARGV3"

echo "<INFO> Creating backup folder for upgrading $SICHER"
rm -rf "$MI_NEU" 2>/dev/null
mkdir -p "$MI_NEU/config" 2>/dev/null
chmod 0700 "$MI_NEU" 2>/dev/null
MI_OK=1
MI_GRUND=""

echo "<INFO> Backing up existing config files"
# Ohne Konfigordner gibt es nichts, was eine vorhandene Sicherung ersetzen
# duerfte: so sieht der zweite Versuch nach einem abgebrochenen Update aus
# (purge_installation hat den Ordner schon entfernt, Fall D1).
if [ -d "$MI_QCFG" ]; then
    cp -a "$MI_QCFG/." "$MI_NEU/config/" 2>/dev/null \
        || { MI_RC=$?; MI_OK=0; MI_GRUND="$MI_GRUND cp Rueckgabewert $MI_RC;"; }
else
    MI_OK=0
    MI_GRUND="$MI_GRUND keine Konfiguration unter $MI_QCFG;"
fi
MI_ABW=$(mi_abweichend "$MI_QCFG" "$MI_NEU/config")
[ -z "$MI_ABW" ] || { MI_OK=0; MI_GRUND="$MI_GRUND nicht in der Sicherung: $MI_ABW;"; }

# Eine Sicherung mit eigenen Einstellungen wird nie durch eine ohne ersetzt.
# Nach purge_installation kopiert der Installer die mitgelieferte Vorgabe
# nach config/plugins/<ordner>/; bricht er danach ab, ist ihre Kopie
# vollstaendig und heil - und traegt nichts mehr vom Anwender (Fall D3).
if [ "$MI_OK" = 1 ] && ! mi_sicherung_traegt "$MI_NEU/config" \
   && mi_sicherung_traegt "$SICHER/config"; then
    MI_OK=0
    MI_GRUND="$MI_GRUND die Einstellungen tragen nichts Eigenes, die vorhandene Sicherung schon;"
fi

if [ "$MI_OK" = 1 ]; then
    rm -rf "$SICHER.alt" 2>/dev/null
    if [ -e "$SICHER" ] && ! mv -T "$SICHER" "$SICHER.alt" 2>/dev/null; then
        rm -rf "$MI_NEU" 2>/dev/null
        echo "<WARNING> Die bisherige Sicherung liess sich nicht beiseitelegen; sie bleibt"
        echo "<WARNING> unangetastet: $SICHER"
    elif mv -T "$MI_NEU" "$SICHER" 2>/dev/null; then
        rm -rf "$SICHER.alt" 2>/dev/null
        echo "<OK> Konfiguration gesichert (Rechte 0700)."
    else
        [ -e "$SICHER.alt" ] && mv -T "$SICHER.alt" "$SICHER" 2>/dev/null
        rm -rf "$MI_NEU" 2>/dev/null
        echo "<WARNING> Die neue Sicherung liess sich nicht an ihren Platz bringen."
        echo "<WARNING> Platz und Rechte in $ARGV5/data/plugins pruefen."
    fi
else
    rm -rf "$MI_NEU" 2>/dev/null
    echo "<WARNING> Die Einstellungen wurden NICHT neu gesichert:$MI_GRUND"
    if [ -d "$SICHER" ]; then
        echo "<WARNING> Die bisherige Sicherung bleibt unangetastet: $SICHER"
    fi
fi

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
# Nach INHALT entscheiden (mi_inhalt oben), nicht nach Groesse. Bis 4.5.7
# stand hier "[ -s ]": eine abgeschnittene Datei und die mitgelieferte
# Vorgabe verdraengten die heile Zweitschrift (Faelle C1-C3, D3).
# Und erst eine Nebendatei, dann umbenennen: "cp -p" direkt auf die
# Zweitschrift kappte sie, bevor die neue stand (Fall D2: nach einem Abbruch
# beim Schreiben blieb eine Zweitschrift mit 0 Byte). Die Meldung
# "Zweitschrift ... angelegt" stand bis 4.5.7 unbedingt am Ende, auch wenn
# nichts angelegt war (Fall D2).
mi_zweitschrift() {  # $1 Dateiname im Konfigordner
    local q="$NETZ_CFG/$1" z="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$1"
    if mi_inhalt "$1" "$q"; then
        if cp -p "$q" "$z.neu" 2>/dev/null \
           && chmod 0600 "$z.neu" 2>/dev/null \
           && cmp -s "$q" "$z.neu" \
           && mv -f "$z.neu" "$z" 2>/dev/null; then
            echo "<INFO> Zweitschrift $1 angelegt."
        else
            rm -f "$z.neu" 2>/dev/null
            echo "<WARNING> Die Zweitschrift von $1 liess sich nicht anlegen;"
            echo "<WARNING> eine vorhandene bleibt unveraendert: $z"
        fi
    elif [ -f "$z" ]; then
        echo "<WARNING> $1 fehlt, ist unvollstaendig oder die mitgelieferte Vorgabe -"
        echo "<WARNING> die vorhandene Zweitschrift bleibt unveraendert: $z"
    else
        echo "<INFO> $1 traegt keine eigenen Einstellungen - keine Zweitschrift angelegt."
    fi
}
mi_zweitschrift devices.cfg
mi_zweitschrift midea2lox.cfg
mi_zweitschrift mqtt_subscriptions.cfg

exit 0
