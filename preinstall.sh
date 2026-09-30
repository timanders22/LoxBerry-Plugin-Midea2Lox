#!/bin/bash
# Midea2Lox - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Neu in 4.5.10 (I1, Entscheidung 1 vom 29.09.2026), Bauform
# AudiConnect 0.9.22 / EVCC 0.9.34. Der Installer ruft dieses Skript bei
# JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung und VOR dem
# Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich; Entscheidung 8, Frage 17). Dann tut es nichts:
# Zweitschriften und Sicherung braucht postinstall.sh bzw. postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# (config/plugins/<ordner>.backup.*: midea2lox.cfg mit dem Midea-Kennwort,
# devices.cfg mit Token und Schluessel der Klimageraete, die Abo-Datei) und
# eine Upgrade-Sicherung (data/plugins/<ordner>.upgrade_sicherung*) einer
# frueheren Installation gehen nach <name>.alt, der Merker .lief_vorher wird
# entfernt, gemeldet mit genau einer <WARNING>. Bis 4.5.9 spielte
# postinstall.sh sie ungefragt zurueck: eine "saubere" Neuinstallation holte
# das alte Midea-Konto samt Kennwort und die alten Geraete zurueck und
# meldete "nach einer Aktualisierung ist nichts weiter zu tun" (in WSL
# gemessen, Bericht installer Befund 1, Fall F1). Einen Weg dahin gibt es:
# 4.3.1/4.3.2 raeumten beim Deinstallieren die drei .backup.*.cfg nicht ab.
# Nichts in der Linie liest .alt; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-Midea2Lox}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Wurzelsuche wie in den uebrigen Hakenskripten: ohne config/plugins,
# data/plugins UND config/system/general.json wird nichts angefasst.
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER".backup.* \
            "$BASE/data/plugins/$PFOLDER".upgrade_sicherung*; do
    [ -e "$ZIEL" ] || [ -L "$ZIEL" ] || continue
    # Was schon beiseiteliegt, bleibt liegen (die Deinstallation raeumt es ab).
    case "$ZIEL" in *.alt) continue ;; esac
    rm -rf "${ZIEL:?}.alt" 2>/dev/null
    if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
        BEISEITE="$BEISEITE $ZIEL.alt"
        if [ -d "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then
            chmod 0700 "$ZIEL.alt" 2>/dev/null
        elif [ -f "$ZIEL.alt" ] && [ ! -L "$ZIEL.alt" ]; then
            chmod 0600 "$ZIEL.alt" 2>/dev/null
        fi
    else
        FEST="$FEST $ZIEL"
    fi
done
MERKER="$BASE/data/plugins/$PFOLDER.lief_vorher"
if [ -e "$MERKER" ]; then
    rm -f "$MERKER" && BEISEITE="$BEISEITE (Startmerker $MERKER entfernt)"
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Midea-Kennwort und Geraetetoken einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
