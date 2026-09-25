#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - welche Themen zurueckbehalten werden, und das Abraeumen am Broker.

Aufruf (aus uninstall/uninstall):  mi_mqtt.py --mqtt-leeren
Sonst ein Modul: midea2lox.py holt sich daraus retain_fuer(),
altlast_thema(), altlast_kennung() und broker_leeren(). Beim Import tut es
nichts - kein Protokoll, keine Verbindung, keine Datei.

WARUM EINE POSITIVLISTE

Bis 4.5.8 stand im Dienst eine Liste der Themen OHNE Retain; alles andere
ging retained hinaus. Ein neues Thema wurde damit still zurueckbehalten,
ohne dass es jemand entschieden hatte (Bestand-2026-09-18/klasse-E,
Dienstzustand-retained_2026-09-19.md, Abschnitt 6; Vorbild AWM-Abfuhr
1.4.13). Jetzt steht hier, was retained geht - alles andere ist fluechtig.

Retained sind nach Regeln/07 Abschnitt 3 nur Aussagen ueber das GERAET
(an/aus, Betriebsart, Sollwert, Komfortschalter, Filterhinweis) und der
Letzte Wille connection/status. Nicht retained sind:
  * die Messwerte mit Zeitbezug (Temperaturen, Feuchte, Energie, Leistung);
  * online eines Geraets. Prueffrage "wer stellt es fest?": msmart im
    Dienstprozess aus der eigenen Abfrage, und der Dienst setzt es selbst
    (midea2lox.py, device._online = False nach gescheitertem authenticate()
    und refresh()). Das ist ein Ausfallmerker der Geraeteschnittstelle, also
    eine Aussage des Dienstes - retained stuende nach dem Tod des Dienstes
    fuer immer die letzte 1 da;
  * das Lebenszeichen status/* und die Automatik automatik/*: grund und
    gesperrt tragen eine Restzeit ("seit N s", "noch N min"), aktiv und
    geraete sagen, was die Automatik DES DIENSTES gerade tut. Beides ist
    falsch, sobald der Dienst steht.

DER LETZTE WILLE

connection/status ist das Paar aus Regeln/07 (a)-(c): 'connected' bei jedem
Verbinden (on_connect, auch nach einer Neuverbindung), 'disconnected' als
Letzter Wille, beide retained - und die Deinstallation raeumt das Thema ab
(--mqtt-leeren, unten). Die Werte bleiben Text und nicht 1/0: an beiden
Woertern haengen bestehende Loxone-Konfigurationen.
"""
import configparser
import json
import os
import re
import sys
import threading
import time

cfg_path = 'REPLACELBPCONFIGDIR' #### REPLACE LBPCONFIGDIR ####
home_path = 'REPLACELBHOMEDIR' #### REPLACE LBHOMEDIR ####

# Retained - und nur diese. Eine Zeile je Wert, keine Klammer in einem
# Kommentar: die Selbstpruefung im Reiter Test (mi_retain_probe() in
# webfrontend/htmlauth/mi_lib.php) liest diese Liste bis zur ersten
# schliessenden Klammer und haelt sie gegen mi_mit_retain().
MIT_RETAIN = (
    'connection/status',
    'power_state',
    'audible_feedback',
    'target_temperature',
    'operational_mode',
    'fan_speed',
    'swing_mode',
    'eco_mode',
    'turbo_mode',
    'display_on',
    'target_humidity',
    'filter_alert',
    'horizontal_swing_angle',
    'vertical_swing_angle',
    'freeze_protection_mode',
    'sleep_mode',
    'follow_me',
    'purifier',
    'self_clean_active',
    'rate_select',
    'breeze_mode',
    'ieco',
)

# Themen, die eine FRUEHERE Fassung retained gesendet hat und die heute
# fluechtig hinausgehen. Ein Altwert verschwindet nicht dadurch, dass niemand
# ihn mehr sendet; er wird einmal mit leerer Nutzlast geloescht.
#   bis 4.5.3: das Lebenszeichen und die sechs Messwerte (alles ging retained)
#   bis 4.5.8: online und die vier Themen der Automatik
DIENST_ALTLAST = (
    'status/ts', 'status/zaehler', 'status/ok', 'status/dienst',
    'automatik/aktiv', 'automatik/grund', 'automatik/gesperrt', 'automatik/geraete',
)
GERAET_ALTLAST = (
    'online', 'indoor_temperature', 'outdoor_temperature', 'indoor_humidity',
    'total_energy_usage', 'current_energy_usage', 'real_time_power_usage',
)

# Geraetenummern schreibt der Dienst mit 10 bis 19 Ziffern (send_to_midea).
_GERAET = re.compile(r'^[0-9]{10,19}/([a-z_]+)$')


def _geraetewert(unter):
    """Der Name hinter der Geraetenummer - oder None."""
    m = _GERAET.match(unter)
    return m.group(1) if m else None


def retain_fuer(thema):
    """Geht dieses Thema (ohne Praefix) retained hinaus?

    Entschieden am Namen: mit Geraetenummer davor ("123456789012/power_state")
    am hinteren Teil, sonst am ganzen Thema. Die Oberflaeche fragt auch mit
    dem blossen Namen ("power_state") - dasselbe Ergebnis.
    """
    t = str(thema).strip('/')
    if t in MIT_RETAIN:
        return True
    name = _geraetewert(t)
    return name is not None and name in MIT_RETAIN


def altlast_thema(unter):
    """Stand dieses Thema (ohne Praefix) frueher retained und heute nicht?"""
    if unter in DIENST_ALTLAST:
        return True
    return _geraetewert(unter) in GERAET_ALTLAST


def eigenes_thema(unter):
    """Sendet diese Linie das Thema (ohne Praefix) - heute oder frueher?

    Fuer die Deinstallation: geleert wird nur, was hierher gehoert. Ein
    fremdes Thema unter demselben Praefix bleibt stehen.
    """
    if unter in MIT_RETAIN or unter in DIENST_ALTLAST:
        return True
    name = _geraetewert(unter)
    return name is not None and (name in MIT_RETAIN or name in GERAET_ALTLAST)


def altlast_kennung(praefix):
    """Der Inhalt des Merkers nach gelungenem Abraeumen.

    Praefix UND Themenliste: ein neuer Praefix oder eine laengere Liste
    ergeben eine andere Kennung, und das Abraeumen laeuft noch einmal. Eine
    Vorfassung hat keinen solchen Merker und kann nichts vortaeuschen.
    """
    return 'v1|%s|%s|<id>/%s' % (praefix, ','.join(DIENST_ALTLAST),
                                 ',<id>/'.join(GERAET_ALTLAST))


def zugangsdaten(home):
    """Broker aus config/system/general.json - dieselben Felder wie der Dienst."""
    try:
        with open(os.path.join(home, 'config', 'system', 'general.json'),
                  encoding='utf-8') as f:
            m = json.load(f)['Mqtt']
        return {'host': str(m['Brokerhost']), 'port': int(m['Brokerport']),
                'user': str(m.get('Brokeruser') or ''),
                'pass': str(m.get('Brokerpass') or '')}
    except (OSError, ValueError, KeyError, TypeError):
        return None


# Klartext zu den Rueckgabecodes eines CONNACK (MQTT 3.1.1; paho 2.x meldet
# dieselben Faelle als 132-136 - der Dienst fuehrt beide in CONNACK_KLARTEXT).
CONNACK_TEXT = {1: 'Protokollfassung abgelehnt', 2: 'Client-Kennung abgelehnt',
                3: 'Broker nicht verfuegbar', 4: 'Benutzername oder Kennwort falsch',
                5: 'nicht berechtigt', 132: 'Protokollfassung abgelehnt',
                133: 'Client-Kennung abgelehnt', 134: 'Benutzername oder Kennwort falsch',
                135: 'nicht berechtigt', 136: 'Broker nicht verfuegbar'}


def broker_leeren(zugang, praefix, auswahl, warten=1.5, nach_loeschen=None):
    """Behaltene Themen unter <praefix>/ am Broker loeschen und NACHLESEN.

    Bauart wie broker_leeren() in APC-UPS 1.2.13 (apc_common.py), dort in WSL
    mit echtem paho 1.6.1 gegen einen eigenen Broker gemessen:
      1. geloescht wird nur, was WIRKLICH behalten im Broker liegt - gefunden
         ueber ein Abonnement - und was auswahl(thema) freigibt;
      2. nach_loeschen(themen) laeuft unmittelbar nach den Loeschungen, noch
         vor dem Nachlesen: dort schickt der Dienst den gueltigen Wert
         hinterher (das MQTT-Gateway reicht eine Loeschung als leeren Wert an
         den Miniserver weiter);
      3. danach ein zweites Abonnement: was dann noch behalten ankommt, ist
         stehengeblieben.
    CONNACK ungleich 0 und ein SUBACK mit 0x80 heissen "nicht zu fragen" -
    nie "nichts behalten" (Muster 11 der Nachlese, Beschattungswaechter
    0.9.21). Rueckgabe {"rc", "geleert", "rest", "grund"}: rc 0 = nichts
    (mehr) behalten, 1 = nach dem Loeschen stand noch etwas, 2 = nicht zu
    fragen.
    """
    erg = {'rc': 2, 'geleert': [], 'rest': [], 'grund': ''}
    praefix = str(praefix or '').strip('/')
    if not praefix or '#' in praefix or '+' in praefix:
        erg['grund'] = "das Themenpraefix '%s' taugt nicht fuer ein Abonnement" % praefix
        return erg
    if not zugang:
        erg['grund'] = 'kein MQTT-Broker in general.json'
        return erg
    try:
        import paho.mqtt.client as mqtt
    except ImportError:
        erg['grund'] = 'das Paket paho-mqtt fehlt'
        return erg
    wo = '%s:%s' % (zugang['host'], zugang['port'])
    gesehen = set()
    angemeldet = threading.Event()
    code = {'wert': None}
    subacks = {}

    def bei_verbindung(_k, _d, _f, *rest):
        # paho 1.x und VERSION1: rc als Zahl; VERSION2: ReasonCode mit .value
        try:
            code['wert'] = int(getattr(rest[0], 'value', rest[0]) or 0) if rest else 0
        except (TypeError, ValueError):
            code['wert'] = 0
        angemeldet.set()

    def bei_nachricht(_k, _d, n):
        # Nur BEHALTENES mit Inhalt: ein live gesendeter Wert ist keine
        # Altlast, und ein leeres Thema ist schon geloescht.
        if n.retain and n.payload and auswahl(n.topic):
            gesehen.add(n.topic)

    def bei_abo(_k, _d, mid, codes, *_rest):
        werte = []
        try:
            for c in (codes or ()):
                werte.append(int(getattr(c, 'value', c)))
        except (TypeError, ValueError):
            werte = [0x80]
        subacks[mid] = werte or [0x80]

    def abonnieren():
        """praefix/# abonnieren und den SUBACK abwarten: '' = bestaetigt."""
        erg_sub = k.subscribe(praefix + '/#')
        try:
            rc_sub, mid = int(erg_sub[0]), erg_sub[1]
        except (TypeError, ValueError, IndexError):
            return 'das Abonnement liess sich nicht absenden (%r)' % (erg_sub,)
        if rc_sub != 0:
            return 'das Abonnement liess sich nicht absenden (rc %s)' % rc_sub
        ende = time.time() + 10
        while mid not in subacks and time.time() < ende:
            time.sleep(0.05)
        if mid not in subacks:
            return 'der Broker %s hat das Abonnement nicht bestaetigt (kein SUBACK)' % wo
        schlecht = [w for w in subacks[mid] if w >= 0x80]
        if schlecht:
            return ("der Broker %s verweigert das Lesen von '%s/#' (SUBACK 0x%02X)"
                    % (wo, praefix, schlecht[0]))
        return ''

    name = 'Midea2Lox-leeren-%d' % os.getpid()
    k = None
    for art in ('VERSION2', 'VERSION1'):
        api = getattr(getattr(mqtt, 'CallbackAPIVersion', None), art, None)
        if api is None:
            continue
        try:
            k = mqtt.Client(api, client_id=name)
            break
        except (AttributeError, TypeError, ValueError):
            k = None
    if k is None:
        k = mqtt.Client(client_id=name)     # paho-mqtt 1.x
    k.on_connect = bei_verbindung
    k.on_message = bei_nachricht
    k.on_subscribe = bei_abo
    if zugang.get('user'):
        k.username_pw_set(zugang['user'], zugang.get('pass') or None)
    try:
        k.connect(zugang['host'], int(zugang['port']), 30)
    except Exception as fehler:  # noqa: BLE001
        erg['grund'] = 'der Broker %s ist nicht erreichbar (%s: %s)' % (
            wo, type(fehler).__name__, fehler)
        return erg
    k.loop_start()
    try:
        if not angemeldet.wait(10):
            erg['grund'] = 'der Broker %s hat auf die Verbindung nicht geantwortet' % wo
            return erg
        if code['wert']:
            erg['grund'] = 'der Broker %s hat die Anmeldung abgewiesen (CONNACK %s: %s)' % (
                wo, code['wert'], CONNACK_TEXT.get(code['wert'], 'unbekannter Grund'))
            return erg
        grund = abonnieren()
        if grund:
            erg['grund'] = grund
            return erg
        time.sleep(warten)
        k.unsubscribe(praefix + '/#')
        zu_leeren = sorted(gesehen)
        for thema in zu_leeren:
            info = k.publish(thema, b'', qos=1, retain=True)
            try:
                info.wait_for_publish(5)
            except TypeError:           # paho vor 1.6 kennt kein timeout
                info.wait_for_publish()
        erg['geleert'] = zu_leeren
        if nach_loeschen is not None and zu_leeren:
            try:
                nach_loeschen(zu_leeren)
            except Exception:  # noqa: BLE001
                pass
        # NACHLESEN: ein neues Abonnement bekommt alles, was noch behalten ist.
        gesehen.clear()
        grund = abonnieren()
        if grund:
            # Geloescht ist dann schon; bestaetigt ist es nicht.
            erg['grund'] = 'Nachlesen nicht moeglich - ' + grund
            return erg
        time.sleep(warten)
        erg['rest'] = sorted(gesehen)
        erg['rc'] = 1 if erg['rest'] else 0
    except Exception as fehler:  # noqa: BLE001
        erg['rc'] = 2
        erg['grund'] = 'das Loeschen am Broker %s scheiterte (%s: %s)' % (
            wo, type(fehler).__name__, fehler)
    finally:
        # ERST abmelden, DANN den Netzstrang anhalten.
        try:
            k.disconnect()
        except Exception:  # noqa: BLE001
            pass
        k.loop_stop()
    return erg


def mqtt_leeren():
    """Die retained Themen dieser Linie abraeumen - fuer die Deinstallation.

    Bis 4.5.8 raeumte uninstall nichts ab: connection/status blieb mit dem
    Letzten Willen 'disconnected' fuer immer im Broker stehen, dazu jeder
    Geraetezustand (Regeln/07, Letzter Wille (c); in WSL gemessen,
    Pruefung-Midea2Lox-4.5.9, Fall U1). Geleert wird unter dem Praefix aus
    midea2lox.cfg genau das, was eigenes_thema() nennt.
    Rueckgabe 0 geleert oder nichts behalten, 1 es blieb etwas stehen,
    2 nicht zu fragen oder nicht zulaessig.
    """
    # Aus einem ausgepackten Archiv (Platzhalter nicht ersetzt, also kein
    # absoluter Pfad) gibt es keine Anlage, deren Broker man leeren duerfte.
    if not os.path.isabs(home_path) or not os.path.isabs(cfg_path):
        print('<WARNING> MQTT: mi_mqtt.py liegt nicht in einer Installation - '
              'es wurde nichts geleert.')
        return 2
    cfg = configparser.RawConfigParser()
    try:
        cfg.read(os.path.join(cfg_path, 'midea2lox.cfg'))
        praefix = cfg.get('default', 'mqtt_praefix').strip().strip('/')
    except (configparser.Error, OSError):
        praefix = ''
    praefix = praefix or 'Midea2Lox'
    zugang = zugangsdaten(home_path)

    def auswahl(thema):
        return thema.startswith(praefix + '/') and eigenes_thema(thema[len(praefix) + 1:])

    erg = broker_leeren(zugang, praefix, auswahl, warten=3.0)
    if erg['rc'] == 2:
        print('<WARNING> MQTT: die zurueckbehaltenen Themen unter %s/ wurden nicht '
              'geleert - %s. Von Hand: mosquitto_pub -r -n -t <thema>' % (praefix, erg['grund']))
        return 2
    if erg['rc'] == 1:
        print('<WARNING> MQTT: %d von %d zurueckbehaltenen Themen unter %s/ stehen noch '
              'im Broker, zum Beispiel %s.' % (len(erg['rest']), len(erg['geleert']),
                                              praefix, erg['rest'][0]))
        return 1
    if erg['geleert']:
        print('<OK> MQTT: %d zurueckbehaltene Themen unter %s/ geleert und nachgelesen.'
              % (len(erg['geleert']), praefix))
    else:
        print('<INFO> MQTT: unter %s/ lag nichts zurueckbehalten - nichts zu leeren.' % praefix)
    return 0


if __name__ == '__main__':
    if sys.argv[1:] == ['--mqtt-leeren']:
        sys.exit(mqtt_leeren())
    print('Aufruf: mi_mqtt.py --mqtt-leeren')
    sys.exit(2)
