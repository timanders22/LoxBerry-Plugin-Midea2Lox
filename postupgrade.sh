#!/bin/bash

# Wird nach einer Aktualisierung ausgefuehrt (nach postinstall).
# Holt die in preupgrade.sh gesicherten Konfigurationsdateien zurueck
# und startet den Dienst.

PTEMPDIR=$1
PDIR=$3
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"

# DIE WURZEL: GELESEN, NICHT GERATEN (seit 4.5.9). Bis 4.5.8 stand hier
# "LBHOME=$5" ohne Pruefung; blieb $5 leer, lauteten alle Pfade ab der
# Laufwerkswurzel, und das Skript versuchte /system/daemons/plugins/<ordner>
# zu starten (in WSL gemessen, Pruefung-Midea2Lox-4.5.9, Fall W2). Bauart
# wie preupgrade.sh: $5, sonst LBHOMEDIR, sonst Suche mit general.json;
# ohne Wurzel oder Ordner wird gewarnt statt vollzogen.
mi_wurzel_suchen() {
    mi_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd) || return 1
    mi_i=0
    while [ -n "$mi_v" ] && [ "$mi_v" != "/" ] && [ "$mi_i" -lt 8 ]; do
        if [ -d "$mi_v/config/plugins" ] && [ -d "$mi_v/data/plugins" ] \
           && [ -f "$mi_v/config/system/general.json" ]; then
            echo "$mi_v"
            return 0
        fi
        mi_v=$(dirname "$mi_v")
        mi_i=$((mi_i + 1))
    done
    return 1
}
LBHOME="${5:-$LBHOMEDIR}"
if [ -z "$LBHOME" ] || [ ! -d "$LBHOME/config/plugins" ] || [ ! -d "$LBHOME/data/plugins" ]; then
    LBHOME=$(mi_wurzel_suchen) || LBHOME=""
fi
case "$PDIR" in
    ''|.|..|*/*) PDIR="" ;;
esac
if [ -z "$LBHOME" ] || [ -z "$PDIR" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis oder kein Plugin-Ordner"
    echo "<WARNING> gefunden (Ordner: '$PDIR', Wurzel: '$LBHOME'). Es wurde nichts"
    echo "<WARNING> zurueckgestellt und kein Dienst gestartet."
    exit 1
fi
LBHOMEDIR=$LBHOME
set -- "$1" "$2" "$PDIR" "$4" "$LBHOME"

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

# Die Sicherung liegt seit dem 10.08.2026 unter data/ statt unter /tmp: /tmp
# ist auf dem LoxBerry eine Ramdisk und ausserdem fuer jeden lesbar.
#
# Nebenbei behoben: der alte Pfad trug ein zusaetzliches /$PDIR am Ende, weil
# 'cp -r quelle/ ziel' das Quellverzeichnis MIT anlegt. preupgrade sichert
# jetzt mit 'cp -a quelle/. ziel/' den Inhalt - ohne die Zwischenebene.
SICHER="$LBHOME/data/plugins/$PDIR.upgrade_sicherung"

mi_abweichend() {  # $1 Quellordner, $2 Kopie, $3 Ausnahmen -> Dateien, die in der Kopie fehlen oder abweichen
    [ -d "$1" ] || return 0
    ( cd "$1" && find . -type f | while IFS= read -r mi_f; do
          case " $3 " in *" ${mi_f#./} "*) continue ;; esac
          cmp -s "$mi_f" "$2/$mi_f" || printf '%s ' "${mi_f#./}"
      done ) 2>/dev/null || echo "(nicht lesbar: $1)"
}

echo "<INFO> Stelle die gesicherten Konfigurationsdateien wieder her"
# Die Sicherung faellt nur, wenn das Zurueckstellen nachweislich gewirkt hat.
# Bis 4.5.7 stand das "rm -rf $SICHER" unbedingt hinter dem cp, und die
# Erfolgsmeldung hing am Rueckgabewert allein: scheiterte das Kopieren, war
# nach diesem Skript weder die Konfiguration noch die Sicherung da (in WSL
# nachgemessen 18.09.2026, Pruefung-Midea2Lox-4.5.8, Fall P1: das Merkwort
# war danach nirgends mehr heil). Jetzt wird jede Datei der Sicherung
# byteweise im Konfigordner nachgesehen; weicht eine ab, bleibt die
# Sicherung liegen.
#
# NACH INHALT, NICHT BLIND (seit 4.5.9). Bis 4.5.8 legte das cp die GANZE
# Sicherung ueber den Konfigordner. Trug die Sicherung nur die Vorgabe - die
# Einstellungen waren schon vor dem Update verloren - oder eine abgeschnittene
# Datei, ueberschrieb sie, was postinstall.sh eben aus der heilen Zweitschrift
# zurueckgeholt hatte, und meldete "zurueckgestellt" (in WSL gemessen,
# Pruefung-Midea2Lox-4.5.9, Faelle S1 und S3). Jetzt bleibt eine Datei des
# Konfigordners stehen, wenn SIE Eigenes traegt und ihre Fassung in der
# Sicherung nicht (mi_inhalt oben); alles andere kommt wie bisher aus der
# Sicherung. Die Sicherung faellt danach nur, wenn jede zurueckgestellte Datei
# byteweise angekommen ist - das ist die Rueckholung nach Inhalt.
MI_BEHALTEN=0
if [ -d "$SICHER/config" ] && [ -n "$(ls -A "$SICHER/config" 2>/dev/null)" ]; then
	MI_ZIEL="$LBHOME/config/plugins/$PDIR"
	mkdir -p "$MI_ZIEL" 2>/dev/null
	MI_RC=0
	MI_BEHIELT=""
	for mi_q in "$SICHER"/config/* "$SICHER"/config/.[!.]*; do
		[ -e "$mi_q" ] || continue
		mi_n=${mi_q##*/}
		if [ -f "$mi_q" ] && ! mi_inhalt "$mi_n" "$mi_q" && mi_inhalt "$mi_n" "$MI_ZIEL/$mi_n"; then
			MI_BEHIELT="$MI_BEHIELT $mi_n"
			continue
		fi
		cp -a "$mi_q" "$MI_ZIEL/" 2>/dev/null || MI_RC=$?
	done
	MI_ABW=$(mi_abweichend "$SICHER/config" "$MI_ZIEL" "$MI_BEHIELT")
	if [ "$MI_RC" -eq 0 ] && [ -z "$MI_ABW" ]; then
		echo "<OK> Konfiguration zurueckgestellt."
		if [ -n "$MI_BEHIELT" ]; then
			echo "<INFO> Beibehalten, weil die Sicherung dort nichts Eigenes traegt, die"
			echo "<INFO> Konfiguration aber schon (aus der Zweitschrift):$MI_BEHIELT"
		fi
	else
		MI_BEHALTEN=1
		echo "<WARNING> Die Konfiguration liess sich NICHT vollstaendig zurueckstellen"
		echo "<WARNING> (cp Rueckgabewert $MI_RC; abweichend: ${MI_ABW:-keine})."
		echo "<WARNING> Die Sicherung bleibt liegen: $SICHER"
	fi
else
	# Kein blinder Alarm: der Installer loescht beim Update AUCH
	# data/plugins/<ordner> und damit die Sicherung, die preupgrade.sh
	# dorthin geschrieben hat - diese Kette kann hier gar nichts finden.
	# Gerettet wird aus der Zweitschrift neben dem Ordner, und das tut
	# postinstall.sh, das VOR postupgrade laeuft. Also erst nachsehen,
	# wie es wirklich steht; eine Warnung bei heiler Konfiguration
	# erschreckt ohne Grund und entwertet die echte.
	#
	# Nachgesehen wird nach INHALT (mi_inhalt oben). Bis 4.5.7 stand hier
	# "[ -s ]", und die mitgelieferte Vorgabe - die der Installer gerade
	# eingespielt hat - ist nie leer: die Meldung "vorhanden (aus der
	# Zweitschrift)" kam auch dann, wenn es gar keine Zweitschrift gab
	# (Faelle P2, P3).
	NETZ_PRUEF="${5:-$LBHOMEDIR}/config/plugins/${3:-Midea2Lox}/midea2lox.cfg"
	if mi_inhalt midea2lox.cfg "$NETZ_PRUEF"; then
	    echo "<OK> Die Einstellungen sind vorhanden (aus der Zweitschrift)."
	else
	echo "<WARNING> Keine Sicherung gefunden, und die Einstellungen tragen nichts Eigenes"
	echo "<WARNING> (sie fehlen, sind die Vorgabe oder unvollstaendig): $NETZ_PRUEF"
	fi
fi

if [ "$MI_BEHALTEN" = 0 ]; then
	echo "<INFO> Entferne den Sicherungsordner"
	rm -rf "$SICHER"
fi

# Der Dienst wird ueber das Startskript gestartet, nicht direkt. Bis 3.4.8
# stand hier ein "./midea2lox.py &" - das umging das Startskript und lief
# damit ohne dessen Pruefungen.
#
# Der Rueckgabewert wird seit 4.5.6 angesehen und gemeldet. Bis 4.5.5 war
# er ohnehin wertlos: das Startskript endete immer mit 0. Die Ausgabe des
# Skripts geht ins Installationsprotokoll - das Einzige, was der Anwender
# von den Hakenskripten je zu sehen bekommt; eine Erfolgsmeldung fuer einen
# Dienst, der nicht laeuft, schickt ihn an die falsche Stelle
# (Regeln/06, APC-UPS NG 1.2.5).
#
# ZWEI AUSNAHMEN, UND WARUM SIE HIER STEHEN
#
# daemon/daemon startet seit 4.5.7 nicht mehr blind. Es fragt zweierlei:
#   - Liegt der Merker soll_laufen? purge_installation hat den Datenordner
#     gerade abgeraeumt, also liegt er nie - MI_START_TROTZ_WILLE=1.
#   - Liegt die Marke aus preupgrade.sh? Sie liegt noch, denn sie wird erst
#     unten entfernt - MI_START_TROTZ_MARKE=1.
# Beide Ausnahmen gelten nur fuer diesen einen Aufruf. Es ist der Start
# nach der Installation, und er ist gewollt.
#
# Die Marke faellt erst NACH dem Start. Umgekehrt - Marke weg, dann starten
# - bliebe zwischen beiden Schritten ein Fenster offen, in dem ein
# Minutentakt weder die Marke noch einen laufenden Dienst sieht und einen
# eigenen startet; an Chromecast4lox 1.3.10 mit 400 Waechterlaeufen im
# Abstand von 0,02 s gemessen (17.09.2026). Mit der Ausnahme oben ist das
# Fenster ganz geschlossen: die Marke liegt waehrend des ganzen Starts.
echo "<INFO> Starte Midea2Lox"
STARTAUS=$(MI_START_TROTZ_WILLE=1 MI_START_TROTZ_MARKE=1 \
	"$LBHOME/system/daemons/plugins/$PDIR" restart 2>&1)
STARTRC=$?
if [ "$STARTRC" -eq 0 ]; then
	echo "<OK> Midea2Lox laeuft."
else
	echo "$STARTAUS" | sed 's/^/<WARNING> /'
	echo "<WARNING> Midea2Lox laeuft nach dem Update nicht. Der minuetliche"
	echo "<WARNING> Waechter versucht es weiter; der Grund steht in"
	echo "<WARNING> log/plugins/$PDIR/midea2lox.log."
fi

# Die Marke der Aktualisierung faellt hier - postupgrade.sh ist in dieser
# Linie das letzte Hakenskript, das LoxBerry ruft (es gibt kein
# postroot.sh; Reihenfolge nach Regeln/06: preroot, preinstall, preupgrade,
# postinstall, postupgrade, postroot).
MARKE="$LBHOME/data/plugins/$PDIR.upgrade_laeuft"
if [ -f "$MARKE" ]; then
	rm -f "$MARKE" 2>/dev/null
	if [ -f "$MARKE" ]; then
		echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
		echo "<WARNING> Sie verfaellt nach einer Stunde von selbst."
	else
		echo "<OK> Marke der Aktualisierung entfernt."
	fi
fi

exit 0
