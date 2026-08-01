#!/bin/bash

# Wird von bash *VOR* der Installation als root ausgefuehrt.
#
# Rueckgabewert 0 = in Ordnung, 1 = Warnung, 2 = Installation abbrechen.
#
# Aufgabe dieses Skripts: pruefen, ob das System ein brauchbares Python 3
# mitbringt. Frueher hat das Plugin an dieser Stelle bei zu altem Python
# OpenSSL 1.1.1w und Python 3.9.16 aus dem Quelltext gebaut und per
# "sudo make install" ins System geschoben. Das ist ersatzlos entfallen:
# OpenSSL 1.1.1 ist seit dem 11.09.2023 abgekuendigt, und ein Plugin hat
# nichts am System-OpenSSL zu suchen. Stattdessen bricht die Installation
# jetzt mit einer klaren Meldung ab.

PVERSION=$4

# msmart-ng verlangt ab 2026.4.0 mindestens Python 3.10.
PYTHON_MIN_MAJOR=3
PYTHON_MIN_MINOR=10

echo "<INFO> Midea2Lox $PVERSION - pruefe die Python-Fassung..."

PYTHON3_REF=$(command -v python3)

if [ -z "$PYTHON3_REF" ]; then
	echo "<FAIL> Es wurde kein python3 gefunden."
	echo "<FAIL> Midea2Lox braucht mindestens Python ${PYTHON_MIN_MAJOR}.${PYTHON_MIN_MINOR}."
	exit 2
fi

VERSION_MAJOR=$("$PYTHON3_REF" -c 'import sys; print(sys.version_info[0])' 2>/dev/null)
VERSION_MINOR=$("$PYTHON3_REF" -c 'import sys; print(sys.version_info[1])' 2>/dev/null)

if [ -z "$VERSION_MAJOR" ] || [ -z "$VERSION_MINOR" ]; then
	echo "<FAIL> Die Python-Fassung liess sich nicht ermitteln ($PYTHON3_REF)."
	exit 2
fi

echo "<INFO> Gefunden: Python ${VERSION_MAJOR}.${VERSION_MINOR} unter $PYTHON3_REF"

if [ "$VERSION_MAJOR" -lt "$PYTHON_MIN_MAJOR" ] || \
   { [ "$VERSION_MAJOR" -eq "$PYTHON_MIN_MAJOR" ] && [ "$VERSION_MINOR" -lt "$PYTHON_MIN_MINOR" ]; }; then
	echo "<FAIL> Zu alt. Midea2Lox braucht mindestens Python ${PYTHON_MIN_MAJOR}.${PYTHON_MIN_MINOR},"
	echo "<FAIL> gefunden wurde ${VERSION_MAJOR}.${VERSION_MINOR}."
	echo "<FAIL>"
	echo "<FAIL> Das betrifft in aller Regel LoxBerry-Installationen, die ueber viele"
	echo "<FAIL> Jahre hochgezogen wurden und noch das Python des urspruenglichen"
	echo "<FAIL> Abbilds benutzen. Ein Plugin darf das Python des Systems nicht"
	echo "<FAIL> austauschen - der richtige Weg ist ein aktuelles LoxBerry-Abbild."
	exit 2
fi

# Das Modul venv gehoert nicht bei jeder Installation zum Lieferumfang.
if ! "$PYTHON3_REF" -c 'import venv' 2>/dev/null; then
	echo "<WARNING> Das Python-Modul 'venv' fehlt. Es wird ueber dpkg/apt"
	echo "<WARNING> (python3-venv) nachinstalliert - falls das scheitert,"
	echo "<WARNING> bricht die Installation spaeter mit einer Meldung ab."
fi

echo "<OK> Python-Pruefung bestanden."
exit 0
