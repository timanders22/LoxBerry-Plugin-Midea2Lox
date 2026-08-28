#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - Geraetesuche.

Sucht die Klimageraete im lokalen Netz, holt fuer V3-Geraete Token und
Schluessel und schreibt alles nach config/plugins/<Ordner>/devices.cfg.

WAS SICH IN 4.3.0 GEAENDERT HAT

1. Die eingetragenen Zugangsdaten und die Region werden WIRKLICH uebergeben.
   Bis 4.2.12 rief diese Datei Discover.discover() ohne Argumente auf, und
   MideaUser, MideaPassword und region kamen im ganzen data/-Baum kein
   einziges Mal vor - gemessen mit einer Suche ueber den gesamten Ordner,
   gegengeprueft an einem Muster, das es sicher gibt. Vier Stellen des
   Plugins sagten trotzdem, die Zugangsdaten wuerden fuer die Suche
   gebraucht.

   An der Primaerquelle nachgesehen (mill1000/midea-msmart, Zweig main,
   abgerufen 27.08.2026):

       async def discover(cls, *, target=..., timeout=5, discovery_packets=3,
                          interface=None, region: str = DEFAULT_CLOUD_REGION,
                          account=None, password=None, auto_connect=True, ...)

       # msmart/cloud.py
       if account and password:   self._account, self._password = account, password
       elif account or password:  raise ValueError("Account and password must be specified.")
       else:
           try:    self._account, self._password = self.CLOUD_CREDENTIALS[region]
           except KeyError: raise ValueError(f"Unknown cloud region '{region}'.")

       # msmart/const.py
       DEFAULT_CLOUD_REGION = "US"

   Daraus folgen die drei Regeln unten: nur BEIDE Zugangsdaten zusammen
   uebergeben, die Region auf einen der drei Serverbereiche abbilden, und
   ohne gueltige Zuordnung gar keine Region uebergeben statt zu raten.

2. Die Suche stirbt nicht mehr an dem Fall, fuer den sie geschrieben wurde.
   Bis 4.2.12 stand hier

       if device.token is None and cfg_devices.get('Midea_'+id, "token"):

   Der Abschnitt war gerade erst angelegt worden und hatte keinen Schluessel
   "token"; RawConfigParser.get wirft dann NoOptionError. Das nackte except
   am Ende fing ihn und rief sys.exit() - und die beiden write()-Aufrufe
   standen DANACH. devices.cfg wurde also nie geschrieben, auch nicht fuer
   die Geraete, die vorher schon gefunden waren. Betroffen war genau das,
   wofuer die Zeile gedacht war: ein V2-Geraet ohne Token und jedes
   V3-Geraet, bei dem die Wolke keinen Token lieferte. Nachgemessen mit
   Kontrollfall.

3. Token und Schluessel landen NICHT mehr im Protokollverzeichnis.
   Bis 4.2.12 schrieb die Datei dieselben Geheimnisse zusaetzlich nach
   log/plugins/<Ordner>/devices.log - und der Reiter "Logdateien" zeigt
   ueber LBWeb::loglist_html() genau diesen Ordner an.
"""
import logging
import sys
import os
import configparser
import asyncio

# Der Import der Bibliothek steht in einem eigenen try: fehlt sie, ist der
# Klartext die einzige brauchbare Auskunft.
try:
    from msmart.discover import Discover
except Exception as _fehler:                       # noqa: BLE001
    print('Midea2Lox: msmart-ng ist nicht ladbar: %s' % _fehler)
    sys.exit(1)

#set path
cfg_path = 'REPLACELBPCONFIGDIR' #### REPLACE LBPCONFIGDIR ####
log_path = 'REPLACELBPLOGDIR' #### REPLACE LBPLOGDIR ####
home_path = 'REPLACELBHOMEDIR' #### REPLACE LBHOMEDIR ####

# Die Zuordnung Land -> Serverbereich von msmart-ng. Sie steht auch in
# mi_lib.php (mi_regionen); beide Seiten muessen dieselben Werte kennen.
# Ueber die Sprachgrenze hinweg gibt es keine gemeinsame Funktion - deshalb
# steht die Begruendung in beiden Dateien, und die Selbstpruefung im Reiter
# Test zeigt die Zuordnung an, die hier gilt.
REGION_ZUORDNUNG = {
    'DE': 'DE', 'AT': 'DE', 'CH': 'DE', 'NL': 'DE', 'IT': 'DE', 'ES': 'DE',
    'FR': 'DE', 'PL': 'DE', 'GB': 'DE', 'US': 'US', 'KR': 'KR',
}

## Protokoll einrichten - VOR dem ersten Lesen der Konfiguration.
##
## Bis 4.2.12 wurde DEBUG in Zeile 16 gelesen, also vor jedem
## Logging-Aufbau und ausserhalb jedes try. Fehlte midea2lox.cfg oder der
## Abschnitt [default], gab es einen rohen Traceback auf die Standardausgabe
## und keinen einzigen Protokolleintrag.
_LOGGER = logging.getLogger("discover.py")
try:
    os.makedirs(log_path, exist_ok=True)
except OSError:
    pass

cfg = configparser.RawConfigParser()
try:
    cfg.read(cfg_path + '/midea2lox.cfg')
except configparser.Error as fehler:
    print('Midea2Lox: midea2lox.cfg ist nicht lesbar: %s' % fehler)
    sys.exit(1)


def _wert(schluessel, vorgabe=''):
    try:
        return str(cfg.get('default', schluessel)).strip()
    except (configparser.NoOptionError, configparser.NoSectionError):
        return vorgabe


DEBUG = _wert('DEBUG', '0')
logging.basicConfig(
    level=logging.DEBUG if DEBUG == '1' else logging.INFO,
    filename=log_path + '/midea2lox.log',
    format='%(asctime)s %(name)-12s %(levelname)-8s %(message)s',
    datefmt='%d.%m %H:%M')

if sys.version_info < (3, 10):
    # msmart-ng verlangt ab 2026.4.0 mindestens Python 3.10; preroot.sh
    # weist eine aeltere Installation schon ab. Die Zahl steht hier ein
    # zweites Mal, weil dieses Skript auch von Hand aufgerufen wird.
    text = "Midea2Lox braucht mindestens Python 3.10, gefunden: %s" % (sys.version_info,)
    print(text)
    _LOGGER.error(text)
    sys.exit(1)


def geheim(wert):
    """Ein Geheimnis fuer das Protokoll unkenntlich machen."""
    s = str(wert or '')
    return (s[:4] + '...' + s[-4:]) if len(s) > 12 else ('*' * len(s))


def zugangsdaten():
    """Die Argumente fuer Discover.discover() - oder eine Beanstandung.

    Rueckgabe: (dict mit den Argumenten, Liste von Hinweistexten).
    """
    hinweise = []
    argumente = {}

    benutzer = _wert('MideaUser')
    kennwort = _wert('MideaPassword')
    if benutzer and kennwort:
        argumente['account'] = benutzer
        argumente['password'] = kennwort
        hinweise.append("Eigenes Midea-Konto wird benutzt: %s" % benutzer)
    elif benutzer or kennwort:
        # msmart wirft bei genau einem von beiden ValueError. Melden statt
        # zurechtbiegen - und ohne das Paar wird gar keines uebergeben.
        hinweise.append("Es ist nur %s eingetragen. Ein Konto braucht BEIDES - "
                        "es werden die Sammelkonten von msmart-ng benutzt."
                        % ('der Benutzer' if benutzer else 'das Kennwort'))
    else:
        hinweise.append("Kein eigenes Midea-Konto eingetragen - es werden die "
                        "Sammelkonten von msmart-ng benutzt. Das genuegt in "
                        "aller Regel.")

    region = _wert('region', 'DE').upper()
    server = REGION_ZUORDNUNG.get(region, '')
    if server:
        argumente['region'] = server
        hinweise.append("Region %s -> Serverbereich %s" % (region, server))
    else:
        # Nicht raten. Ohne Angabe nimmt msmart seine eigene Vorgabe (US);
        # das ist eine Auskunft, die der Anwender bekommen muss.
        hinweise.append("Die Region '%s' laesst sich keinem Serverbereich von "
                        "msmart-ng zuordnen (moeglich: DE, KR, US). Es wird "
                        "keine Region uebergeben; msmart benutzt dann seine "
                        "Vorgabe US. Bitte die Region im Plugin neu waehlen."
                        % region)
    return argumente, hinweise


def bestehende_bezeichnungen():
    """Die vom Anwender vergebenen Bezeichnungen retten.

    discover.py schreibt devices.cfg neu. Ohne diesen Schritt waere eine
    eigene Bezeichnung nach jeder Suche fort - ein Feld, das der Anwender
    pflegt und das Programm bei naechster Gelegenheit wegwirft.
    """
    aus = {}
    c = configparser.RawConfigParser()
    try:
        c.read(cfg_path + '/devices.cfg')
    except configparser.Error:
        return aus
    for ab in c.sections():
        if c.has_option(ab, 'bezeichnung'):
            aus[ab] = c.get(ab, 'bezeichnung')
    return aus


def rechte_setzen(pfad):
    """0600 - in der Datei stehen Token und Schluessel der Klimageraete."""
    try:
        os.chmod(pfad, 0o600)
    except OSError as fehler:
        _LOGGER.debug("Rechte auf %s nicht setzbar: %s", pfad, fehler)


async def discovery():
    argumente, hinweise = zugangsdaten()
    for h in hinweise:
        print(h)
        _LOGGER.info(h)

    _LOGGER.info("Sending Device Scan Broadcast...")
    print("Suche laeuft - das dauert bis zu einer Minute.")

    try:
        gefunden = await Discover.discover(**argumente)
    except ValueError as fehler:
        # Genau die Fehlerklasse, vor der die Zuordnung oben schuetzt.
        text = ("Die Geraetesuche wurde von msmart-ng abgewiesen: %s\n"
                "Bitte Region und Zugangsdaten im Reiter Einstellungen pruefen."
                % fehler)
        print(text)
        _LOGGER.error(text)
        return 1
    except Exception as fehler:
        text = "Die Geraetesuche ist gescheitert: %s" % fehler
        print(text)
        _LOGGER.error(text, exc_info=True)
        return 1

    if not gefunden:
        text = ("Es wurde kein Geraet gefunden. Haeufigste Ursachen: das "
                "Klimageraet haengt in einem anderen Netz als der LoxBerry, "
                "oder die Rundsendung wird vom Netzwerk nicht weitergeleitet.")
        print(text)
        _LOGGER.warning(text)
        return 0

    # EINMAL lesen, EINMAL schreiben.
    #
    # Bis 4.2.12 wurde die Datei je gefundenem Geraet neu eingelesen und
    # vollstaendig neu geschrieben, und die beiden Dateihandles wurden nie
    # geschlossen.
    bezeichnungen = bestehende_bezeichnungen()
    cfg_devices = configparser.RawConfigParser()
    try:
        cfg_devices.read(cfg_path + '/devices.cfg')
    except configparser.Error as fehler:
        _LOGGER.warning("devices.cfg war nicht lesbar (%s) - sie wird neu "
                        "angelegt.", fehler)
        cfg_devices = configparser.RawConfigParser()

    for device in gefunden:
        ab = 'Midea_' + str(device.id)
        _LOGGER.info("*** Found a device: %s", device)
        print("*** Found a device: %s" % device)
        if not cfg_devices.has_section(ab):
            cfg_devices.add_section(ab)
        cfg_devices.set(ab, "type", device.type.name)
        cfg_devices.set(ab, "supported", device.supported)
        cfg_devices.set(ab, "id", device.id)
        cfg_devices.set(ab, "ip", device.ip)
        cfg_devices.set(ab, "port", device.port)
        if ab in bezeichnungen:
            cfg_devices.set(ab, "bezeichnung", bezeichnungen[ab])

        if device.supported:
            try:
                await device.get_capabilities()
            except Exception as fehler:
                _LOGGER.warning("Faehigkeiten von %s nicht abrufbar: %s",
                                device.id, fehler)
            else:
                # Je Merkmal fuer sich: ein AttributeError - etwa weil eine
                # msmart-Fassung ein Merkmal umbenannt hat - darf nicht die
                # ganze Suche mitreissen.
                merkmale = (
                    ("modes", lambda d: [str(m.name.lower()) for m in d.supported_operation_modes]),
                    ("swingmodes", lambda d: [str(m.name.capitalize()) for m in d.supported_swing_modes]),
                    ("fanspeeds", lambda d: [str(m.name) for m in d.supported_fan_speeds]),
                    ("device min temperature", lambda d: d.min_target_temperature),
                    ("device max temperature", lambda d: d.max_target_temperature),
                    ("supports custom fanspeed", lambda d: d.supports_custom_fan_speed),
                    ("supports EcoMode", lambda d: d.supports_eco),
                    ("supports TurboMode", lambda d: d.supports_turbo),
                    ("supports Freeze Protection", lambda d: d.supports_freeze_protection),
                    ("supports Display control", lambda d: d.supports_display_control),
                    ("supports filter reminder", lambda d: d.supports_filter_reminder),
                    # Bis 4.2.12 stand hier "supports pruifier" - ein
                    # Schreibfehler, den niemand las, weil den Schluessel
                    # ohnehin niemand auswertet.
                    ("supports purifier", lambda d: d.supports_purifier),
                    # Der OEFFENTLICHE Name. Bis 4.2.12 stand hier
                    # d._supports_humidity, waehrend midea2lox.py die
                    # oeffentliche Fassung benutzt. Verschwindet das private
                    # Attribut, riss der AttributeError die ganze Suche mit.
                    ("supports humidity", lambda d: d.supports_humidity),
                    ("supports horizontal swing angle", lambda d: d.supports_horizontal_swing_angle),
                    ("supports vertical swing angle", lambda d: d.supports_vertical_swing_angle),
                    ("supports Rate selects", lambda d: [str(r.name) for r in d.supported_rate_selects]),
                    ("supports breeze_away", lambda d: d.supports_breeze_away),
                    ("supports breeze_mild", lambda d: d.supports_breeze_mild),
                    ("supports breezeless", lambda d: d.supports_breezeless),
                    ("supports ieco", lambda d: d.supports_ieco),
                    ("supports self clean", lambda d: d.supports_self_clean),
                )
                for name, holen in merkmale:
                    try:
                        cfg_devices.set(ab, name, holen(device))
                    except Exception as fehler:
                        _LOGGER.debug("Merkmal '%s' von %s nicht lesbar: %s",
                                      name, device.id, fehler)

        # Token und Schluessel.
        #
        # has_option() statt get(): ein frisch angelegter Abschnitt hat den
        # Schluessel nicht, und get() wirft dann NoOptionError. Genau daran
        # ist die Suche bis 4.2.12 gestorben - und zwar bei jedem V2-Geraet
        # und bei jedem V3-Geraet ohne Wolkenantwort.
        hat_alt = cfg_devices.has_option(ab, 'token') and cfg_devices.has_option(ab, 'key')
        if device.token and device.key:
            cfg_devices.set(ab, "token", device.token)
            cfg_devices.set(ab, "key", device.key)
            _LOGGER.info("Token/Schluessel fuer %s erneuert (%s / %s)",
                         device.id, geheim(device.token), geheim(device.key))
        elif hat_alt:
            # Das zuletzt bekannte Paar behalten, wenn die Wolke nichts
            # geliefert hat. Ohne diese Zeile waere ein einmal angemeldetes
            # V3-Geraet nach einer Suche ohne Internet stumm.
            _LOGGER.info("Keine neuen Zugangsmarken fuer %s - das bisherige "
                         "Paar bleibt stehen.", device.id)
        else:
            _LOGGER.info("Geraet %s ohne Token/Schluessel - Protokoll V2 oder "
                         "die Wolke hat nichts geliefert.", device.id)

    ziel = cfg_path + '/devices.cfg'
    tmp = ziel + '.tmp'
    try:
        # Rechte VOR dem Inhalt: in der Datei stehen Token und Schluessel.
        with open(tmp, 'w', encoding='utf-8') as f:
            rechte_setzen(tmp)
            cfg_devices.write(f)
        os.replace(tmp, ziel)
        rechte_setzen(ziel)
    except OSError as fehler:
        text = "devices.cfg konnte nicht geschrieben werden: %s" % fehler
        print(text)
        _LOGGER.error(text)
        return 1

    # In das Protokollverzeichnis geht NUR eine Liste ohne Geheimnisse.
    # Bis 4.2.12 landete dort die vollstaendige Datei samt Token und
    # Schluessel - und der Reiter "Logdateien" zeigt genau diesen Ordner an.
    try:
        with open(log_path + '/devices.log', 'w', encoding='utf-8') as f:
            f.write("# Uebersicht der gefundenen Geraete - OHNE Token und Schluessel.\n")
            for device in gefunden:
                f.write("Midea_%s  ip=%s  port=%s  typ=%s  unterstuetzt=%s\n"
                        % (device.id, device.ip, device.port,
                           getattr(device.type, 'name', '?'), device.supported))
    except OSError as fehler:
        _LOGGER.debug("devices.log nicht schreibbar: %s", fehler)

    text = "%d Geraet(e) gefunden und in devices.cfg gespeichert." % len(gefunden)
    print(text)
    _LOGGER.info(text)
    return 0


if __name__ == '__main__':
    try:
        sys.exit(asyncio.run(discovery()) or 0)
    except KeyboardInterrupt:
        sys.exit(1)
    except Exception as fehler:
        print('Midea2Lox: Geraetesuche abgebrochen: %s' % fehler)
        _LOGGER.error('Geraetesuche abgebrochen: %s', fehler, exc_info=True)
        sys.exit(1)
