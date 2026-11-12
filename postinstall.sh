#!/bin/bash

# Wird von bash *NACH* der Installation als Benutzer "loxberry" ausgefuehrt.
#
# Rueckgabewert 0 = in Ordnung, 1 = Warnung, 2 = Installation abbrechen.
#
# Legt die eigene Python-Umgebung (venv) an und installiert die benoetigten
# Module hinein. Der Weg ueber ein venv ist Absicht: er umgeht PEP 668, das
# auf Debian 12 und 13 jede systemweite pip-Installation abweist.
#
# Anders als frueher wird hier *jeder* Schritt geprueft. Schlaegt das Anlegen
# der Umgebung oder eine der Modulinstallationen fehl, bricht die Installation
# mit Rueckgabewert 2 ab, statt Erfolg zu melden und ein totes Plugin zu
# hinterlassen.

PDIR=$3
PVERSION=$4

# M2 (4.5.18, Gruppe 15): DIE WURZEL WIE IN postupgrade.sh. Bis 4.5.17 galt hier die Umgebung
# zuerst (LBHOMEDIR vor $5) und weiter unten $5 zuerst (NETZ_BASE) - zwei Wurzeln auf einem
# Pruefstand mit abweichender Umgebung -, und ein Ordnername ".." wurde nicht abgewiesen (Ziel
# des rm -rf waere bin/venv* gewesen). Jetzt: $5, sonst LBHOMEDIR, sonst Suche mit general.json;
# config/plugins und data/plugins muessen darunter liegen. Alle Pfade aus DIESER Wurzel.
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
LBHOMEDIR="${5:-$LBHOMEDIR}"
if [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR/config/plugins" ] || [ ! -d "$LBHOMEDIR/data/plugins" ]; then
    LBHOMEDIR=$(mi_wurzel_suchen) || LBHOMEDIR=""
fi
case "$PDIR" in
    ''|.|..|*/*) PDIR="" ;;
esac
if [ -z "$PDIR" ] || [ -z "$LBHOMEDIR" ]; then
	echo "<FAIL> Weder die Argumente noch die Umgebung nennen ein brauchbares LoxBerry-Verzeichnis"
	echo "<FAIL> und einen Plugin-Ordner (Ordner: '$3', Wurzel: '${5:-}'). Es wird nichts angefasst."
	exit 2
fi
LBPDATA="$LBHOMEDIR/data/plugins"
LBPBIN="$LBHOMEDIR/bin/plugins"
LBPCONFIG="$LBHOMEDIR/config/plugins"

PDATA=$LBPDATA/$PDIR
PBIN=$LBPBIN/$PDIR
PCONFIG=$LBPCONFIG/$PDIR

VENV="$PBIN/venv"
PIP="$VENV/bin/pip3"

# Fassungen der Python-Module an einer Stelle
MSMART_VERSION="2026.7.0"
PAHO_VERSION="<2.0.0"

echo "<INFO> Midea2Lox $PVERSION wird eingerichtet"
echo "<INFO> Datenverzeichnis:      $PDATA"
echo "<INFO> Programmverzeichnis:   $PBIN"
echo "<INFO> Konfigurationsordner:  $PCONFIG"

chmod +x "$PDATA/midea2lox.py" 2>/dev/null
chmod +x "$PDATA/discover.py" 2>/dev/null
# Ab 4.3.0: der minuetliche Waechter ruft dieses Stueck auf, um das
# Lebenszeichen zu senden. Ohne Ausfuehrungsrecht schweigt es - und zwar
# stillschweigend, weil der Cron seine Ausgabe verwirft.
chmod +x "$PDATA/lebenszeichen.py" 2>/dev/null
# Ab 4.5.9: uninstall/uninstall ruft es mit --mqtt-leeren auf.
chmod +x "$PDATA/mi_mqtt.py" 2>/dev/null

# ===================================================================
# DIE MARKE DER AKTUALISIERUNG (ab 4.5.10, I1/I3)
# ===================================================================
#
# Liegt data/plugins/<ordner>.upgrade_laeuft (preupgrade.sh legt sie als
# Erstes an, kein Altersvergleich), ist es eine Aktualisierung. Endet dieses
# Skript NICHT mit 0, entfernt der trap die Marke (Regeln/06: sonst sperrt sie
# den Dienst nach einem Abbruch eine Stunde lang, und die Oberflaeche zeigt
# "Aktualisierung laeuft"). Bei 0 raeumt postupgrade.sh sie ab. Gemessen
# (bash 5.2): Kommandoersetzung und Unterschale loesen den EXIT-trap nicht aus.
MI_MARKE="$PDATA.upgrade_laeuft"
MI_LIEF="$PDATA.lief_vorher"
MI_UPDATE=0
[ -f "$MI_MARKE" ] && MI_UPDATE=1
trap 'mi_rc=$?; if [ "$mi_rc" != 0 ] && [ -f "$MI_MARKE" ]; then rm -f "$MI_MARKE" 2>/dev/null && echo "<INFO> Marke der Aktualisierung entfernt (postinstall.sh endet mit $mi_rc)."; fi' EXIT

# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
# M2 (4.5.18): dieselbe Wurzel und derselbe Ordner wie oben.
NETZ_BASE="$LBHOMEDIR"
NETZ_PDIR="$PDIR"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
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
# M23 (4.5.18): dazu die Vorgabe 4.5.17 + Ergaenzung fenster_* (so schrieb sie die Oberflaeche) und
# die vollstaendige Paket-cfg 4.5.18 - jeder neue Schluessel zieht diese Liste in ALLEN DREI Skripten mit
# (4.5.22: die Paket-cfg mit vorkuehlen=; 4.5.27: zuletzt die Paket-cfg mit tls=auto).
MI_VORGABE_MIDEA="fb75336280d50f2c24f0c86a15ce7b9f8096ac11aa4db9d71a25275287fa93e9 1ebad3fa1da1408accd52c3a680c8d7de799c3da50aefb4993fd4dca11475460 cfcdf81105a935a872636e4400cdcd97f9f907f7d4b460f8ee92009292dbe0f5 5ddb66e57556eca9130ddb52f8583ff2d1f0a7fbf45149512754c2008d70a398 e5dbd96130fb2a27472df1604fb6382e043d6b05103452b2448e53fbcc17cba4 d455e827abce5156127854b02b7b3bb7a0270a8868f2af9422c4e16ed1085b0f e3c29744064a1f093c0a89cbb7fbf02f808d5ad8058fd23841e9ef86938c90b2"
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
            # M6 (4.5.18): endet wirklich mit einem Zeilenumbruch (ein NUL am Ende zaehlte bis
            # 4.5.17 als leer) und traegt kein NUL - eine nach Stromausfall genullte Haelfte
            # verdraengte sonst die heile Zweitschrift.
            [ "$(tail -c 1 "$2" | wc -l)" -eq 1 ] || return 1
            [ "$(tr -d '\000' < "$2" | wc -c)" -eq "$(wc -c < "$2")" ] || return 1
            grep -q '^[[:space:]]*\[default\][[:space:]]*$' "$2" || return 1
            for k in MINISERVER UDP_PORT DEBUG LoxberryIP maxConnectionLifetime region \
                     MideaUser MideaPassword mqtt_praefix abfragetakt lox_timeout; do
                grep -q "^[[:space:]]*$k[[:space:]]*=" "$2" || return 1
            done ;;
        devices.cfg)
            # Endet mit Zeilenumbruch, mindestens ein Abschnitt, jede Zeile ist
            # leer, Kommentar, Abschnitt oder "schluessel = wert".
            # M6 (4.5.18): endet wirklich mit einem Zeilenumbruch (ein NUL am Ende zaehlte bis
            # 4.5.17 als leer) und traegt kein NUL - eine nach Stromausfall genullte Haelfte
            # verdraengte sonst die heile Zweitschrift.
            [ "$(tail -c 1 "$2" | wc -l)" -eq 1 ] || return 1
            [ "$(tr -d '\000' < "$2" | wc -c)" -eq "$(wc -c < "$2")" ] || return 1
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
# "Verloren" heisst: die Datei traegt nichts Eigenes (mi_inhalt oben) - sie
# fehlt, ist leer, ist zeichengenau eine mitgelieferte Vorgabe dieser oder
# einer frueheren Fassung, oder sie ist unvollstaendig. Bis 4.5.7 galt eine
# abgeschnittene Datei als "gueltige Konfiguration" und wurde nicht ersetzt
# (Fall I1), und die Zweitschrift wurde ohne jede Pruefung zurueckgespielt -
# eine abgeschnittene verdraengte die heile Vorgabe (Fall I2). Die
# Sollsummen stehen jetzt im Inhaltsblock oben, nicht mehr hier.
netz_zurueck() {
    local datei ziel zweit
    datei=$1
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    mi_inhalt "$datei" "$ziel" && return 0
    if ! mi_inhalt "$datei" "$zweit"; then
        # Eine Zweitschrift, die selbst nur die Vorgabe ist, hat nichts zu
        # retten - das ist keine Warnung wert.
        mi_vorgabe "$datei" "$zweit" && return 0
        echo "<WARNING> Die Zweitschrift $zweit ist unvollstaendig;"
        echo "<WARNING> $datei wird daraus NICHT zurueckgespielt."
        return 0
    fi
    if cp -p "$zweit" "$ziel.neu" 2>/dev/null && cmp -s "$zweit" "$ziel.neu" \
       && mv -f "$ziel.neu" "$ziel" 2>/dev/null; then
        echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
    else
        rm -f "$ziel.neu" 2>/dev/null
        echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
        echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
    fi
}
# I1 (Entscheidung 1): zurueckgespielt wird NUR bei einer Aktualisierung
# (Marke liegt). Bei einer Neuinstallation hat preinstall.sh liegengebliebene
# Zweitschriften schon nach .alt gelegt; hier wird dann nichts eingespielt.
# I3: dieser Block steht seit 4.5.10 VOR dem Anlegen der Umgebung - scheitert
# pip (kein Internet), ist die Konfiguration trotzdem zurueck.
if [ "$MI_UPDATE" = 1 ]; then
	netz_zurueck "devices.cfg"
	netz_zurueck "midea2lox.cfg"
	netz_zurueck "mqtt_subscriptions.cfg"
fi

# ===================================================================
# UPDATE OHNE INTERNET (ab 4.5.10, I3)
# ===================================================================
#
# Bis 4.5.9 brach postinstall.sh beim Update mit 2 ab, sobald pip scheiterte
# (kein Internet). Das "Zurueckrollen" darunter konnte bis 4.5.17 nie greifen:
# purge_installation hatte bin/plugins/<ordner>/ samt venv schon geloescht. Seit
# 4.5.18 (M1) legt preupgrade.sh sie vorher NEBEN den Ordner, und unten wird sie
# zurueckgeholt - mi_nachholen() findet dann eine ladbare Umgebung.
# Danach war das Plugin tot - Vorgabekonfiguration, halbe venv, Marke noch
# eine Stunde gueltig, kein soll_laufen (in WSL gemessen, Bericht installer
# Befund 3, Fall V). Jetzt, NUR bei einer Aktualisierung: die Konfiguration
# ist schon zurueckgespielt (oben), eine unbrauchbare Umgebung wird entfernt,
# der Befehl zum Nachholen steht im Protokoll, der Merker soll_laufen folgt
# dem Stand vor dem Update, und das Skript endet mit 1 (Warnung) - die
# Marke raeumt der trap ab. Der Waechter (cron.01min) startet den Dienst in
# der ersten Minute, in der sich die Umgebung laden laesst.
# Bei einer Neuinstallation bleibt es beim Abbruch mit 2.
mi_nachholen() {  # $1 Grund
	[ "$MI_UPDATE" = 1 ] || return 0
	zurueckrollen
	if ! "$VENV/bin/python3" -c "import msmart.device, paho.mqtt.client" >/dev/null 2>&1; then
		rm -rf "${VENV:?}" 2>/dev/null
		echo "<WARNING> Die Aktualisierung ist unvollstaendig: $1"
		echo "<WARNING> Die Einstellungen sind zurueckgespielt. Der Dienst startet von selbst, sobald die"
		echo "<WARNING> Python-Umgebung vollstaendig ist. Nachholen, sobald der LoxBerry Internet hat"
		echo "<WARNING> (als Benutzer loxberry, in einer Zeile):"
	else
		# M1 (4.5.18): die zurueckgeholte Umgebung laedt - der Dienst arbeitet mit ihr weiter.
		echo "<WARNING> pip scheiterte (kein Internet?): $1"
		echo "<WARNING> Die bisherige Python-Umgebung ist wiederhergestellt; der Dienst arbeitet mit ihr"
		echo "<WARNING> weiter. Nachholen bei Gelegenheit (als Benutzer loxberry, in einer Zeile):"
	fi
	echo "<WARNING>   python3 -m venv '$VENV' && '$PIP' install --extra-index-url https://www.piwheels.org/simple --prefer-binary requests 'paho-mqtt${PAHO_VERSION}' ifaddr 'msmart-ng==${MSMART_VERSION}'"
	if [ -f "$MI_LIEF" ]; then
		mkdir -p "$PDATA" 2>/dev/null && : > "$PDATA/soll_laufen" 2>/dev/null
		echo "<INFO> Der Dienst lief vor dem Update und startet, sobald die Umgebung vollstaendig ist."
	fi
	exit 1
}

# ---------------------------------------------------------------------------
# 1. Virtuelle Python-Umgebung anlegen
# ---------------------------------------------------------------------------

echo "<INFO> Lege die virtuelle Python-Umgebung an: $VENV"

# Die bisherige Umgebung wird BEISEITEGELEGT, nicht weggeworfen.
#
# E8 (4.5.18): beim Update gilt das erst seit M1 - purge_installation loescht
# bin/plugins/<ordner>/ (plugininstall.pl :1649) VOR diesem Skript; preupgrade.sh
# legt die Umgebung deshalb neben den Ordner (<ordner>.venv_vorher), und hier wird
# sie zuerst zurueckgeholt. Danach arbeitet der Block wie beschrieben.
#
# Bis 4.3.2 stand hier "rm -rf $VENV" vor jeder Pruefung. Ein
# Aktualisierungsversuch ohne Internet - pip_install bricht dann mit
# Rueckgabewert 2 ab - liess damit eine leere Umgebung zurueck und machte
# aus einem laufenden Plugin ein totes. Jetzt wird zurueckgerollt, und der
# Anwender behaelt den Stand, mit dem er vorher gearbeitet hat.
#
# Verschoben statt kopiert, und zwar an den Ort und wieder zurueck: in einem
# venv stehen absolute Pfade (pyvenv.cfg und jede Shebang-Zeile unter bin/).
# Eine an einem anderen Namen gebaute Umgebung waere nach dem Umbenennen
# kaputt - deshalb entsteht die neue immer unter dem endgueltigen Namen.
# M1 (4.5.18): die Umgebung von preupgrade.sh zurueckholen - an DENSELBEN Pfad, an dem sie
# gebaut wurde (absolute Pfade in pyvenv.cfg und den Shebangs).
if [ ! -d "$VENV" ] && [ -d "$PBIN.venv_vorher" ]; then
	mkdir -p "$PBIN" 2>/dev/null
	if mv -T "$PBIN.venv_vorher" "$VENV" 2>/dev/null; then
		echo "<INFO> Bisherige Python-Umgebung zurueckgeholt."
	else
		echo "<WARNING> Die beiseitegelegte Python-Umgebung liess sich nicht zurueckholen: $PBIN.venv_vorher"
	fi
fi
VENV_VORHER="$PBIN/venv.vorher"
rm -rf "$VENV_VORHER"
if [ -d "$VENV" ]; then
	mv "$VENV" "$VENV_VORHER" 2>/dev/null || rm -rf "$VENV"
fi

zurueckrollen() {
	if [ -d "$VENV_VORHER" ]; then
		rm -rf "$VENV"
		if mv "$VENV_VORHER" "$VENV" 2>/dev/null; then
			echo "<WARNING> Die bisherige Umgebung wurde wiederhergestellt."
			echo "<WARNING> Das Plugin arbeitet mit dem Stand von vorher weiter."
		fi
	fi
}

if ! python3 -m venv "$VENV"; then
	mi_nachholen "die virtuelle Python-Umgebung liess sich nicht anlegen (python3-venv fehlt?)."
	echo "<FAIL> Die virtuelle Python-Umgebung konnte nicht angelegt werden."
	echo "<FAIL> Meist fehlt dafuer das Paket python3-venv. Es steht in dpkg/apt;"
	echo "<FAIL> wenn der Paketschritt fehlgeschlagen ist, bitte im"
	echo "<FAIL> Installationsprotokoll weiter oben nachsehen."
	exit 2
fi

if [ ! -x "$PIP" ]; then
	mi_nachholen "in der neuen Umgebung ist kein pip3 vorhanden ($PIP)."
	echo "<FAIL> In der neuen Umgebung ist kein pip3 vorhanden ($PIP)."
	zurueckrollen
	exit 2
fi

echo "<OK> Virtuelle Umgebung angelegt."

# ---------------------------------------------------------------------------
# 2. Module hineininstallieren - jeder Schritt wird geprueft
# ---------------------------------------------------------------------------

# piwheels liefert fertige Pakete fuer den Raspberry Pi. Auf x86 ist der
# zusaetzliche Index unschaedlich, dort greift PyPI.
PIPOPTS="--extra-index-url https://www.piwheels.org/simple --prefer-binary"

pip_install() {
	local paket="$1"
	echo "<INFO> Installiere $paket ..."
	# "$PIP" in Anfuehrungszeichen - $PIPOPTS soll zerfallen, der Pfad nicht.
	if ! "$PIP" install $PIPOPTS "$paket"; then
		mi_nachholen "'$paket' liess sich nicht installieren (haeufigste Ursache: keine Internetverbindung)."
		echo "<FAIL> Die Installation von '$paket' ist fehlgeschlagen."
		echo "<FAIL> Ohne dieses Modul kann Midea2Lox nicht arbeiten."
		echo "<FAIL> Haeufigste Ursache: keine Internetverbindung waehrend der Installation."
		zurueckrollen
		exit 2
	fi
	echo "<OK> $paket installiert."
}

echo "<INFO> Aktualisiere pip in der Umgebung..."
"$PIP" install --upgrade pip setuptools wheel >/dev/null 2>&1 || \
	echo "<WARNING> pip liess sich nicht aktualisieren - wird fortgesetzt."

pip_install "requests"
pip_install "paho-mqtt${PAHO_VERSION}"
pip_install "ifaddr"
pip_install "msmart-ng==${MSMART_VERSION}"

# ---------------------------------------------------------------------------
# 3. Gegenprobe: laesst sich msmart in der Umgebung wirklich laden?
# ---------------------------------------------------------------------------

echo "<INFO> Pruefe die Umgebung gegen..."
if ! "$VENV/bin/python3" -c "from msmart.device import AirConditioner; import paho.mqtt.client" 2>&1; then
	mi_nachholen "die Module liessen sich zwar installieren, aber nicht laden."
	echo "<FAIL> Die Module liessen sich zwar installieren, aber nicht laden."
	zurueckrollen
	exit 2
fi

INSTALLED=$("$VENV/bin/python3" -c "import msmart; print(msmart.__version__)" 2>/dev/null)
echo "<OK> Umgebung einsatzbereit - msmart-ng $INSTALLED"

# Erst JETZT ist die alte Umgebung entbehrlich.
rm -rf "$VENV_VORHER"
# M1: und ein Rest neben dem Ordner (genauer Name, kein Muster).
rm -rf "${PBIN:?}.venv_vorher"

# ---------------------------------------------------------------------------
# Die Erstanleitung steht seit 4.5.9 am Ende: erst nach dem Zurueckspielen
# aus der Zweitschrift ist bekannt, ob schon eingerichtet ist.


# ---------------------------------------------------------------------------
# Erstanleitung NUR, wenn noch nichts eingerichtet ist (seit 4.5.9)
# ---------------------------------------------------------------------------
#
# postinstall.sh laeuft bei der Erstinstallation UND bei jedem Update
# (plugininstall.pl uebergibt kein Kennzeichen). Bis 4.5.8 stand die
# Anleitung "Einstellungen anpassen und speichern, danach den Dienst
# starten" unbedingt da - auch nach einem gelungenen Update, bei dem die
# Einstellungen gerade zurueckgespielt wurden und postupgrade.sh den Dienst
# gleich wieder startet (in WSL gemessen, Pruefung-Midea2Lox-4.5.9, Faelle
# I2 und I4). Entscheidung des Hausherrn vom 24.09.2026, gemeinsamer
# Auftrag Bestand-2026-09-18/AUFTRAG_postinstall-hinweis_2026-09-24.md.
#
# "Eingerichtet" entscheidet der INHALT (mi_inhalt oben, dieselbe Pruefung
# wie fuer die Zweitschrift): midea2lox.cfg oder devices.cfg tragen Eigenes.
# Liegt das Eigene erst in der Sicherung dieses Updates, holt postupgrade.sh
# es gleich zurueck - auch dann keine Erstanleitung.
MI_SICHER_CFG="$NETZ_BASE/data/plugins/$NETZ_PDIR.upgrade_sicherung/config"
# Das Schlusswort "nach einer Aktualisierung" nur NACH einer (I1): bis 4.5.9
# stand es auch nach einer Neuinstallation, die alte Zweitschriften
# eingespielt hatte (Bericht installer Befund 1).
if [ "$MI_UPDATE" = 1 ] && { mi_inhalt midea2lox.cfg "$NETZ_CFG/midea2lox.cfg" || mi_inhalt devices.cfg "$NETZ_CFG/devices.cfg"; }; then
	echo "<OK> Die Einstellungen sind uebernommen - nach einer Aktualisierung ist nichts weiter zu tun."
elif [ "$MI_UPDATE" != 1 ] && { mi_inhalt midea2lox.cfg "$NETZ_CFG/midea2lox.cfg" || mi_inhalt devices.cfg "$NETZ_CFG/devices.cfg"; }; then
	echo "<INFO> Im Konfigurationsordner liegen schon eigene Einstellungen."
elif [ "$MI_UPDATE" = 1 ] && { mi_inhalt midea2lox.cfg "$MI_SICHER_CFG/midea2lox.cfg" || mi_inhalt devices.cfg "$MI_SICHER_CFG/devices.cfg"; }; then
	echo "<INFO> Die Einstellungen liegen in der Sicherung dieser Aktualisierung; postupgrade.sh"
	echo "<INFO> spielt sie gleich zurueck und startet den Dienst."
else
	/bin/echo "#############################################################################################"
	/bin/echo "#  Nach der Installation bitte die Einstellungen zu allen MiniServern anpassen und speichern."
	/bin/echo "#  Danach den Dienst starten."
	/bin/echo "#"
	/bin/echo "#  Der Reiter \"Einbindung in Loxone\" im Plugin enthaelt eine Schritt-fuer-Schritt-"
	/bin/echo "#  Anleitung samt kompletter Baustein-Liste zum Nachbauen."
	/bin/echo "#############################################################################################"
fi

exit 0
