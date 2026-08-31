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

# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
# preupgrade.sh und postupgrade.sh haben diese Absicherung seit jeher;
# hier fehlte sie als einziger der fuenf Schalen. Ohne sie wird aus
# "$LBPBIN/$PDIR" der Pfad "/Midea2Lox", und das rm -rf weiter unten
# laeuft darauf los.
LBHOMEDIR="${LBHOMEDIR:-$5}"
LBPDATA="${LBPDATA:-$LBHOMEDIR/data/plugins}"
LBPBIN="${LBPBIN:-$LBHOMEDIR/bin/plugins}"
LBPCONFIG="${LBPCONFIG:-$LBHOMEDIR/config/plugins}"

if [ -z "$PDIR" ] || [ -z "$LBHOMEDIR" ] || [ ! -d "$LBHOMEDIR" ]; then
	echo "<FAIL> Weder die Umgebungsvariablen noch die Argumente nennen ein"
	echo "<FAIL> brauchbares LoxBerry-Verzeichnis. Es wird nichts angefasst."
	exit 2
fi

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

# ---------------------------------------------------------------------------
# 1. Virtuelle Python-Umgebung anlegen
# ---------------------------------------------------------------------------

echo "<INFO> Lege die virtuelle Python-Umgebung an: $VENV"

# Die bisherige Umgebung wird BEISEITEGELEGT, nicht weggeworfen.
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
	echo "<FAIL> Die virtuelle Python-Umgebung konnte nicht angelegt werden."
	echo "<FAIL> Meist fehlt dafuer das Paket python3-venv. Es steht in dpkg/apt;"
	echo "<FAIL> wenn der Paketschritt fehlgeschlagen ist, bitte im"
	echo "<FAIL> Installationsprotokoll weiter oben nachsehen."
	exit 2
fi

if [ ! -x "$PIP" ]; then
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
	echo "<FAIL> Die Module liessen sich zwar installieren, aber nicht laden."
	zurueckrollen
	exit 2
fi

INSTALLED=$("$VENV/bin/python3" -c "import msmart; print(msmart.__version__)" 2>/dev/null)
echo "<OK> Umgebung einsatzbereit - msmart-ng $INSTALLED"

# Erst JETZT ist die alte Umgebung entbehrlich.
rm -rf "$VENV_VORHER"

# ---------------------------------------------------------------------------

/bin/echo "#############################################################################################"
/bin/echo "#  Nach der Installation bitte die Einstellungen zu allen MiniServern anpassen und speichern."
/bin/echo "#  Danach den Dienst starten."
/bin/echo "#"
/bin/echo "#  Der Reiter \"Einbindung in Loxone\" im Plugin enthaelt eine Schritt-fuer-Schritt-"
/bin/echo "#  Anleitung samt kompletter Baustein-Liste zum Nachbauen."
/bin/echo "#############################################################################################"


# ==== NETZ-EINSTELLUNGEN-UPDATE (automatisch eingefuegt, nicht doppeln) ====
# Zurueckspielen aus der Zweitschrift - aber NUR, wenn die Datei des Nutzers
# wirklich verloren ist. Erkannt wird das an dreierlei: sie fehlt, sie ist
# leer, oder sie ist zeichengenau die mitgelieferte Vorgabe (Pruefsumme
# unten). Der letzte Fall ist der eigentliche: genau so sieht die Datei nach
# dem Kopierschritt des Installers aus.
#
# Eine gueltige Konfiguration wird NIE ueberschrieben. Eine Sicherung, die
# echte Einstellungen ersetzt, waere schlimmer als gar keine.
NETZ_BASE="${5:-$LBHOMEDIR}"
NETZ_PDIR="${3:-Midea2Lox}"
NETZ_CFG="$NETZ_BASE/config/plugins/$NETZ_PDIR"
# Ab 4.3.0 nimmt netz_zurueck MEHRERE Sollsummen entgegen, durch Leerzeichen
# getrennt: die der mitgelieferten Vorgabe DIESER Fassung und die der
# Vorgaengerfassungen. Grund ist der Aktualisierungsfall - wer nie gespeichert
# hat, dessen Datei ist zeichengenau die Vorgabe der ALTEN Fassung, und mit
# nur einer Summe wuerde sie nicht als "verloren" erkannt. Eine Zahl, die nur
# fuer die neueste Fassung stimmt, ist eine Pruefung auf Zeit.
netz_zurueck() {
    local datei ziel zweit verloren ist soll
    datei=$1; shift
    ziel="$NETZ_CFG/$datei"
    zweit="$NETZ_BASE/config/plugins/$NETZ_PDIR.backup.$datei"
    [ -f "$zweit" ] || return 0
    verloren=0
    if [ ! -f "$ziel" ] || [ ! -s "$ziel" ]; then
        verloren=1
    else
        ist=$(sha256sum "$ziel" 2>/dev/null | cut -d" " -f1)
        for soll in "$@"; do
            [ -n "$ist" ] && [ "$ist" = "$soll" ] && verloren=1
        done
    fi
    if [ "$verloren" = "1" ]; then
        if cp -p "$zweit" "$ziel" 2>/dev/null; then
            echo "<OK> $datei aus der Zweitschrift wiederhergestellt."
        else
            echo "<WARNING> $datei liess sich nicht zurueckspielen. Die Sicherung"
            echo "<WARNING> liegt unter $zweit und kann von Hand kopiert werden."
        fi
    fi
}
# Sollsummen: erst die Vorgabe dieser Fassung, dann die der Vorgaenger.
netz_zurueck "devices.cfg"     "db6bd81a12e08bbc0182a54b6af10e28ef6ab47b75e79ed968cad04003cf88c7"
netz_zurueck "midea2lox.cfg"     "1ebad3fa1da1408accd52c3a680c8d7de799c3da50aefb4993fd4dca11475460"     "cfcdf81105a935a872636e4400cdcd97f9f907f7d4b460f8ee92009292dbe0f5"
netz_zurueck "mqtt_subscriptions.cfg"     "a5cc6d64cc2ad24c25a747efd2105a1be007b8111ef84fea241d2c08d32e30de"

exit 0
