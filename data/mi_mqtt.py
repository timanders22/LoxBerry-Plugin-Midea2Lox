#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - welche Themen zurueckbehalten werden, und das Abraeumen am Broker.

Aufruf (aus uninstall/uninstall):  mi_mqtt.py --mqtt-leeren
Seit 4.5.18 (Gruppe 15) auch die Fehlwerte (FEHLWERT) und die Tabelle der
MQTT-Befehle (MQTT_BEFEHLE, befehl_deuten) - eine Quelle fuer Dienst und Oberflaeche.
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
_GERAET_ID = re.compile(r'^([0-9]{10,19})/([a-z_]+)$')
# Ein Themenpraefix, wie die Oberflaeche es zulaesst (mi_wert_pruefen).
_PRAEFIX = re.compile(r'^[A-Za-z0-9_.\-]{1,48}(/[A-Za-z0-9_.\-]{1,48}){0,3}$')

# ---------------------------------------------------------------------------
# Fehlwerte (ab 4.5.18, Gruppe 15 - Entscheidungen Gruppe 3/4: ohne Vorzeichen -1,
# Temperaturen -999; Texte bleiben "-")
# ---------------------------------------------------------------------------
# Was ein ERFOLGREICHER Abruf nicht liefert, geht nicht mehr als "-" hinaus:
# ein Virtueller Eingang liest "-" als 0, und power_state 0 hiesse "aus" - eine
# Falschaussage. -1 sagt "keine Aussage", -999 "Temperatur fehlt". Texte
# (Betriebsart, Luefter, Schwenken, Winkel, Leistungsstufe, Breeze) bleiben
# "-": ein Virtueller Texteingang zeigt es ehrlich. Ein OFFLINE-Geraet sendet
# weiter nur <id>/online = 0 - die Zustaende bleiben stehen (begruendete
# Ausnahme, Entwurf Gruppe 15 Abschnitt 2.3, F-E5).
# Eine Zeile je Thema, keine Klammer in einem Kommentar: die Pruefzeile im
# Reiter Test (mi_fehlwert_probe() in mi_lib.php) liest diese Tabelle bis zur
# ersten schliessenden Klammer und haelt sie gegen mi_werte().
FEHLWERT = (
    'target_temperature=-999',
    'indoor_temperature=-999',
    'outdoor_temperature=-999',
    'operational_mode=-',
    'fan_speed=-',
    'swing_mode=-',
    'horizontal_swing_angle=-',
    'vertical_swing_angle=-',
    'rate_select=-',
    'breeze_mode=-',
)


def fehlwert_fuer(name):
    """Der Fehlwert eines Geraetethemas (Name ohne Nummer): -999, - oder -1."""
    for zeile in FEHLWERT:
        k, _gleich, w = zeile.partition('=')
        if k == name:
            return w
    return '-1'


# ---------------------------------------------------------------------------
# Befehle ueber MQTT (ab 4.5.18, Gruppe 15 - Entwurf 3.2, F-E1/F-E2)
# ---------------------------------------------------------------------------
# Thema <praefix>/befehl/<Geraetenummer>/<name>, Nutzlast wie die gemeinsame
# Befehlsbruecke (puls 1, schalter 0/1, auswahl ein Wort, zahl mit Punkt oder
# Komma, ganz). Jeder Befehl wird zu GENAU dem UDP-Wort, das Loxone bisher
# schickte, und laeuft durch dieselbe Warteschlange wie UDP (Gleichwert-Sperre,
# Handvorrang, Positivliste in send_to_midea). Spalten je Zeile, durch "|"
# getrennt: Name | Art | Werte | Abbildung | eingreifend.
#   Werte:     schalter 0,1 - puls 1 - auswahl die Woerter - zahl/ganz min..max
#   Abbildung: Wert=UDP-Wort, durch Leerzeichen getrennt; <v> = der Wert
#   eingreifend: 1 = nur mit dem zweiten Haken befehle_eingreifend_ein
# Eine Zeile je Befehl, keine Klammer in einem Kommentar: die Oberflaeche
# (mi_mqtt_befehle_python() in mi_lib.php) liest diese Tabelle zeilenweise und
# prueft, dass jede Abbildung in der Positivliste von mi_befehle() steht.
MQTT_BEFEHLE = (
    'ein|schalter|0,1|1=power.True 0=power.False|1',
    'betriebsart|auswahl|automatik,kuehlen,heizen,entfeuchten,lueften|automatik=ac.operational_mode_enum.auto kuehlen=ac.operational_mode_enum.cool heizen=ac.operational_mode_enum.heat entfeuchten=ac.operational_mode_enum.dry lueften=ac.operational_mode_enum.fan_only|1',
    'luefter|auswahl|automatik,leise,niedrig,mittel,hoch,hoechste|automatik=ac.fan_speed_enum.Auto leise=ac.fan_speed_enum.Silent niedrig=ac.fan_speed_enum.Low mittel=ac.fan_speed_enum.Medium hoch=ac.fan_speed_enum.High hoechste=ac.fan_speed_enum.Full|0',
    'schwenken|auswahl|aus,senkrecht,waagerecht,beide|aus=ac.swing_mode_enum.Off senkrecht=ac.swing_mode_enum.Vertical waagerecht=ac.swing_mode_enum.Horizontal beide=ac.swing_mode_enum.Both|0',
    'soll|zahl|5..35|<v>=temp.<v>|0',
    'feuchte|ganz|0..100|<v>=humidity.<v>|0',
    'spar|schalter|0,1|1=eco.True 0=eco.False|0',
    'schnell|schalter|0,1|1=turbo.True 0=turbo.False|0',
    'nacht|schalter|0,1|1=sleep.True 0=sleep.False|0',
    'fernbedienung|schalter|0,1|1=follow.True 0=follow.False|0',
    'frostschutz|schalter|0,1|1=freeze.True 0=freeze.False|1',
    'luftreinigung|schalter|0,1|1=purifier.True 0=purifier.False|0',
    'ton|schalter|0,1|1=tone.True 0=tone.False|0',
    'anzeige|puls|1|1=toggle_Display|0',
    'selbstreinigung|puls|1|1=toggle_self_clean|1',
    'abfragen|puls|1|1=status|0',
    'leistungsstufe|auswahl|aus,gear_50,gear_75,level_1,level_2,level_3,level_4,level_5|aus=rate_select.OFF gear_50=rate_select.GEAR_50 gear_75=rate_select.GEAR_75 level_1=rate_select.LEVEL_1 level_2=rate_select.LEVEL_2 level_3=rate_select.LEVEL_3 level_4=rate_select.LEVEL_4 level_5=rate_select.LEVEL_5|0',
)
NUTZLAST_MAX = 64
_ZAHL = re.compile(r'^-?[0-9]{1,9}(?:[.,][0-9]{1,3})?$')
_GANZ = re.compile(r'^-?[0-9]{1,9}$')


def mqtt_befehle():
    """{Name: (Art, Werte, {Wert: UDP-Wort}, eingreifend)} aus MQTT_BEFEHLE."""
    aus = {}
    for zeile in MQTT_BEFEHLE:
        name, art, werte, abbildung, eingreifend = zeile.split('|')
        karte = {}
        for paar in abbildung.split(' '):
            w, _gleich, wort = paar.partition('=')
            karte[w] = wort
        aus[name] = (art, werte, karte, eingreifend == '1')
    return aus


def _zahl_text(f):
    """22.0 -> '22', 22.5 -> '22.5' (wie bb_zahl_text der gemeinsamen Bruecke)."""
    t = ('%.3f' % f).rstrip('0').rstrip('.')
    return '0' if t in ('', '-0') else t


def befehl_deuten(name, roh, eingreifend_erlaubt):
    """Einen MQTT-Befehl formal pruefen und in das UDP-Wort uebersetzen.

    Rueckgabe (UDP-Wort, None) oder (None, Grund fuer die Quittung ohne
    'abgelehnt: '). Geschaltet wird hier nichts - das tut der Arbeiter.
    """
    tab = mqtt_befehle()
    if name not in tab:
        return None, 'unbekannter Befehl'
    art, werte, karte, eingreifend = tab[name]
    if not isinstance(roh, str):
        return None, 'Wert ungueltig (Text)'
    if len(roh.encode('utf-8', 'replace')) > NUTZLAST_MAX:
        return None, 'Wert ungueltig (hoechstens %d Byte)' % NUTZLAST_MAX
    w = roh.strip()
    if art == 'puls':
        if w != '1':
            return None, 'Wert ungueltig (1)'
        wort = karte['1']
    elif art == 'schalter':
        if w not in ('0', '1'):
            return None, 'Wert ungueltig (0 oder 1)'
        wort = karte[w]
    elif art == 'auswahl':
        k = w.lower()
        liste = werte.split(',')
        if k not in liste:
            return None, 'Wert ungueltig (%s)' % '|'.join(liste)
        wort = karte[k]
    elif art in ('zahl', 'ganz'):
        unten, _p, oben = werte.partition('..')
        unten, oben = float(unten), float(oben)
        if art == 'ganz':
            er = 'ganze Zahl %s bis %s' % (_zahl_text(unten), _zahl_text(oben))
            if not _GANZ.match(w) or not unten <= int(w) <= oben:
                return None, 'Wert ungueltig (%s)' % er
            text = str(int(w))
        else:
            er = 'Zahl %s bis %s' % (_zahl_text(unten), _zahl_text(oben))
            if not _ZAHL.match(w):
                return None, 'Wert ungueltig (%s)' % er
            f = float(w.replace(',', '.'))
            if not unten <= f <= oben:
                return None, 'Wert ungueltig (%s)' % er
            text = _zahl_text(f)
        wort = karte['<v>'].replace('<v>', text)
    else:
        return None, 'Wert ungueltig (unbekannte Art)'
    if eingreifend and not eingreifend_erlaubt:
        return None, 'zweiter Haken aus'
    return wort, None


def grund_ascii(g):
    """Grund fuer die Quittung: ASCII, hoechstens 120 Zeichen (wie bb_grund_ascii)."""
    g = re.sub(r'[^A-Za-z0-9 _:.,()/=+|-]', '_', str(g))[:120].strip()
    return g or 'ohne Grund'


def geraet_und_wert(unter):
    """(Geraetenummer, Name) eines Themas ohne Praefix - oder None (M2)."""
    m = _GERAET_ID.match(unter)
    return (m.group(1), m.group(2)) if m else None


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
    # Gruppe 15 (M14-Rest): ein versehentlich retained gesendeter Befehl gehoert dazu.
    if re.match(r'^befehl/[0-9]{10,19}/[a-z_]+$', unter):
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


# ---------------------------------------------------------------------------
# Die Client-Kennung (Verbesserungsbau 30.09.2026, a1)
# ---------------------------------------------------------------------------
# Bis 4.5.11 hiess der Dienst am Broker fest "Midea2Lox", das Lebenszeichen
# fest "Midea2Lox_leben". Zwei Anlagen am selben Broker (zwei LoxBerrys oder
# ein zweiter Pluginordner) meldeten sich damit unter DERSELBEN Kennung an.
# Der Broker trennt dann die bestehende Verbindung (MQTT 3.1.1, 3.1.4), deren
# Letzter Wille "disconnected" geht hinaus, paho verbindet neu und wirft
# seinerseits den anderen hinaus - ein Wechselspiel im Sekundentakt (am
# Attrappen-Broker gemessen, vb_md2_bau_skripte/proben/a1_*).
#
# Jetzt: Midea2Lox-<ordner>-<hostname kurz>[-<rolle>]-<hash>, hoechstens 64
# Zeichen. Der Hash (6 Hexziffern) geht ueber Ordner, vollen Hostnamen,
# /etc/machine-id und Rolle - zwei LoxBerrys mit dem Vorgabenamen "loxberry"
# unterscheiden sich damit trotzdem, und bei jedem Start ist es dieselbe
# Kennung.
#
# MQTT 3.1 laesst nur 23 Zeichen zu, und 3.1.1 garantiert nur Buchstaben und
# Ziffern bis 23 Zeichen. paho faellt bei CONNACK 1 selbst auf 3.1 zurueck;
# weist der Broker danach die lange Kennung ab (CONNACK 2 bzw. 133), nimmt
# der naechste Versuch die kurze Form: "Midea2Lox" + Hostname + Rolle, nur
# Buchstaben und Ziffern, auf 17 Zeichen gekuerzt, + Hash = 23 Zeichen.
#
# Was an der Kennung haengt, geprueft am Code (Bauliste "zu pruefen"): der
# Dienst verbindet mit clean_session (paho-Vorgabe bei 3.1.1, nirgends
# anders gesetzt) - der Broker haelt keine Sitzung und keine Abos ueber die
# Verbindung hinaus, und on_connect abonniert nach jedem Verbinden neu. Der
# Letzte Wille gehoert zur Verbindung (will_set vor connect), die retained
# Themen gehoeren zum Praefix. Ein Wechsel der Kennung verliert also nichts.
KENNUNG_LANG = 64
KENNUNG_KURZ = 23


def _maschinen_kennung():
    """/etc/machine-id (systemd) - oder leer, wenn es sie nicht gibt."""
    for datei in ('/etc/machine-id', '/var/lib/dbus/machine-id'):
        try:
            with open(datei, encoding='ascii', errors='replace') as f:
                wert = f.read().strip()
        except OSError:
            continue
        if wert:
            return wert
    return ''


def client_kennungen(rolle='', ordner=None, host=None, maschine=None):
    """(lange, kurze) Client-Kennung dieser Anlage fuer eine Rolle.

    rolle '' ist der Dienst, 'leben' das Lebenszeichen aus dem Cron-Lauf.
    ordner, host und maschine sind nur fuer die Probe da; ohne Angabe gelten
    der Pluginordner (aus cfg_path), socket.gethostname() und die
    Maschinenkennung.
    """
    import hashlib
    import socket
    if ordner is None:
        ordner = os.path.basename(str(cfg_path).rstrip('/')) or 'Midea2Lox'
    if host is None:
        try:
            host = socket.gethostname() or ''
        except OSError:
            host = ''
    if maschine is None:
        maschine = _maschinen_kennung()
    rest = hashlib.sha1(('%s|%s|%s|%s' % (ordner, host, maschine, rolle))
                        .encode('utf-8')).hexdigest()[:6]
    kurzname = str(host).split('.')[0]

    def sauber(s):
        return ''.join(c if (c.isascii() and (c.isalnum() or c in '-_')) else '_'
                       for c in str(s))

    kopf = 'Midea2Lox-'
    mitte = '-'.join(t for t in (sauber(ordner), sauber(kurzname), sauber(rolle)) if t)
    mitte = mitte[:KENNUNG_LANG - len(kopf) - 1 - len(rest)].rstrip('-_')
    lang = kopf + (mitte + '-' if mitte else '') + rest
    buchstaben = ''.join(c for c in kurzname + rolle if c.isascii() and c.isalnum())
    kurz = ('Midea2Lox' + buchstaben)[:KENNUNG_KURZ - len(rest)] + rest
    return lang, kurz


_LEEREN = {'n': 0}
_LEEREN_SCHLOSS = threading.Lock()


def broker_leeren(zugang, praefix, auswahl, warten=1.5, nach_loeschen=None, ersatz=b''):
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

    ersatz (ab 4.5.10, M2): statt der leeren Nutzlast wird dieser Wert
    retained gesendet ('-' fuer ein entferntes Geraet, Entscheidung 8). Ein
    Thema, das ihn schon traegt, gilt als erledigt.
    """
    erg = {'rc': 2, 'geleert': [], 'rest': [], 'grund': ''}
    ersatz_b = ersatz.encode('utf-8') if isinstance(ersatz, str) else bytes(ersatz or b'')
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
        if n.retain and n.payload and n.payload != ersatz_b and auswahl(n.topic):
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

    # a1 (Verbesserungsbau 30.09.2026): je AUFRUF eine eigene Kennung. Bis
    # 4.5.11 trugen alle Abraeum-Verbindungen eines Prozesses dieselbe
    # ('Midea2Lox-leeren-<pid>'); das Abraeumen der Altwerte und der Nachlauf
    # laufen im Dienst aber in zwei Faeden gleichzeitig - der Broker trennte
    # die eine Verbindung, sobald die andere kam (am Attrappen-Broker mit
    # Sitzungsuebernahme gemessen, proben/a1_vorher.txt: 1-2 Uebernahmen je
    # Start). Der Anfang 'Midea2Lox-leeren-' bleibt.
    with _LEEREN_SCHLOSS:
        _LEEREN['n'] += 1
        name = 'Midea2Lox-leeren-%d-%d' % (os.getpid(), _LEEREN['n'])
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
            info = k.publish(thema, ersatz_b, qos=1, retain=True)
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


# ---------------------------------------------------------------------------
# Die Praefixe, unter denen die Linie gesendet hat (ab 4.5.10, M1)
# ---------------------------------------------------------------------------
# Eine Datei NEBEN dem Konfigordner (config/plugins/<ordner>.mqtt_praefixe),
# damit sie ein Update uebersteht (purge_installation raeumt den Ordner ab,
# nicht den Nachbarn). Eine Zeile je Praefix, ohne Kommentar. Geschrieben vom
# Dienst (sein eigenes Praefix nach dem Verbinden) und von der Oberflaeche
# (das alte Praefix beim Wechsel); gelesen vom Dienst und von --mqtt-leeren.

def praefixe_datei(konfigordner):
    return str(konfigordner).rstrip('/') + '.mqtt_praefixe'


def praefixe_lesen(datei):
    aus = []
    try:
        with open(datei, encoding='utf-8') as f:
            for zeile in f:
                p = zeile.strip().strip('/')
                if p and _PRAEFIX.match(p) and p not in aus:
                    aus.append(p)
    except (OSError, UnicodeError):
        pass
    return aus


def praefixe_schreiben(datei, liste):
    """Unteilbar ueber eine Nebendatei mit PID; True bei Erfolg."""
    neu = '%s.neu.%d' % (datei, os.getpid())
    try:
        with open(neu, 'w', encoding='utf-8') as f:
            for p in liste:
                f.write(p + '\n')
        os.replace(neu, datei)
        return True
    except OSError:
        try:
            os.remove(neu)
        except OSError:
            pass
        return False


def praefixe_abraeumen(zugang, aktuell, datei):
    """Das aktuelle Praefix eintragen und jedes andere gemerkte abraeumen.

    Rueckgabe {"rc": 0|1|2, "meldungen": [...]}. Ein Praefix faellt erst aus
    der Liste, wenn es am Broker nachweislich leer ist.
    """
    erg = {'rc': 0, 'meldungen': []}
    liste = praefixe_lesen(datei)
    rest = [aktuell]
    for p in liste:
        if p == aktuell:
            continue

        def auswahl(thema, p=p):
            return thema.startswith(p + '/') and eigenes_thema(thema[len(p) + 1:])

        teil = broker_leeren(zugang, p, auswahl)
        if teil['rc'] == 0:
            erg['meldungen'].append('frueheres Praefix %s/: %d zurueckbehaltene Themen geleert '
                                    'und nachgelesen.' % (p, len(teil['geleert'])))
        else:
            rest.append(p)
            erg['rc'] = max(erg['rc'], teil['rc'])
            erg['meldungen'].append('frueheres Praefix %s/ nicht abgeraeumt - %s' % (
                p, teil['grund'] or ('%d Themen stehen noch' % len(teil['rest']))))
    if rest != liste and not praefixe_schreiben(datei, rest):
        erg['meldungen'].append('die Praefixliste %s liess sich nicht schreiben' % datei)
        erg['rc'] = max(erg['rc'], 1)
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
    # M1 (ab 4.5.10): auch jedes frueher benutzte Praefix aus der Liste.
    alle = [praefix] + [p for p in praefixe_lesen(praefixe_datei(cfg_path)) if p != praefix]
    gesamt = 0
    for p in alle:
        gesamt = max(gesamt, _praefix_leeren(zugang, p))
    return gesamt


def _praefix_leeren(zugang, praefix):
    """Ein Praefix fuer die Deinstallation leeren; Rueckgabe wie mqtt_leeren()."""
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
