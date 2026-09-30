#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - Dauerlaeufer.

Nimmt Befehle vom Miniserver per UDP entgegen, schickt sie an die
Klimageraete im lokalen Netz und meldet deren Zustand zurueck - per MQTT,
sonst per HTTP an virtuelle Eingaenge.

WAS SICH IN 4.3.0 AM AUFBAU GEAENDERT HAT

Bis 4.2.12 war die asyncio-Fassade dekorativ: soc.recvfrom() war ein
blockierender Aufruf mitten in einer Koroutine, und time.sleep(5) stand
dreimal darin. Alles lief streng nacheinander, und waehrend eines
Wiederholversuchs nahm der Dienst bis zu zwanzig Sekunden lang keine Pakete
mehr an - sie liefen in den Empfangspuffer des Sockets und wurden nach
dessen Ueberlauf ohne jede Meldung verworfen.

Jetzt haengt der Empfang an einem echten Datagramm-Endpunkt und legt die
Pakete in eine Warteschlange mit harter Obergrenze. Verarbeitet werden sie
von einem Arbeiter; dazu kommen zwei Koroutinen fuer Herzschlag und
Abfragetakt.

DIE VERARBEITUNG BLEIBT ABSICHTLICH SERIELL. Ein globales Schloss haelt
genau die Reihenfolge ein, die bis 4.2.12 galt. Echte Nebenlaeufigkeit
mehrerer Geraete waere moeglich, laesst sich hier aber nicht messen - kein
Klimageraet, keine msmart-Installation. Wer sie einfuehrt, misst vorher, ob
zwei gleichzeitige refresh() auf DEMSELBEN Geraet einander vertragen.
"""
import logging
import sys
import os
import threading
import time

#set path
cfg_path = 'REPLACELBPCONFIGDIR' #### REPLACE LBPCONFIGDIR ####
log_path = 'REPLACELBPLOGDIR' #### REPLACE LBPLOGDIR ####
home_path = 'REPLACELBHOMEDIR' #### REPLACE LBHOMEDIR ####
data_path = 'REPLACELBPDATADIR' #### REPLACE LBPDATADIR ####

# ===========================================================================
# Umwandlung eingehender UDP-Woerter - OHNE eval()
# ===========================================================================
#
# Bis 4.0.0 wurden die Werte aus dem UDP-Paket mit eval() in Python-Objekte
# verwandelt, an 27 Stellen. Die meisten davon standen hinter einer
# Weissliste ("power.True"/"power.False" und aehnlich) und waren damit
# ungefaehrlich. DREI standen es nicht:
#
#     Zeile 317  elif eachArg.split(".")[0] == "humidity":
#                    device.target_humidity = eval(eachArg.split(".")[1])
#     Zeile 323  ... == "h_swing_angle":
#                    ... = eval('ac.SwingAngle.' + eachArg.split(".")[1])
#     Zeile 329  ... == "v_swing_angle":  (dasselbe)
#
# Geprueft wurde jeweils nur das Wort VOR dem ersten Punkt. Alles dahinter
# ging ungefiltert in eval(). Nachgestellt mit einer Attrappe: beide Muster
# haben Code ausgefuehrt.
#
#     humidity.exec(chr(105)+chr(109)+...)              -> ausgefuehrt
#     h_swing_angle.A if 0 else exec(chr(105)+...)      -> ausgefuehrt
#
# Punkte im Schadcode braucht es nicht: chr() setzt jede Zeichenkette
# zusammen. Beim zweiten Muster sorgt "A if 0 else ..." dafuer, dass der
# unbrauchbare Vorspann 'ac.SwingAngle.A' gar nicht erst ausgewertet wird.
#
# Der Socket horcht auf allen Adressen des LoxBerry. Wer ein UDP-Paket
# dorthin schicken kann, konnte also Befehle mit den Rechten des Benutzers
# loxberry ausfuehren.
#
# Ersetzt durch feste Zuordnungstabellen und Typumwandlung. Was nicht in der
# Tabelle steht, wird protokolliert und verworfen - nicht ausgefuehrt.

def mi_bool(wort):
    """'True'/'False' aus dem Paket in einen echten Wahrheitswert.

    Rueckgabe None, wenn es weder das eine noch das andere ist - der
    Aufrufer verwirft den Befehl dann, statt zu raten.
    """
    w = str(wort).strip().lower()
    if w in ('true', '1', 'on', 'yes'):
        return True
    if w in ('false', '0', 'off', 'no'):
        return False
    return None


def mi_enum(klasse, name):
    """Einen Aufzaehlungswert ueber seinen Namen holen, ohne eval().

    getattr() statt eval(): getattr kann nur ein Attribut nachschlagen, es
    kann keinen Ausdruck auswerten. Selbst ein boesartiger Name fuehrt
    hoechstens zu None, niemals zu ausgefuehrtem Code.
    """
    if not name:
        return None
    n = str(name).strip().upper()
    # Nur Buchstaben, Ziffern und Unterstrich - alles andere ist kein
    # Aufzaehlungsname und hat hier nichts zu suchen.
    if not n.replace('_', '').isalnum():
        return None
    return getattr(klasse, n, None)


# Zuordnung Loxone-Wort -> Aufzaehlungswert. Die Tabelle ersetzt das
# frueher per eval() aufgeloeste support_msmart_ng-Woerterbuch, das
# Zeichenketten wie 'ac.OperationalMode.AUTO' enthielt.
def mi_tabellen():
    return {
        'operational_mode': {
            'ac.operational_mode_enum.auto':     'AUTO',
            'ac.operational_mode_enum.cool':     'COOL',
            'ac.operational_mode_enum.heat':     'HEAT',
            'ac.operational_mode_enum.dry':      'DRY',
            'ac.operational_mode_enum.fan_only': 'FAN_ONLY',
        },
        'fan_speed': {
            'ac.fan_speed_enum.Auto':   'AUTO',
            # msmart-ng kennt kein FULL - die hoechste Stufe heisst MAX.
            'ac.fan_speed_enum.Full':   'MAX',
            'ac.fan_speed_enum.High':   'HIGH',
            'ac.fan_speed_enum.Medium': 'MEDIUM',
            'ac.fan_speed_enum.Low':    'LOW',
            'ac.fan_speed_enum.Silent': 'SILENT',
        },
        'swing_mode': {
            'ac.swing_mode_enum.Off':        'OFF',
            'ac.swing_mode_enum.Vertical':   'VERTICAL',
            'ac.swing_mode_enum.Horizontal': 'HORIZONTAL',
            'ac.swing_mode_enum.Both':       'BOTH',
        },
    }


def mi_rueck(gruppe):
    """Aufzaehlungsname -> Loxone-Wort, aus DERSELBEN Tabelle gebildet.

    Bis 4.2.12 baute der Rueckweg das Wort aus dem Aufzaehlungsnamen
    zusammen (.name.capitalize()). Bei fuenf von sechs Luefterstufen ging
    das gut; bei der sechsten nicht, und zwar genau dort, wo die Hintabelle
    bewusst umbenennt:

        Loxone sendet  ac.fan_speed_enum.Full
        intern         MAX
        Loxone bekommt fan_speed_enum.Max      <- kennt kein Eingabewort

    Ein Statustext-Baustein, der auf "Full" hoert, blieb damit stumm. Aus
    einer Tabelle gebildet kann das nicht mehr auseinanderlaufen.
    """
    aus = {}
    for wort, name in mi_tabellen()[gruppe].items():
        aus[name] = wort.rsplit('.', 1)[1]
    return aus


def mi_wort(gruppe, aufzaehlung):
    """Das Loxone-Wort zu einem Aufzaehlungswert - oder None."""
    if aufzaehlung is None:
        return None
    name = getattr(aufzaehlung, 'name', None)
    if name is None:
        return None
    return mi_rueck(gruppe).get(name, name.capitalize())


# ===========================================================================
# Abonnierte Werte (ab 4.5.0)
# ===========================================================================
#
# paho liefert on_message im NETZWERKFADEN aus, die Ereignisschleife laeuft
# daneben. Zwischen beiden liegt genau dieser Speicher: je Thema ein Wert und
# der Zeitpunkt, zu dem er ankam, unter einem Schloss.
#
# Bewusst KEIN run_coroutine_threadsafe. Es sind Zahlen, keine Ablaeufe; wer
# aus einem fremden Faden eine Koroutine in die Schleife wirft, muss deren
# Lebensdauer mitverwalten, und dafuer gibt es hier keinen Grund.
#
# Der Zeitpunkt ist kein Beiwerk. Ein Preissignal, das seit Stunden nicht
# nachgekommen ist, ist kein Signal - es ist eine Leiche. Eine Automatik, die
# darauf weiterlaeuft, waere schlimmer als gar keine.

ABO_SCHLOSS = threading.Lock()
ABO_WERTE = {}          # Thema -> (roher Text, Zeitpunkt des Eintreffens | None)

# C2 (Durchgang 30.09.2026): ein Wert, der beim Abonnieren aus dem Speicher
# des Brokers kommt (Retain-Merkmal gesetzt), hat KEIN bekanntes Alter. Bis
# 4.5.9 bekam er den Zeitpunkt des Eintreffens - ein beliebig alter Wert
# einer Quelle, die laengst nicht mehr sendet, galt damit nach jedem Start
# und jedem Wiederverbinden bis auf_max_alter als frisch, und die Automatik
# griff (in WSL gemessen, Bericht code Befund 2: "Automatik greift ... 21.0
# -> 19.0" ohne einen einzigen frischen Wert). Jetzt traegt er None als
# Zeitpunkt und zaehlt nicht, bis die Quelle wieder sendet: live
# weitergereichte Nachrichten kommen nach MQTT 3.1.1 immer OHNE
# Retain-Merkmal an, auch wenn der Sender retained schickt.


def abo_merken(thema, text, zurueckbehalten=False):
    with ABO_SCHLOSS:
        ABO_WERTE[thema] = (text, None if zurueckbehalten else time.time())


def abo_stand():
    """Eine Abschrift des ganzen Speichers - fuer Anzeige und Protokoll."""
    with ABO_SCHLOSS:
        return dict(ABO_WERTE)


def abo_holen(thema, hoechstalter):
    """(Text, Alter) - oder (None, Alter) wenn zu alt, (None, None) wenn nie,
    (None, -1) wenn bisher nur ein zurueckbehaltener Wert unbekannten Alters
    kam (C2).

    Die Nein-Faelle sind ABSICHTLICH unterscheidbar: "nie etwas bekommen" ist
    ein Einrichtungsfehler, "zu alt" ein Betriebsfehler, "nur ein alter Wert
    aus dem Broker" heisst: die Quelle sendet gerade nicht.
    """
    if not thema:
        return None, None
    with ABO_SCHLOSS:
        eintrag = ABO_WERTE.get(thema)
    if not eintrag:
        return None, None
    text, wann = eintrag
    if wann is None:
        return None, -1
    alter = time.time() - wann
    if hoechstalter > 0 and alter > hoechstalter:
        return None, alter
    return text, alter


def abo_zahl(text):
    """Aus dem Rohtext eine Zahl - oder None. Wird NICHT zurechtgebogen.

    "1", "1.0", "1,0" und " 1 " ergeben 1.0; "ja", "" und "an" ergeben None.
    Ein Thema, das Text statt Zahl liefert, ist ein falsch eingerichtetes
    Thema - und das gehoert gemeldet, nicht geraten.
    """
    if text is None:
        return None
    try:
        return float(str(text).strip().replace(',', '.'))
    except (TypeError, ValueError):
        return None


# ===========================================================================
# Wer darf Befehle schicken? (ab 4.5.10, C1)
# ===========================================================================
#
# Bis 4.5.9 pruefte datagram_received nur die Laenge. Ein Datagramm von
# 127.0.0.2 bei einem Miniserver auf 192.0.2.10 wurde ausgefuehrt (in WSL
# gemessen, Bericht code Befund 1): jeder Rechner im Netz konnte die
# Klimageraete schalten und die Automatik fuer auto_sperrzeit sperren.
# Entscheidung 8 vom 30.09.2026: angenommen wird nur, was von den
# Miniserver-Adressen aus general.json und von 127.0.0.1 kommt. Bauform wie
# Chromecast4lox 1.3.13 (miniserver_adressen, fremd_melden).
#
# Dazu die eigene Adresse aus LoxberryIP: der Knopf "Senden" im Reiter Test
# schickt an LoxberryIP, und ein Paket vom LoxBerry an seine eigene
# LAN-Adresse traegt diese als Absender, nicht 127.0.0.1. Von aussen kommt
# ein solches Paket nicht an: Linux verwirft auf einer Netzschnittstelle
# Pakete mit einer eigenen Adresse als Absender (accept_local=0).

_FREMDE_ABSENDER = {}   # Adresse -> [zuletzt gemeldet, seither verworfen]


def miniserver_adressen():
    """127.0.0.1, die eigene Adresse und alle Miniserver-Adressen.

    Gelesen aus general.json (Miniserver.*.Ipaddress) und aus den
    MINISERVER-Abschnitten der general.cfg, die cfg oben schon eingelesen
    hat. Ein Name statt einer Adresse wird EINMAL aufgeloest; laesst er sich
    nicht aufloesen, fehlt er in der Liste, und das steht im Protokoll.
    """
    roh = set()
    try:
        with open(home_path + '/config/system/general.json', encoding='utf-8') as f:
            allgemein = json.load(f)
        for _nr, ms in (allgemein.get('Miniserver') or {}).items():
            if isinstance(ms, dict):
                for schluessel in ('Ipaddress', 'IPAddress', 'ipaddress'):
                    if str(ms.get(schluessel) or '').strip():
                        roh.add(str(ms.get(schluessel)).strip())
                        break
    except (OSError, ValueError, AttributeError) as fehler:
        _LOGGER.warning("general.json nicht lesbar (%s) - fuer die UDP-Absender "
                        "gilt nur die general.cfg.", fehler)
    for abschnitt in cfg.sections():
        if abschnitt.upper().startswith('MINISERVER') and cfg.has_option(abschnitt, 'IPADDRESS'):
            wert = str(cfg.get(abschnitt, 'IPADDRESS')).strip()
            if wert:
                roh.add(wert)
    aus = {'127.0.0.1'}
    if LoxberryIP and LoxberryIP != '0.0.0.0':
        aus.add(LoxberryIP)
    for adresse in roh:
        try:
            if type(ip_address(adresse)) is IPv4Address:
                aus.add(adresse)
                continue
        except ValueError:
            pass
        try:
            for eintrag in socket.getaddrinfo(adresse, None, socket.AF_INET):
                aus.add(eintrag[4][0])
        except (OSError, UnicodeError) as fehler:
            _LOGGER.warning("Miniserver-Adresse '%s' laesst sich nicht aufloesen (%s) - "
                            "von dort werden keine UDP-Befehle angenommen.", adresse, fehler)
    return aus


def absender_erlaubt(adresse):
    """Darf dieser Absender Befehle schicken? Abweisung gebremst melden:
    die erste sofort, danach hoechstens einmal je Stunde mit der Zahl der
    seither verworfenen."""
    if adresse in ERLAUBTE_ABSENDER:
        return True
    jetzt = time.time()
    eintrag = _FREMDE_ABSENDER.get(adresse)
    if eintrag is None or jetzt - eintrag[0] >= 3600:
        zusatz = '' if eintrag is None or not eintrag[1] else \
            ' (seit der letzten Meldung %d weitere verworfen)' % eintrag[1]
        _LOGGER.warning("UDP von %s verworfen: nur die Miniserver (general.json), "
                        "127.0.0.1 und der LoxBerry selbst duerfen Befehle schicken%s",
                        adresse or '?', zusatz)
        _FREMDE_ABSENDER[adresse] = [jetzt, 0]
    else:
        eintrag[1] += 1
    return False


# ===========================================================================
# Empfang
# ===========================================================================

async def start_server():
    """Empfang, Verarbeitung, Herzschlag und Abfragetakt nebeneinander."""
    _LOGGER.info("Midea2Lox Version: {} msmart Version: {} Python Version: {}.{}.{}".format(
        Midea2Lox_Version, __version__,
        sys.version_info.major, sys.version_info.minor, sys.version_info.micro))

    # get_running_loop(), nicht get_event_loop(): innerhalb einer laufenden
    # Schleife ist das zweite seit 3.12 verfallen und ab 3.14 ein Fehler.
    # Der LoxBerry faehrt heute 3.13.5 - die Zeile waere die naechste, die
    # ohne Zutun kaputtgeht.
    schleife = asyncio.get_running_loop()

    # Harte Obergrenze. Ein Absender, der schneller schickt, als die Geraete
    # antworten, darf nicht den Speicher fuellen - und ein verworfenes Paket
    # wird GEMELDET, nicht stillschweigend fallen gelassen.
    warteschlange = asyncio.Queue(maxsize=200)

    class Empfang(asyncio.DatagramProtocol):
        def datagram_received(self, daten, absender):
            # Laengengrenze. Der laengste zulaessige Befehl ist eine
            # Geraetenummer, ein Schluessel (64), ein Token (128), eine IP und
            # acht Werte - zusammen weit unter 512 Byte. Ohne diese Grenze
            # schreibt EIN Datagramm von 64 kB eine 64-kB-Protokollzeile
            # (die Meldung "Incomming Message" steht vor jeder Auswertung),
            # und acht davon rotieren den ganzen bisherigen Verlauf fort.
            # Abgewiesen heisst gemeldet - aber nur mit den ersten 64 Byte.
            if len(daten) > UDP_MAX:
                _LOGGER.error(
                    "Paket von %s ist %d Byte lang (Grenze %d) und wird "
                    "verworfen: %r", absender[0] if absender else '?',
                    len(daten), UDP_MAX, daten[:64])
                return
            # C1 (Durchgang 30.09.2026, Entscheidung 8): nur die Miniserver
            # aus general.json, 127.0.0.1 und die eigene Adresse duerfen
            # Befehle schicken. Geprueft VOR der Warteschlange - ein
            # abgewiesenes Paket setzt auch keine Handsperre der Automatik.
            if not absender_erlaubt(absender[0] if absender else ''):
                return
            try:
                warteschlange.put_nowait((daten, absender))
            except asyncio.QueueFull:
                _LOGGER.error(
                    "Warteschlange voll (%d) - Paket von %s verworfen. "
                    "Antwortet ein Geraet nicht, oder schickt Loxone zu schnell?",
                    warteschlange.maxsize, absender[0] if absender else '?')

        def error_received(self, fehler):
            _LOGGER.warning("UDP-Fehler: %s", fehler)

    # Der Socket ist seit 4.5.10 schon gebunden (UDP_SOCKET, im Modulrumpf
    # VOR der MQTT-Anmeldung - C6). Hier wird er nur noch uebernommen.
    try:
        transport, _ = await schleife.create_datagram_endpoint(
            Empfang, sock=UDP_SOCKET)
    except OSError as fehler:
        _LOGGER.error("Socket konnte nicht gebunden werden (%s:%s): %s",
                      LoxberryIP, UDP_Port, fehler)
        print('Bind failed. Error : %s' % fehler)
        return
    _LOGGER.info("Socket bind complete, listen at {}:{}".format(LoxberryIP, UDP_Port))
    print('Socket bind complete, listen at', LoxberryIP, ":", UDP_Port)
    _LOGGER.info("UDP-Befehle werden nur angenommen von: %s",
                 ', '.join(sorted(ERLAUBTE_ABSENDER)))

    aufgaben = [
        asyncio.ensure_future(arbeiter(warteschlange)),
        asyncio.ensure_future(herzschlag()),
    ]
    if AUTO_EIN:
        aufgaben.append(asyncio.ensure_future(automatik_schleife()))
        _LOGGER.info("Automatik eingeschaltet: Takt %d s, Verschiebung %.1f K, "
                     "Themen %r / %r", AUTO_TAKT, AUTO_VERSCHIEBUNG,
                     AUTO_THEMA_REGEL, AUTO_THEMA_PV)
    else:
        _LOGGER.info("Automatik ist ausgeschaltet.")
    if FENSTER_EIN:
        aufgaben.append(asyncio.ensure_future(fenster_schleife()))
        _LOGGER.info("Fenster offen -> Geraet aus: eingeschaltet, Frist %d s, %d Geraet(e), "
                     "Themen %s", FENSTER_FRIST, len(FENSTER_ZUORDNUNG),
                     ', '.join(sorted(FENSTER_THEMEN)))
        if MQTT != 1:
            _LOGGER.warning("Fenster offen -> Geraet aus: ohne MQTT hoert der Dienst keine "
                            "Fenster - die Kopplung ruht, es wird nichts ausgeschaltet.")
    if Abfragetakt > 0:
        aufgaben.append(asyncio.ensure_future(abfragetakt_schleife()))
        _LOGGER.info("Abfragetakt eingeschaltet: alle %d s", Abfragetakt)
    else:
        _LOGGER.info("Abfragetakt ist ausgeschaltet - Werte kommen nur auf "
                     "Anforderung aus Loxone (<ID> status).")

    try:
        await asyncio.gather(*aufgaben)
    finally:
        transport.close()


async def arbeiter(warteschlange):
    """Nimmt Pakete aus der Warteschlange und verarbeitet sie der Reihe nach."""
    while True:
        rohdaten, absender = await warteschlange.get()
        try:
            # decode() steht INNERHALB der Absicherung.
            #
            # Bis 4.2.12 stand es davor. Ein einziges UDP-Paket mit einem
            # Byte >= 0x80 - Portscanner, falsch eingetragener fremder
            # Dienst, Tippfehler in einem anderen virtuellen Ausgang - warf
            # einen UnicodeDecodeError, der aus start_server() herausflog,
            # asyncio.run() beendete und den Prozess sterben liess.
            # Nachgemessen mit echtem Socket und drei Paketen: das erste
            # wurde verarbeitet, das zweite toetete den Dienst, das dritte
            # kam nie an. Im Protokoll stand kein Grund - der Dienst starb
            # vor jedem Protokollaufruf.
            #
            # Abgewiesen heisst gemeldet: errors='replace' macht den Inhalt
            # lesbar, und das Paket wird verworfen statt zurechtgebogen.
            try:
                text = rohdaten.decode('utf-8')
            except UnicodeDecodeError:
                _LOGGER.error("Paket von %s ist kein UTF-8 und wird verworfen: %r",
                              absender[0] if absender else '?', rohdaten[:64])
                continue

            daten = text.strip().split(' ')
            if not daten or daten[0] in ('0', ''):
                continue
            print("Incomming Message from Loxone: ", daten)
            _LOGGER.info("Incomming Message from Loxone: {}".format(daten))
            # Ein Befehl von aussen sperrt die Automatik zeitweise.
            #
            # Die Sperre steht HIER und nicht in send_to_midea(): die
            # Automatik benutzt dieselbe Funktion, und dort gesetzt wuerde
            # sie sich bei jedem eigenen Griff selbst aussperren. Eine reine
            # Statusabfrage sperrt nicht - sie aendert nichts.
            if AUTO_EIN and len(daten) > 1 and 'status' not in daten:
                hand_sperren()
            async with GERAETE_SCHLOSS:
                await send_to_midea(daten)
        except Exception as fehler:
            _LOGGER.error("Fehler bei der Verarbeitung: %s", fehler, exc_info=True)
        finally:
            warteschlange.task_done()


# ===========================================================================
# Herzschlag
# ===========================================================================
#
# Ein virtueller Eingang behaelt seinen letzten Wert, bei MQTT mit Retain
# sogar ueber jeden Neustart des Miniservers hinweg. Stirbt der Dienst,
# steht in Loxone weiter der Zustand vom Zeitpunkt des Ausfalls. Das ist
# keine fehlende Auskunft, sondern eine Falschaussage - und sie sieht aus
# wie eine richtige.
#
# ts geht bei JEDEM Durchgang hinaus, auch unveraendert: ueber MQTT gibt es
# kein "Alter", nur einen Zeitstempel; der Miniserver rechnet selbst
# (Alter = (Loxone-Zeit + 1230768000) - ts).
#
# Der Zaehler beantwortet, was der Zeitstempel nicht kann: ein Raspberry
# ohne Echtzeituhr springt beim ersten Zeitabgleich, ein Alter kann danach
# negativ oder stundenlang sein, obwohl alles laeuft. Eine umlaufende Zahl
# nicht.
#
# status/dienst kommt NICHT von hier, sondern aus dem minuetlichen
# Cron-Lauf: ein Dienst, der seinen eigenen Tod melden soll, ist der
# falsche Zeuge.

HERZTAKT = 60
_zaehler = 0

# -1 heisst "noch kein Durchgang", nicht "Durchgang fehlgeschlagen".
#
# Bis 4.3.1 stand hier 0. Am Geraet gemessen (29.08.2026): eine frisch
# eingerichtete Anlage ohne hinterlegtes Klimageraet meldete dauerhaft
# status/ok = 0 - bei kerngesundem Dienst. Dasselbe gilt bei
# abfragetakt = 0, solange Loxone noch nichts geschickt hat: der Wert wird
# nur von einem echten Geraetedurchgang gesetzt.
#
# Wer status/ok in Loxone auf eine Stoermeldung legt, hat damit eine
# Dauerstoerung ohne Stoerung. Das ist die Klasse "ein Kreuz an der ersten
# Stelle einer Pruefkette, das nichts bedeutet".
#
# Drei Zustaende, wie beim dritten Ausgang im Zendure-Plugin:
#   -1  noch nichts gemessen   (weder Haken noch Kreuz)
#    0  der letzte Durchgang ist gescheitert
#    1  der letzte Durchgang hat gemessen
# Die Loxone-Vorlage traegt dafuer Signed="true" und MinVal="-1".
_letzter_erfolg = -1


async def herzschlag():
    global _zaehler
    while True:
        try:
            _zaehler = (_zaehler + 1) % 1000
            jetzt = int(time.time())
            lebenszeichen_schreiben(jetzt, _zaehler, _letzter_erfolg)
            await veroeffentlichen([
                ('status/ts', str(jetzt)),
                ('status/zaehler', str(_zaehler)),
                ('status/ok', str(_letzter_erfolg)),
            ])
            # Ein gescheitertes Abraeumen der Altwerte wird von hier aus
            # wiederholt (hoechstens alle zehn Minuten, siehe unten).
            altlast_anstossen()
            # Ebenso der Nachlauf (M1/M2); er merkt ausserdem, wenn in
            # devices.cfg ein Geraet weggefallen ist.
            nachlauf_anstossen()
        except Exception as fehler:
            _LOGGER.error("Herzschlag gescheitert: %s", fehler)
        await asyncio.sleep(HERZTAKT)


def lebenszeichen_schreiben(zeit, zaehler, ok):
    """Das Lebenszeichen als Datei - die Oberflaeche liest sie.

    Unteilbar ueber eine Nebendatei: die Oberflaeche liest waehrenddessen,
    und eine halb geschriebene JSON-Datei waere kein Lebenszeichen, sondern
    ein Fehler.
    """
    try:
        os.makedirs(data_path, exist_ok=True)
        ziel = os.path.join(data_path, 'lebenszeichen.json')
        tmp = ziel + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump({'ts': zeit, 'zaehler': zaehler, 'ok': ok,
                       'geraete': len(device_id_list)}, f)
        os.replace(tmp, ziel)
    except OSError as fehler:
        _LOGGER.debug("Lebenszeichen nicht schreibbar: %s", fehler)


# ===========================================================================
# Abfragetakt
# ===========================================================================
#
# Bis 4.2.12 war das Plugin rein reaktiv: ohne ein UDP-Paket aus Loxone
# passierte gar nichts. Wer die Raumtemperatur sehen wollte, musste in
# Loxone selbst einen Taktgeber bauen - das stand nirgends in der Anleitung.
#
# AB WERK AUS (abfragetakt=0). Eine bestehende Anlage, die ihren Takt schon
# in Loxone hat, bekaeme sonst die doppelte Abfragelast. Und ein
# Vorgabewert, der beim ersten Lauf ungefragt an die Geraete klopft, ist ein
# Fehler.

async def abfragetakt_schleife():
    # Nicht sofort losrennen: der Dienst startet gerade, und Loxone schickt
    # nach einem Neustart ohnehin meist eine Statusabfrage.
    await asyncio.sleep(min(Abfragetakt, 30))
    while True:
        try:
            ids = geraete_ids_aus_datei()
            if not ids:
                _LOGGER.debug("Abfragetakt: keine Geraete hinterlegt.")
            for gid in ids:
                async with GERAETE_SCHLOSS:
                    try:
                        await send_to_midea([gid, 'status'])
                    except Exception as fehler:
                        _LOGGER.error("Abfragetakt fuer %s gescheitert: %s",
                                      gid, fehler)
        except Exception as fehler:
            _LOGGER.error("Abfragetakt gescheitert: %s", fehler)
        await asyncio.sleep(Abfragetakt)


# ===========================================================================
# Automatik (ab 4.5.0)
# ===========================================================================
#
# Der Grundsatz, an dem sich alles Weitere ausrichtet:
#
#     Die Automatik macht NUR das rueckgaengig, was sie selbst getan hat.
#
# Wer ein Geraet einschaltet, das der Anwender eingeschaltet hatte, und es
# spaeter wieder ausschaltet, hat ihm das Geraet ausgeschaltet. Deshalb
# merkt sich _AUTO_STAND je Geraet, was die Automatik VORGEFUNDEN hat, und
# stellt genau das wieder her - nicht einen gerechneten Wert.

_AUTO_STAND = {}        # Geraetenummer -> {'soll', 'ein', 'aktiv'}
_AUTO_HAND_BIS = 0.0    # Zeitpunkt, bis zu dem der Handbetrieb sperrt
_AUTO_LETZTE_MELDUNG = ''


def automatik_stand_schreiben(traegt, klartext, gesperrt, wieviele):
    """Denselben Zustand als Datei - fuer die Oberflaeche.

    Unteilbar ueber eine Nebendatei: die Oberflaeche liest waehrenddessen,
    und eine halb geschriebene JSON-Datei waere kein Zustand, sondern ein
    Fehler. Derselbe Weg wie beim Lebenszeichen.
    """
    try:
        os.makedirs(data_path, exist_ok=True)
        ziel = os.path.join(data_path, 'automatik.json')
        tmp = ziel + '.tmp'
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump({'ts': int(time.time()),
                       'aktiv': 1 if traegt else 0,
                       'grund': klartext,
                       'gesperrt': int(gesperrt),
                       'geraete': int(wieviele),
                       'thema_regel': AUTO_THEMA_REGEL,
                       'thema_pv': AUTO_THEMA_PV,
                       'abo': {k: v[0] for k, v in abo_stand().items()}}, f)
        os.replace(tmp, ziel)
    except OSError as fehler:
        _LOGGER.debug("Automatikstand nicht schreibbar: %s", fehler)


def hand_sperren():
    """Ein Befehl von aussen setzt die Automatik zeitweise aus."""
    global _AUTO_HAND_BIS
    if AUTO_SPERRZEIT <= 0:
        return
    _AUTO_HAND_BIS = time.time() + AUTO_SPERRZEIT
    _LOGGER.info("Handbetrieb: die Automatik ruht %d Minuten.",
                 int(AUTO_SPERRZEIT / 60))


def hand_sperre_rest():
    rest = _AUTO_HAND_BIS - time.time()
    return int(rest) if rest > 0 else 0


def auto_signal():
    """(traegt, Quelle, Klartext) - warum die Automatik greift oder nicht.

    Die beiden Quellen sind ein ODER: guenstiger Strom ODER eigener
    Ueberschuss. Beide sind einzeln abschaltbar, indem man ihr Thema
    leer laesst.
    """
    gruende = []
    traegt = False

    if AUTO_THEMA_REGEL:
        text, alter = abo_holen(AUTO_THEMA_REGEL, AUTO_MAX_ALTER)
        zahl = abo_zahl(text)
        if text is None and alter is None:
            gruende.append('Regel: noch nichts empfangen')
        elif text is None and alter == -1:
            gruende.append('Regel: nur ein zurueckbehaltener Wert (Alter unbekannt)')
        elif text is None:
            gruende.append('Regel: seit %d s nichts mehr (Grenze %d s)'
                           % (int(alter or 0), AUTO_MAX_ALTER))
        elif zahl is None:
            gruende.append('Regel: %r ist keine Zahl' % text[:20])
        elif zahl >= 1:
            traegt = True
            gruende.append('Regel aktiv')
        else:
            gruende.append('Regel aus')

    if AUTO_THEMA_PV:
        text, alter = abo_holen(AUTO_THEMA_PV, AUTO_MAX_ALTER)
        zahl = abo_zahl(text)
        if text is None and alter is None:
            gruende.append('PV: noch nichts empfangen')
        elif text is None and alter == -1:
            gruende.append('PV: nur ein zurueckbehaltener Wert (Alter unbekannt)')
        elif text is None:
            gruende.append('PV: seit %d s nichts mehr (Grenze %d s)'
                           % (int(alter or 0), AUTO_MAX_ALTER))
        elif zahl is None:
            gruende.append('PV: %r ist keine Zahl' % text[:20])
        elif zahl >= AUTO_PV_AB:
            traegt = True
            gruende.append('PV %d W >= %d W' % (int(zahl), AUTO_PV_AB))
        else:
            gruende.append('PV %d W < %d W' % (int(zahl), AUTO_PV_AB))

    return traegt, ', '.join(gruende) if gruende else 'kein Thema eingetragen'


def auto_richtung(device):
    """Welches Vorzeichen die Verschiebung in dieser Betriebsart hat.

    Kuehlen heisst: guenstiger Strom -> TIEFER stellen, also vorkuehlen.
    Heizen heisst:  guenstiger Strom -> HOEHER stellen, also vorwaermen.

    Ein Vorzeichen, das fuer die Haelfte der Betriebsarten falsch ist, waere
    genau die Sorte Fehler, die man erst im Winter merkt. Betriebsarten ohne
    sinnvollen Sollwert liefern 0 und werden uebersprungen.
    """
    try:
        name = getattr(device.operational_mode, 'name', str(device.operational_mode))
    except Exception:
        return 0
    name = str(name).lower()
    if name in ('cool', 'dry'):
        return -1
    if name == 'heat':
        return +1
    return 0


def auto_geraete_liste():
    ids = geraete_ids_aus_datei()
    if AUTO_GERAETE:
        ids = [x for x in ids if x in AUTO_GERAETE]
    return ids


def auto_device(gid):
    for d in device_list:
        try:
            if int(gid) == d.id:
                return d
        except (TypeError, ValueError):
            continue
    return None


async def auto_anlegen(gid, device):
    """Greifen: Sollwert verschieben, auf Wunsch Turbo und Einschalten."""
    stand = _AUTO_STAND.get(gid)
    if stand and stand.get('aktiv'):
        return False
    # Den Ist-Zustand FRISCH holen, bevor wir ihn uns merken.
    #
    # Was hier gemerkt wird, stellt auto_loesen() spaeter genau so wieder
    # her. Aus dem zwischengespeicherten Objekt gelesen waere das der Stand
    # der letzten Abfrage - wer inzwischen an der Fernbedienung den Sollwert
    # verstellt hat, bekaeme beim Loslassen den alten Wert zurueck.
    #
    # Der Durchgang faellt nur beim ZUGREIFEN an: steht schon eine
    # Verschiebung, ist die Funktion oben bereits zurueckgekehrt.
    try:
        await send_to_midea([gid, 'status'])
    except Exception as fehler:
        _LOGGER.warning("Automatik: %s liess sich nicht abfragen (%s) - "
                        "es wird nicht zugegriffen.", gid, fehler)
        return False
    frisch = auto_device(gid)
    if frisch is not None:
        device = frisch
    richtung = auto_richtung(device)
    if richtung == 0:
        _LOGGER.debug("Automatik: %s ist in einer Betriebsart ohne Sollwert.", gid)
        return False
    try:
        vorher_soll = float(device.target_temperature)
    except (TypeError, ValueError):
        _LOGGER.warning("Automatik: %s nennt keinen Sollwert - uebersprungen.", gid)
        return False
    vorher_ein = bool(getattr(device, 'power_state', False))

    neu = vorher_soll + richtung * AUTO_VERSCHIEBUNG
    if neu < AUTO_SOLL_MIN:
        neu = float(AUTO_SOLL_MIN)
    if neu > AUTO_SOLL_MAX:
        neu = float(AUTO_SOLL_MAX)
    # Der eigene Sollwert der Automatik bleibt in den Grenzen des Geraets.
    # Bis 4.5.9 leistete das die Klemme in send_to_midea(); seit 4.5.10
    # weist send_to_midea() Werte ausserhalb ab (C5) - die Automatik rechnet
    # ihren Wert deshalb hier selbst hinein, statt abgewiesen zu werden.
    try:
        neu = min(max(neu, float(device.min_target_temperature)),
                  float(device.max_target_temperature))
    except (AttributeError, TypeError, ValueError):
        pass
    if abs(neu - vorher_soll) < 0.05 and not (AUTO_SCHALTEN and not vorher_ein):
        _LOGGER.debug("Automatik: %s liegt schon an der Grenze - nichts zu tun.", gid)
        return False

    _AUTO_STAND[gid] = {'soll': vorher_soll, 'ein': vorher_ein, 'aktiv': True}
    _LOGGER.info("Automatik greift bei %s: Sollwert %.1f -> %.1f", gid,
                 vorher_soll, neu)
    if AUTO_SCHALTEN and not vorher_ein:
        await send_to_midea([gid, 'power.True'])
    await send_to_midea([gid, 'temp.%.1f' % neu])
    if AUTO_TURBO:
        await send_to_midea([gid, 'turbo.True'])
    return True


async def auto_loesen(gid, wegen_hand=False):
    """Loslassen: den vorgefundenen Zustand wiederherstellen.

    wegen_hand=True heisst: es laesst nicht das Signal nach, sondern der
    ANWENDER hat gerade einen Befehl geschickt. Dann wird der Sollwert
    zurueckgestellt - die Verschiebung war unsere -, aber NICHT geschaltet.

    Warum diese Unterscheidung sein muss, ist am Pruefstand aufgefallen:
    die Automatik hatte ein Geraet eingeschaltet, der Anwender schickte
    power.True, das setzte die Handsperre, die Automatik liess los - und
    schaltete das Geraet aus, Sekunden nachdem er es eingeschaltet hatte.
    Sie arbeitete gegen den Menschen, der danebensteht. Bleibt das Signal
    dagegen einfach aus, ist niemand da, der etwas anderes wollte; dann ist
    das Ausschalten richtig.
    """
    stand = _AUTO_STAND.get(gid)
    if not stand or not stand.get('aktiv'):
        return False
    _LOGGER.info("Automatik laesst %s los%s: Sollwert zurueck auf %.1f", gid,
                 " (Handbetrieb - es wird nicht geschaltet)" if wegen_hand else "",
                 stand['soll'])
    if AUTO_TURBO:
        await send_to_midea([gid, 'turbo.False'])
    await send_to_midea([gid, 'temp.%.1f' % stand['soll']])
    # AUSSCHALTEN NUR, WENN DIE AUTOMATIK SELBST EINGESCHALTET HAT - UND
    # NIEMAND VON HAND DAZWISCHENGEGANGEN IST.
    if AUTO_SCHALTEN and not stand.get('ein') and not wegen_hand:
        await send_to_midea([gid, 'power.False'])
    stand['aktiv'] = False
    return True


async def automatik_schleife():
    """Der Takt der Automatik.

    Sie faellt IMMER auf den vorgefundenen Zustand zurueck, wenn das Signal
    ausbleibt, veraltet oder unlesbar ist - "im Zweifel loslassen" ist die
    einzige Voreinstellung, die niemandem die Wohnung auskuehlt.
    """
    global _AUTO_LETZTE_MELDUNG
    await asyncio.sleep(min(AUTO_TAKT, 30))
    while True:
        try:
            traegt, klartext = auto_signal()
            rest = hand_sperre_rest()
            if rest > 0:
                traegt = False
                klartext = 'Handbetrieb, noch %d min' % int(rest / 60 + 0.5)
            if klartext != _AUTO_LETZTE_MELDUNG:
                _LOGGER.info("Automatik: %s", klartext)
                _AUTO_LETZTE_MELDUNG = klartext

            for gid in auto_geraete_liste():
                stand = _AUTO_STAND.get(gid)
                aktiv = bool(stand and stand.get('aktiv'))
                if traegt and not aktiv:
                    # c1: waehrend einer Fensteroeffnung weder zugreifen noch
                    # einschalten - sonst liefe das Geraet bei offenem Fenster.
                    if fenster_sperrt(gid):
                        _LOGGER.debug("Automatik: bei %s ist ein Fenster offen - es wird "
                                      "nicht zugegriffen.", gid)
                        continue
                    device = auto_device(gid)
                    if device is None:
                        # Das Geraet ist dem Dienst noch nie begegnet. Eine
                        # Statusabfrage legt es an - derselbe Weg, den auch
                        # Loxone geht.
                        async with GERAETE_SCHLOSS:
                            await send_to_midea([gid, 'status'])
                        device = auto_device(gid)
                        if device is None:
                            continue
                    async with GERAETE_SCHLOSS:
                        await auto_anlegen(gid, device)
                elif aktiv and not traegt:
                    # Zum Loslassen wird das Geraeteobjekt nicht gebraucht.
                    async with GERAETE_SCHLOSS:
                        await auto_loesen(gid, wegen_hand=(rest > 0))
                # Sonst: nichts zu tun - und dann wird auch nichts angefasst.
                # Ein Takt, der alle fuenf Minuten jedes Klimageraet abfragt,
                # obwohl nichts ansteht, ist kein Beiwerk: er haelt die
                # Verbindung dauerhaft warm und steht der Bedienung im Weg.

            wieviele = len([g for g, s in _AUTO_STAND.items() if s.get('aktiv')])
            automatik_stand_schreiben(traegt, klartext, rest, wieviele)
            await veroeffentlichen([
                ('automatik/aktiv', '1' if traegt else '0'),
                ('automatik/grund', klartext),
                ('automatik/gesperrt', str(rest)),
                ('automatik/geraete', str(wieviele)),
            ])
        except Exception as fehler:
            _LOGGER.error("Automatik gescheitert: %s", fehler, exc_info=True)
        await asyncio.sleep(AUTO_TAKT)


# ===========================================================================
# Fenster offen -> Klimageraet aus (Verbesserungsbau 30.09.2026, c1)
# ===========================================================================
#
# AB WERK AUS (fenster_ein=0): eine Kopplung an ein anderes Plugin wird nicht
# durch ein Update eingeschaltet.
#
# Die Quelle sind die Haus-Themen haus/tuer/<name>/offen (Regeln/07,
# Entscheidung 15: 0/1, retained, '-' = keine Aussage; Anbieter Matter2Lox -
# jeder Kontaktsensor gilt dort als Tuer, also auch ein Fensterkontakt). Die
# Kopplung laeuft nur ueber diese Themen, nie ueber Dateien eines anderen
# Plugins. Fensterbilanz sendet kein Thema fuer ein offenes Fenster (nur
# Urteile ueber den Sonneneintrag) und kommt als Quelle nicht in Frage.
#
#   * Je Geraet eine Liste von Fenstern (fenster_zuordnung
#     "<Geraetenummer>:<name>+<name>,<Geraetenummer>:<name>").
#   * Geht ein zugeordnetes Fenster LIVE auf 1 und dauert die Oeffnung
#     fenster_frist Sekunden (0-600, Vorgabe 60), geht EIN Befehl
#     power.False an das Geraet. Die Oeffnung endet erst, wenn keines seiner
#     Fenster mehr offen ist - ein zweites Fenster, das dazukommt, loest
#     keinen zweiten Befehl aus (Bremse: einer je Geraet je Oeffnung).
#   * Schliesst das Fenster, wird das Geraet NICHT wieder eingeschaltet. Wer
#     das will, baut es in Loxone; ein Plugin, das von sich aus einschaltet,
#     heizt oder kuehlt womoeglich ein Zimmer, das niemand mehr braucht.
#   * Ein zurueckbehaltener Wert (Abo nach dem Start oder nach einem
#     Wiederverbinden) hat kein bekanntes Alter und loest nie etwas aus (wie
#     C2). '-' und jeder andere Wert als '1' heissen "nicht offen". Schweigt
#     die Quelle, passiert nichts - und der Reiter Test sagt es.
#   * Waehrend eine Oeffnung laeuft, greift die Automatik bei diesem Geraet
#     nicht zu (sie wuerde es sonst womoeglich wieder einschalten).
FENSTER_THEMA = 'haus/tuer/%s/offen'
FENSTER_TAKT = 2
_FENSTER_ZEICHEN = set('abcdefghijklmnopqrstuvwxyz0123456789_')
_FENSTER_SCHLOSS = threading.Lock()
_FENSTER = {}           # Name -> {'wert', 'live', 'empfangen', 'offen_seit'}
_FENSTER_BEFOHLEN = {}  # Geraetenummer -> Zeitpunkt des Aus-Befehls dieser Oeffnung


def fenster_zuordnung_lesen(roh):
    """{Geraetenummer: [Namen]} aus fenster_zuordnung - oder None bei einem
    Formfehler. Dieselbe Regel wie mi_fenster_zuordnung() in mi_lib.php:
    Nummer 10-19 Ziffern, jede nur einmal; Namen wie im Haus-Thema (klein,
    a-z, 0-9, _, 1-40 Zeichen), je Geraet ohne Doppel."""
    aus = {}
    text = str(roh or '').strip()
    if not text:
        return aus
    for teil in text.split(','):
        gid, trenner, namen = teil.strip().partition(':')
        gid = gid.strip()
        if (not trenner or not 10 <= len(gid) <= 19
                or not all(c in '0123456789' for c in gid) or gid in aus):
            return None
        liste = [n.strip() for n in namen.split('+')]
        if len(set(liste)) != len(liste) or any(
                not 1 <= len(n) <= 40 or not set(n) <= _FENSTER_ZEICHEN for n in liste):
            return None
        aus[gid] = liste
    return aus


def fenster_merken(thema, text, zurueckbehalten):
    """Ein Wert eines Fensterthemas ist eingetroffen.

    Laeuft im Netzwerkfaden von paho - hier wird nur gemerkt, nichts
    geschaltet; das tut fenster_schleife() in der Ereignisschleife.
    """
    name = FENSTER_THEMEN.get(thema)
    if name is None:
        return
    jetzt = time.time()
    with _FENSTER_SCHLOSS:
        e = _FENSTER.setdefault(name, {'wert': None, 'live': False,
                                       'empfangen': None, 'offen_seit': None})
        e['wert'] = text
        e['empfangen'] = jetzt
        e['live'] = not zurueckbehalten
        if text != '1':
            # zu, '-' (keine Aussage) oder unlesbar: keine Oeffnung.
            e['offen_seit'] = None
        elif not zurueckbehalten and e['offen_seit'] is None:
            # Nur ein LIVE gesendetes '1' beginnt eine Oeffnung. Ein
            # zurueckbehaltenes '1' laesst eine laufende stehen (Wiederverbinden
            # mitten in der Frist) und beginnt keine neue.
            e['offen_seit'] = jetzt
    _LOGGER.debug("Fenster %s = %s%s", name, text[:10],
                  " (zurueckbehalten, loest nichts aus)" if zurueckbehalten else "")


def fenster_offen(gid):
    """Die Fenster dieses Geraets mit laufender Oeffnung: [(Name, seit)]."""
    with _FENSTER_SCHLOSS:
        return [(n, _FENSTER[n]['offen_seit']) for n in FENSTER_ZUORDNUNG.get(gid, ())
                if n in _FENSTER and _FENSTER[n]['offen_seit'] is not None]


def fenster_sperrt(gid):
    """Laeuft fuer dieses Geraet eine Oeffnung? Dann greift die Automatik nicht."""
    return FENSTER_EIN and bool(fenster_offen(gid))


def fenster_stand():
    """Der Stand der Kopplung fuer die Oberflaeche - ohne Zeitstempel des
    Schreibens, damit ein unveraenderter Stand nicht jede Runde auf die
    Karte geht."""
    fenster = {}
    with _FENSTER_SCHLOSS:
        for namen in FENSTER_ZUORDNUNG.values():
            for n in namen:
                e = _FENSTER.get(n) or {}
                fenster[n] = {
                    'wert': e.get('wert'),
                    'live': 1 if e.get('live') else 0,
                    'empfangen': int(e['empfangen']) if e.get('empfangen') else None,
                    'offen_seit': int(e['offen_seit']) if e.get('offen_seit') else None,
                }
    geraete = {}
    for gid, namen in FENSTER_ZUORDNUNG.items():
        wann = _FENSTER_BEFOHLEN.get(gid)
        geraete[gid] = {'fenster': list(namen),
                        'aus_befohlen': int(wann) if wann else None}
    return {'mqtt': 1 if (MQTT == 1 and mqtt_error == 0) else 0,
            'frist': FENSTER_FRIST, 'fenster': fenster, 'geraete': geraete}


def fenster_stand_schreiben(stand):
    """Unteilbar ueber eine Nebendatei - wie lebenszeichen.json."""
    try:
        os.makedirs(data_path, exist_ok=True)
        ziel = os.path.join(data_path, 'fenster.json')
        tmp = ziel + '.tmp'
        daten = dict(stand)
        daten['ts'] = int(time.time())
        with open(tmp, 'w', encoding='utf-8') as f:
            json.dump(daten, f)
        os.replace(tmp, ziel)
    except OSError as fehler:
        _LOGGER.debug("Fensterstand nicht schreibbar: %s", fehler)


async def fenster_schleife():
    """Der Takt der Kopplung: Frist abwarten, einmal ausschalten, merken."""
    letzter = None
    geschrieben = 0.0
    while True:
        try:
            jetzt = time.time()
            for gid in list(FENSTER_ZUORDNUNG):
                offen = fenster_offen(gid)
                if not offen:
                    if _FENSTER_BEFOHLEN.pop(gid, None) is not None:
                        _LOGGER.info("Fenster: bei %s ist wieder alles zu - das Geraet bleibt "
                                     "aus, es wird nicht wieder eingeschaltet.", gid)
                    continue
                if gid in _FENSTER_BEFOHLEN:
                    continue        # Bremse: ein Befehl je Geraet je Oeffnung
                seit = min(s for _n, s in offen)
                if jetzt - seit < FENSTER_FRIST:
                    continue
                # ERST merken, DANN senden: auch ein gescheiterter Befehl zaehlt
                # als der eine dieser Oeffnung - kein Nachfassen im Takt.
                _FENSTER_BEFOHLEN[gid] = jetzt
                _LOGGER.info("Fenster offen (%s) seit %d s - %s wird ausgeschaltet "
                             "(ein Befehl je Oeffnung).", ', '.join(n for n, _s in offen),
                             int(jetzt - seit), gid)
                async with GERAETE_SCHLOSS:
                    await send_to_midea([gid, 'power.False'])
            stand = fenster_stand()
            if stand != letzter or jetzt - geschrieben >= 60:
                fenster_stand_schreiben(stand)
                letzter, geschrieben = stand, jetzt
        except Exception as fehler:
            _LOGGER.error("Fenster-Kopplung gescheitert: %s", fehler, exc_info=True)
        await asyncio.sleep(FENSTER_TAKT)


def geraete_ids_aus_datei():
    """Die Geraetenummern aus devices.cfg - bei jedem Durchgang neu gelesen.

    Der Dienst liest Aenderungen damit ohne Neustart; wer ein Geraet
    hinzufuegt, muss nicht neu starten.
    """
    try:
        c = configparser.RawConfigParser()
        c.read(cfg_path + '/devices.cfg')
        aus = []
        for ab in c.sections():
            if c.has_option(ab, 'id'):
                aus.append(str(c.get(ab, 'id')).strip())
            elif ab.startswith('Midea_'):
                aus.append(ab[6:])
        return [x for x in aus if x.isdigit()]
    except Exception as fehler:
        _LOGGER.debug("devices.cfg nicht lesbar: %s", fehler)
        return []


# ===========================================================================
# Befehle an das Geraet
# ===========================================================================

# C4 (Durchgang 30.09.2026): Adresse, Port, Token und Schluessel, mit denen
# ein Geraet angelegt wurde. Bis 4.5.9 wurden sie nur beim ERSTEN Anlegen
# benutzt; fand die Suche eine neue Adresse und schrieb sie in devices.cfg,
# sprach der Dienst bis zum naechsten Neustart die alte an (in WSL gemessen,
# Bericht code Befund 4: "refresh ip=127.0.0.1" nach Umstellung auf
# 127.0.0.9). Jetzt wird bei jedem Befehl verglichen; weicht etwas ab, wird
# das Geraet verworfen und mit den neuen Angaben neu angelegt - ohne
# Neustart des Dienstes.
_GERAET_SIGNATUR = {}   # Geraetenummer (int) -> (ip, port, token, key)


def geraet_verwerfen(nummer):
    """Ein zwischengespeichertes Geraet vergessen (C4)."""
    while nummer in device_id_list:
        device_id_list.remove(nummer)
    for d in [d for d in device_list if getattr(d, 'id', None) == nummer]:
        device_list.remove(d)
    _GERAET_SIGNATUR.pop(nummer, None)


def soll_pruefen(device, wert, wort):
    """Eine Solltemperatur pruefen - abweisen statt zurechtbiegen (C5).

    Bis 4.5.9 kam temp.nan bis in apply() und ging als "nan" retained an den
    Broker (float('nan') besteht beide Vergleiche < und >), und ein Wert
    ausserhalb des Bereichs wurde still auf die Grenze gesetzt (in WSL
    gemessen, Bericht code Befund 5). Rueckgabe: die Zahl, oder None, wenn
    der Befehl zu verwerfen ist - dann steht der Grund im Protokoll.
    """
    try:
        zahl = float(wert)
    except (TypeError, ValueError):
        _LOGGER.error("ungueltige Solltemperatur '%s' - Befehl verworfen", wort)
        return None
    if not math.isfinite(zahl):
        _LOGGER.error("Solltemperatur '%s' ist keine endliche Zahl - Befehl verworfen", wort)
        return None
    try:
        unten = float(device.min_target_temperature)
        oben = float(device.max_target_temperature)
    except (AttributeError, TypeError, ValueError):
        return zahl
    if zahl < unten or zahl > oben:
        _LOGGER.error("Solltemperatur '%s' liegt ausserhalb des Bereichs %s-%s dieses "
                      "Geraets - Befehl verworfen, der Sollwert bleibt.", wort,
                      device.min_target_temperature, device.max_target_temperature)
        return None
    return zahl


# send to Midea Appliance over LAN/WLAN
async def send_to_midea(data):
    global _letzter_erfolg
    runtime = time.time()
    try:
        oldLox = 0
        device_port = 6444
        retries = 0
        statusupdate = 0
        support_mode = 0
        device_id = None
        device_ip = None
        device_key = None
        device_token = None

        # support_msmart_ng entfaellt - die Zuordnung steht jetzt in
        # mi_tabellen() und liefert Namen statt auszuwertender Zeichenketten.

        for eachArg in data: ### get device_id
            if len(eachArg) in range(10,20) and eachArg.isdigit():
                device_id = eachArg
                _LOGGER.debug("Device ID: '{}'".format(device_id))
            elif len(eachArg) == 64:
                device_key = eachArg
                _LOGGER.debug("Device Key: '{}'".format('*' * 8))
                oldLox = 1
            elif len(eachArg) == 128:
                device_token = eachArg
                _LOGGER.debug("Device Token: '{}'".format('*' * 8))
                oldLox = 1
            elif eachArg == "status":
                statusupdate = 1
                _LOGGER.debug("statusupdate =: {}".format(statusupdate))
            try:
                if type(ip_address(eachArg)) is IPv4Address and not eachArg.isdigit():
                    device_ip = eachArg
                    _LOGGER.debug("Device ip: {}".format(device_ip))
                    oldLox = 1
            except ValueError:
                pass

        if len(data) == 10 and data[0] == 'True' or len(data) == 10 and data[0] == 'False': ### support older Midea2Lox Versions <3.x
            support_mode = 1
            _LOGGER.debug("support Mode enabled")

        else:
            if device_id == None:
                _LOGGER.error("missing device_id, please check your Loxone config")
                return
            try:
                _LOGGER.debug('get device informations')
                cfg_devices = configparser.RawConfigParser()
                cfg_devices.read(cfg_path + '/devices.cfg')
                abschnitt = 'Midea_' + device_id
                # Port, Token und Schluessel werden AUCH dann aus der Datei
                # geholt, wenn Loxone eine IP mitgeschickt hat.
                #
                # Bis 4.2.12 hing der ganze Block an "if device_ip == None".
                # Eine aeltere Loxone-Konfiguration, die die IP mitsendet -
                # was Zeile 352 ausdruecklich als ueberfluessig, aber
                # zulaessig behandelt -, bekam damit weder Token noch
                # Schluessel: die Anmeldung wurde uebersprungen, und ein
                # V3-Geraet wurde ungeschuetzt angesprochen. Der Port blieb
                # auf dem fest verdrahteten 6444.
                if cfg_devices.has_section(abschnitt):
                    if device_ip is None and cfg_devices.has_option(abschnitt, 'ip'):
                        device_ip = cfg_devices.get(abschnitt, 'ip')
                    if cfg_devices.has_option(abschnitt, 'port'):
                        try:
                            device_port = int(cfg_devices.get(abschnitt, 'port'))
                        except ValueError:
                            _LOGGER.warning("Port in devices.cfg fuer %s ist keine "
                                            "Zahl - es gilt 6444.", device_id)
                    ## Token und Schluessel gibt es nur bei V3-Geraeten.
                    if device_key is None and cfg_devices.has_option(abschnitt, 'key'):
                        device_key = cfg_devices.get(abschnitt, 'key')
                    if device_token is None and cfg_devices.has_option(abschnitt, 'token'):
                        device_token = cfg_devices.get(abschnitt, 'token')
                else:
                    _LOGGER.warning('couldn´t find Device ID "%s", please do Discover '
                                    'or Check your Loxone config to send the right ID'
                                    % (device_id))
            except (configparser.Error, OSError) as fehler:
                _LOGGER.warning('devices.cfg nicht lesbar: %s', fehler)

        # sys.exit() statt return war hier ein Missgriff, wenn auch ein
        # harmloserer als oft angenommen: SystemExit erbt von BaseException,
        # nicht von Exception. Das "except Exception" am Ende dieser Funktion
        # faengt es also NICHT - wohl aber das nackte "except:" in
        # start_server(). Nachgemessen: fuenf von fuenf Paketen ueberlebt,
        # der Dienst stirbt NICHT.
        #
        # Zwei Gruende, es trotzdem zu aendern:
        #   - Die Meldung geht verloren. Protokolliert wird sys.exc_info(),
        #     also eine SystemExit-Spur statt des Klartexts.
        #   - Es ist eine Falle. Wer das nackte "except:" spaeter zu
        #     "except Exception:" praezisiert - was jeder Ratgeber empfiehlt -,
        #     macht den Dienst damit unbeabsichtigt toetbar.
        if device_id == None:
            _LOGGER.error('device ID unknown')
            return
        elif device_ip == None:
            _LOGGER.error('device IP unknown')
            return


        # C4: neue Angaben aus devices.cfg (oder aus dem Paket) fuer ein schon
        # angelegtes Geraet? Dann verwerfen und unten neu anlegen.
        signatur = (str(device_ip), int(device_port), device_token or '', device_key or '')
        vorher = _GERAET_SIGNATUR.get(int(device_id))
        if int(device_id) in device_id_list and vorher is not None and vorher != signatur:
            _LOGGER.info("Midea.%s hat neue Angaben (Adresse %s:%s%s) - das Geraet wird "
                         "ohne Neustart des Dienstes neu angelegt.", device_id, device_ip,
                         device_port,
                         ", Token/Schluessel geaendert" if vorher[2:] != signatur[2:] else "")
            geraet_verwerfen(int(device_id))

        if int(device_id) not in device_id_list: ### Init nur von neuen Devices
            _LOGGER.debug('Init eines neuen Devices')
            device = ac(ip=device_ip, device_id=int(device_id), port=device_port)
            try: ### support old configs without max_connection_lifetime
                device.set_max_connection_lifetime(int(cfg.get('default','maxConnectionLifetime')))
            except (configparser.Error, ValueError):
                _LOGGER.error('set maxConnectionLifetime to 90s. Please set maxConnectionLifetime and click "save and restart"')
                device.set_max_connection_lifetime(90)
            if device_key and device_token: ### support midea V3
                try:
                    await device.authenticate(device_token, device_key)
                except Exception as fehler:
                    device._online = False
                    await send_to_loxone(device, 0)
                    _LOGGER.error("Error on Authenticate: %s", fehler)
                    return
                retries = 0

            else:
                _LOGGER.debug("use Midea V2")

            await device.get_capabilities()
            device.enable_energy_usage_requests = True   ### msmart-ng: Early tests have shown that many devices report energy usage without claiming support

            # Die Faehigkeitsliste ist eine reine Protokollzeile - sie darf
            # das Zwischenspeichern nicht verhindern. Bis 4.2.12 stand sie
            # ZWISCHEN get_capabilities() und den beiden append(): ein
            # AttributeError auf eines der 25 Merkmale (etwa bei einer
            # aelteren msmart-Fassung) haette das Geraet nie in die Liste
            # kommen lassen, und es waere bei JEDEM Paket vollstaendig neu
            # aufgebaut worden, inklusive authenticate().
            device_id_list.append(device.id)
            device_list.append(device)
            _GERAET_SIGNATUR[device.id] = signatur
            faehigkeiten_protokollieren(device)

        else:
            for devices in device_list:
                if int(device_id) == devices.id:
                    device = devices

        if statusupdate == 1: ### refresh() AC State
            try:
                await device.refresh()
                while device.online == False and retries < 2: ### retry 2 times on connection error
                    retries += 1
                    _LOGGER.warning("retry refresh %s/2" %(retries))
                    await asyncio.sleep(5)
                    await device.refresh()
            except Exception as error:
                device._online = False
                _LOGGER.error(error)

        else: ### apply() AC changes
            if support_mode == 1:
                _LOGGER.info("apply() on support Mode for Loxone Configs createt with Midea2Lox V2.x --> MQTT disabled. If you want to use MQTT you need to update your Loxoneconfig")
                key = ["True", "False", "ac.operational_mode_enum.auto", "ac.operational_mode_enum.cool", "ac.operational_mode_enum.heat", "ac.operational_mode_enum.dry", "ac.operational_mode_enum.fan_only", "ac.fan_speed_enum.High", "ac.fan_speed_enum.Medium", "ac.fan_speed_enum.Low", "ac.fan_speed_enum.Auto", "ac.fan_speed_enum.Silent", "ac.swing_mode_enum.Off", "ac.swing_mode_enum.Vertical", "ac.swing_mode_enum.Horizontal", "ac.swing_mode_enum.Both"]
                if data[0] in key and data[1] in key and data[3] in key and data[4] in key and data[5] in key and data[6] in key and data[7] in key:
                    tab = mi_tabellen()
                    # Die Weissliste prueft nur die ZUGEHOERIGKEIT, nicht die
                    # Position. Steht an Stelle 3 eine Luefterstufe statt
                    # eines Betriebsmodus, liefert die Tabelle None - und bis
                    # 4.2.12 wurde None ungeprueft gesetzt. Spaeter scheiterte
                    # der Aufbau der Werteliste an ".name" auf None, und damit
                    # ging der GANZE Statusbericht verloren, auch die
                    # Offline-Meldung.
                    beanstandet = []
                    p_power = mi_bool(data[0])
                    p_beep = mi_bool(data[1])
                    p_mode = mi_enum(ac.OperationalMode, tab['operational_mode'].get(data[3]))
                    p_fan = mi_enum(ac.FanSpeed, tab['fan_speed'].get(data[4]))
                    p_swing = mi_enum(ac.SwingMode, tab['swing_mode'].get(data[5]))
                    p_eco = mi_bool(data[6])
                    p_turbo = mi_bool(data[7])
                    try:
                        p_temp = int(data[2])
                    except (ValueError, TypeError):
                        p_temp = None
                        beanstandet.append('Temperatur %r' % (data[2],))
                    # C5: ausserhalb des Bereichs abweisen, nicht klemmen.
                    if p_temp is not None and soll_pruefen(device, p_temp, data[2]) is None:
                        beanstandet.append('Temperatur %r ausserhalb des Bereichs' % (data[2],))
                    for name, wert in (('power', p_power), ('tone', p_beep),
                                       ('operational_mode', p_mode), ('fan_speed', p_fan),
                                       ('swing_mode', p_swing), ('eco', p_eco),
                                       ('turbo', p_turbo)):
                        if wert is None:
                            beanstandet.append(name)
                    if beanstandet:
                        _LOGGER.error("support Mode: unbrauchbare Angaben (%s) - "
                                      "Befehl verworfen. Bitte die Loxone-Konfiguration pruefen.",
                                      ', '.join(beanstandet))
                        return
                    device.power_state = p_power
                    device.beep = p_beep
                    device.target_temperature = p_temp
                    device.operational_mode = p_mode
                    device.fan_speed = p_fan
                    device.swing_mode = p_swing
                    if device.supports_eco:
                        device.eco = p_eco
                    if device.supports_turbo:
                        device.turbo = p_turbo
                else:
                    for eachArg in data:
                        if eachArg not in key and eachArg != data[2] and eachArg != data[8] and eachArg != data[9]:
                            print("getting wrong Argument: ", eachArg)
                            _LOGGER.error("getting wrong Argument: '{}'. Please check your Loxone config.".format(eachArg))
                    _LOGGER.info("allowed Arguments: {}".format(key))
                    return

            else: # new find command logic. Need new Loxone config (power.True, tone.True, eco.True, turbo.True -- and False of each)
                if oldLox == 1:
                    _LOGGER.warning("you dont need to send IP, Key and Token anymore, just do a discover and send your DeviceID")
                # Ein refresh() ist noetig, wenn Loxone NICHT alle Werte
                # mitschickt.
                #
                # Bis 4.2.12 stand hier
                #     ((key and token) and len(data) != 12) or len(data) != 10
                # Wahrheitstafel nachgerechnet: nur "genau zehn Felder OHNE
                # Key und Token" ergibt False - also genau umgekehrt zur
                # Absicht des Kommentars. Bei einem V3-Geraet, dem
                # Normalfall, lief damit vor JEDEM Setzbefehl zusaetzlich ein
                # refresh(), inklusive der beiden Wiederholungen a 5 s.
                vollstaendig = (len(data) == 12) if (device_key and device_token) else (len(data) == 10)
                if not vollstaendig:
                    try:
                        await device.refresh()
                        while device.online == False and retries < 2: # retry 2 times on connection error
                            retries += 1
                            _LOGGER.warning("retry refresh %s/2" %(retries))
                            await asyncio.sleep(5)
                            await device.refresh()
                    except Exception as error:
                        device._online = False
                        await send_to_loxone(device, support_mode)
                        raise error

                #set all allowed key´s for Loxone input
                power = ["power.True", "power.False"]
                tone = ["tone.True", "tone.False"]
                operation = ["ac.operational_mode_enum.auto", "ac.operational_mode_enum.cool", "ac.operational_mode_enum.heat", "ac.operational_mode_enum.dry", "ac.operational_mode_enum.fan_only"]
                swing_modes = ["ac.swing_mode_enum.Off", "ac.swing_mode_enum.Vertical", "ac.swing_mode_enum.Horizontal", "ac.swing_mode_enum.Both"]
                eco = ["eco.True", "eco.False"]
                turbo = ["turbo.True", "turbo.False"]
                display = ["toggle_Display"]
                freeze = ["freeze.True", "freeze.False"]
                sleep = ["sleep.True", "sleep.False"]
                follow = ["follow.True", "follow.False"]
                purifier = ["purifier.True", "purifier.False"]
                self_clean = ["toggle_self_clean"]
                rate_select = ["rate_select.OFF", "rate_select.GEAR_50", "rate_select.GEAR_75", "rate_select.LEVEL_1", "rate_select.LEVEL_2", "rate_select.LEVEL_3", "rate_select.LEVEL_4", "rate_select.LEVEL_5"]
                breeze_away = ["breeze_away.True","breeze_away.False"]
                breeze_mild = ["breeze_mild.True","breeze_mild.False"]
                breezeless = ["breezeless.True","breezeless.False"]
                ieco = ["ieco.True", "ieco.False"]

                for eachArg in data: #find keys from Loxone to msmart
                    if eachArg in power:
                        device.power_state = mi_bool(eachArg.split(".")[1])
                        _LOGGER.debug("Device Power state '{}'".format(device.power_state))
                    elif eachArg in tone:
                        device.beep = mi_bool(eachArg.split(".")[1])
                        _LOGGER.debug("Device promt Tone '{}'".format(device.beep))
                    elif eachArg in eco:
                        if device.supports_eco:
                            device.eco = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug("Device Eco Mode '{}'".format(device.eco))
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in turbo:
                        if device.supports_turbo:
                            device.turbo = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug("Device Turbo Mode '{}'".format(device.turbo))
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in operation:
                        wert = mi_enum(ac.OperationalMode, mi_tabellen()['operational_mode'].get(eachArg))
                        if wert is None:
                            _LOGGER.error("unbekannte Betriebsart '{}' - Befehl verworfen".format(eachArg))
                        else:
                            device.operational_mode = wert
                            _LOGGER.debug(device.operational_mode)
                    elif eachArg.startswith("ac.fan_speed_enum."):
                        # Frueher: elif "fan_speed_enum" in eachArg - eine
                        # TEILSTRING-Pruefung. Damit kam jedes Wort hier an,
                        # das die Zeichenfolge irgendwo enthielt.
                        teile = eachArg.split(".")
                        rest = teile[2] if len(teile) > 2 else ''
                        if rest.isdigit():
                            if device.supports_custom_fan_speed:
                                device.fan_speed = int(rest)
                            else:
                                _LOGGER.warning("device is not capable of property {}".format(eachArg))
                        else:
                            wert = mi_enum(ac.FanSpeed, mi_tabellen()['fan_speed'].get(eachArg))
                            if wert is None:
                                _LOGGER.error("unbekannte Luefterstufe '{}' - Befehl verworfen".format(eachArg))
                            else:
                                device.fan_speed = wert
                        _LOGGER.debug(device.fan_speed)
                    elif eachArg in swing_modes:
                        wert = mi_enum(ac.SwingMode, mi_tabellen()['swing_mode'].get(eachArg))
                        if wert is None:
                            _LOGGER.error("unbekannter Schwenkmodus '{}' - Befehl verworfen".format(eachArg))
                        else:
                            device.swing_mode = wert
                            _LOGGER.debug(device.swing_mode)
                    elif len(eachArg) == 2 and eachArg.isdigit():
                        # C5: ausserhalb des Bereichs abweisen, nicht klemmen.
                        if soll_pruefen(device, int(eachArg), eachArg) is not None:
                            device.target_temperature = int(eachArg)
                            _LOGGER.debug(device.target_temperature)
                    elif eachArg.startswith("temp."):
                        # Einstellige Sollwerte (8 Grad Frostschutz) und halbe
                        # Grad gingen bis 4.2.12 nicht: die Erkennung war
                        # len(eachArg) == 2 and isdigit(). "20.5" und "8"
                        # fielen durch und landeten als "unknown" im Protokoll.
                        roh = eachArg.split(".", 1)[1].replace(",", ".")
                        # C5: nan/inf und Werte ausserhalb des Bereichs werden
                        # abgewiesen (soll_pruefen), nicht geklemmt.
                        zahl = soll_pruefen(device, roh, eachArg)
                        if zahl is not None:
                            device.target_temperature = zahl
                            _LOGGER.debug(device.target_temperature)
                    elif eachArg in display:
                        if device.supports_display_control:
                            device.toggle_display()
                            _LOGGER.debug('toggle_Display')
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg.split(".")[0] == "humidity":
                        if device.supports_humidity:
                            # Eine Prozentzahl, nichts sonst. Bis 4.0.0 ging
                            # hier ALLES hinter dem Punkt in eval().
                            roh = eachArg.split(".")[1] if "." in eachArg else ''
                            if roh.isdigit() and 0 <= int(roh) <= 100:
                                device.target_humidity = int(roh)
                                _LOGGER.debug(device.target_humidity)
                            else:
                                _LOGGER.error("ungueltige Sollfeuchte '{}' - Befehl verworfen".format(eachArg))
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg.split(".")[0] == "h_swing_angle":
                        if device.supports_horizontal_swing_angle:
                            wert = mi_enum(ac.SwingAngle, eachArg.split(".")[1] if "." in eachArg else '')
                            if wert is None:
                                _LOGGER.error("unbekannter Schwenkwinkel '{}' - Befehl verworfen".format(eachArg))
                            else:
                                device.horizontal_swing_angle = wert
                                _LOGGER.debug(device.horizontal_swing_angle)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg.split(".")[0] == "v_swing_angle":
                        if device.supports_vertical_swing_angle:
                            wert = mi_enum(ac.SwingAngle, eachArg.split(".")[1] if "." in eachArg else '')
                            if wert is None:
                                _LOGGER.error("unbekannter Schwenkwinkel '{}' - Befehl verworfen".format(eachArg))
                            else:
                                device.vertical_swing_angle = wert
                                _LOGGER.debug(device.vertical_swing_angle)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in freeze:
                        if device.supports_freeze_protection:
                            device.freeze_protection = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.freeze_protection)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in sleep:
                        device.sleep = mi_bool(eachArg.split(".")[1])
                        _LOGGER.debug(device.sleep)
                    elif eachArg in follow:
                        device.follow_me = mi_bool(eachArg.split(".")[1])
                        _LOGGER.debug(device.follow_me)
                    elif eachArg in purifier:
                        if device.supports_purifier:
                            device.purifier = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.purifier)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in self_clean:
                        if device.supports_self_clean:
                            device.start_self_clean()
                            _LOGGER.debug("start self_clean")
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in rate_select:
                        # Verglichen wird der NAME gegen die Namen der
                        # unterstuetzten Werte.
                        #
                        # Bis 4.2.12 stand hier "if eachArg in
                        # device.supported_rate_selects" - eine Zeichenkette
                        # wie "rate_select.GEAR_50" gegen eine Liste von
                        # Aufzaehlungswerten. Der Vergleich war IMMER falsch:
                        # es wurde immer "device is not capable" gewarnt und
                        # der Wert nie gesetzt. Die Funktion konnte gar nicht
                        # arbeiten.
                        name = eachArg.split(".", 1)[1]
                        moeglich = [getattr(r, 'name', str(r))
                                    for r in getattr(device, 'supported_rate_selects', [])]
                        if name in moeglich:
                            wert = mi_enum(ac.RateSelect, name) if hasattr(ac, 'RateSelect') else None
                            # Kennt msmart die Aufzaehlung nicht, wird der
                            # Name unveraendert durchgereicht - so wie es die
                            # Bibliothek bis 4.0.0 erwartet hat.
                            device.rate_select = wert if wert is not None else name
                            _LOGGER.debug(device.rate_select)
                        else:
                            _LOGGER.warning("device is not capable of property {} (moeglich: {})".format(eachArg, moeglich))
                    elif eachArg in breeze_away:
                        if device.supports_breeze_away:
                            device.breeze_away = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.breeze_away)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in breeze_mild:
                        if device.supports_breeze_mild:
                            device.breeze_mild = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.breeze_mild)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in breezeless:
                        if device.supports_breezeless:
                            device.breezeless = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.breezeless)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    elif eachArg in ieco:
                        if device.supports_ieco:
                            device.ieco = mi_bool(eachArg.split(".")[1])
                            _LOGGER.debug(device.ieco)
                        else:
                            _LOGGER.warning("device is not capable of property {}".format(eachArg))
                    else: #unknown key´s
                        if len(eachArg) != 64 and len(eachArg) != 128 and eachArg != device_id and eachArg != device_ip:
                            _LOGGER.error("Given command '{}' is unknown".format(eachArg))

            # Errorhandling
            # Midea AC only supports auto Fanspeed in auto-Operationalmode.
            # Bis 3.4.8 stand hier ein Vergleich gegen support_msmart_ng[...].
            # Das war aus zwei Gruenden falsch: der Schluessel
            # 'ac.fan_speed.auto' existiert in der Tabelle gar nicht (=>
            # KeyError bei jedem Befehl), und die Tabelle liefert Zeichenketten
            # wie 'ac.OperationalMode.AUTO', die niemals gleich device.
            # operational_mode.name ('AUTO') sein koennen. Jetzt werden die
            # Aufzaehlungswerte direkt verglichen.
            if device.operational_mode == ac.OperationalMode.AUTO and device.fan_speed != ac.FanSpeed.AUTO:
                device.fan_speed = ac.FanSpeed.AUTO
                _LOGGER.info("set auto-Fanspeed because of Auto-Operational Mode")
            if device.freeze_protection and device.operational_mode != ac.OperationalMode.HEAT:
                device.operational_mode = ac.OperationalMode.HEAT
                _LOGGER.info("set Heatmode to get into Freezeprotection Mode")

            # Solltemperaturen werden seit 4.5.10 schon beim Lesen des Befehls
            # geprueft (soll_pruefen, C5): ein Wert ausserhalb des Bereichs oder
            # nan/inf wird abgewiesen und protokolliert. Bis 4.5.9 stand hier
            # ein Klemmen auf die Grenze - der Anwender bekam einen anderen
            # Sollwert, als er geschickt hatte, und nan ging ganz durch.

            # commit the changes with apply()
            # Der Wiederholungszaehler faengt hier neu an. Bis 4.2.12 teilten
            # refresh() und apply() sich einen Zaehler: hatte refresh seine
            # zwei Versuche verbraucht, machte apply keinen einzigen mehr.
            retries = 0
            try:
                await device.apply()
                while device.online == False and retries < 2: # retry 2 times on connection error
                    retries += 1
                    _LOGGER.warning("retry apply %s/2" %(retries))
                    await asyncio.sleep(5)
                    await device.apply()
            except Exception as error:
                device._online = False
                _LOGGER.error(error)

        if device.online == True:
            _letzter_erfolg = 1
            if statusupdate == 1:
                _LOGGER.info("Statusupdate for Midea.{} @ {} successful. Runtime: {}s".format(device.id, device.ip,round(time.time()-runtime,2)))
            else:
                _LOGGER.info("Set new state for Midea.{} @ {} successful. Runtime: {}s".format(device.id, device.ip,round(time.time()-runtime,2)))
        else:
            _letzter_erfolg = 0
            _LOGGER.error("Device is offline")

        await send_to_loxone(device, support_mode)

    except Exception as e:
        _letzter_erfolg = 0
        _LOGGER.error(e, exc_info=True)

    finally:
        _LOGGER.debug("{}s".format(round(time.time()-runtime,2)))


def faehigkeiten_protokollieren(device):
    """Die Faehigkeitsliste ins Protokoll - jedes Merkmal fuer sich.

    Frueher war das ein einziges Woerterbuch mit 25 Attributzugriffen. Ein
    AttributeError darin - etwa weil eine msmart-Fassung ein Merkmal
    umbenannt hat - riss die ganze Zeile mit. Jetzt faellt hoechstens ein
    Eintrag aus, und man sieht welcher.
    """
    merkmale = [
        "supported_operation_modes", "supported_swing_modes",
        "supported_fan_speeds", "max_target_temperature",
        "min_target_temperature", "supports_custom_fan_speed",
        "supports_eco", "supports_turbo", "supports_freeze_protection",
        "supports_display_control", "supports_filter_reminder",
        "supports_purifier", "supports_humidity", "supports_target_humidity",
        "supports_self_clean", "supports_horizontal_swing_angle",
        "supports_vertical_swing_angle", "supported_rate_selects",
        "supports_breeze_away", "supports_breeze_mild",
        "supports_breezeless", "supports_ieco",
    ]
    aus = {"device-id": getattr(device, 'id', '?')}
    for m in merkmale:
        try:
            w = getattr(device, m)
            if isinstance(w, (list, tuple, set)):
                w = [str(getattr(x, 'name', x)) for x in w]
            aus[m] = w
        except Exception as fehler:
            aus[m] = 'nicht ermittelbar (%s)' % type(fehler).__name__
    _LOGGER.info("%s", aus)


# ===========================================================================
# Werte an Loxone
# ===========================================================================

async def send_to_loxone(device, support_mode):
    """Den Zustand eines Geraets veroeffentlichen.

    Die Liste entsteht WERT FUER WERT.

    Bis 4.2.12 wurden alle 28 Werte in einem Zug gebaut. Scheiterte einer -
    int(None) bei power_state, .name auf None bei operational_mode, ein
    Geraet ohne Schwenkwinkel -, fing das except die Ausnahme, protokollierte
    sie und machte weiter; addresses blieb dabei UNGEBUNDEN. Der naechste
    Zugriff endete mit UnboundLocalError. Besonders bitter war der Sonderweg
    "Geraet ist offline": er las addresses[10] und scheiterte an derselben
    Stelle. Loxone erfuhr also gerade dann nichts vom Ausfall, wenn das
    Geraet ausgefallen war - und der virtuelle Eingang behielt dank Retain
    seinen letzten Wert. Nachgemessen mit Kontrollfall.
    """
    try:
        geraet_id = device.id
    except Exception:
        _LOGGER.error("Geraet ohne Nummer - es wird nichts veroeffentlicht.")
        return

    online = False
    try:
        online = bool(device.online)
    except Exception:
        pass

    if not online:
        # Der Offline-Weg baut sein EINES Thema selbst, statt in eine Liste
        # zu greifen, die es vielleicht gar nicht gibt.
        await veroeffentlichen([('%s/online' % geraet_id, '0')], support_mode)
        _LOGGER.info("Device is Offline! Status fuer Midea.%s gesendet.", geraet_id)
        return

    paare = []

    def nimm(name, holen, wandeln=str):
        try:
            w = holen()
        except Exception as fehler:
            _LOGGER.debug("Wert '%s' nicht verfuegbar: %s", name, fehler)
            return
        if w is None:
            # M3 (Durchgang 30.09.2026, Entscheidungen 5 und 8): ein Zustand,
            # den ein ERFOLGREICHER Abruf nicht liefert, geht einmal als '-'
            # retained hinaus - nie als stehenbleibender Altwert. Bis 4.5.9
            # wurde er uebersprungen, und im Broker blieb der alte Wert (in
            # WSL gemessen, Bericht mqtt M3: target_humidity 55 blieb stehen).
            # Messwerte (nicht retained) werden weiter nicht gesendet.
            thema = '%s/%s' % (geraet_id, name)
            if support_mode == 0 and retain_fuer(name) and _ZULETZT.get(thema) != '-':
                paare.append((thema, '-'))
            else:
                _LOGGER.debug("Wert '%s' nicht verfuegbar: kein Wert", name)
            return
        try:
            paare.append(('%s/%s' % (geraet_id, name), wandeln(w)))
        except Exception as fehler:
            _LOGGER.debug("Wert '%s' nicht verfuegbar: %s", name, fehler)

    ganz = lambda w: str(int(w))

    nimm('power_state',            lambda: device.power_state, ganz)
    nimm('audible_feedback',       lambda: device.beep, ganz)
    nimm('target_temperature',     lambda: device.target_temperature)
    nimm('operational_mode',       lambda: mi_wort('operational_mode', device.operational_mode),
         lambda w: 'operational_mode_enum.%s' % w)
    nimm('fan_speed',              lambda: (device.fan_speed if isinstance(device.fan_speed, int)
                                            else mi_wort('fan_speed', device.fan_speed)),
         lambda w: str(w) if isinstance(w, int) else 'fan_speed_enum.%s' % w)
    nimm('swing_mode',             lambda: mi_wort('swing_mode', device.swing_mode),
         lambda w: 'swing_mode_enum.%s' % w)
    nimm('eco_mode',               lambda: device.eco, ganz)
    nimm('turbo_mode',             lambda: device.turbo, ganz)
    nimm('indoor_temperature',     lambda: device.indoor_temperature)
    nimm('outdoor_temperature',    lambda: device.outdoor_temperature)
    nimm('display_on',             lambda: device.display_on, ganz)
    nimm('online',                 lambda: device.online, ganz)
    nimm('target_humidity',        lambda: device.target_humidity)
    nimm('indoor_humidity',        lambda: device.indoor_humidity)
    nimm('filter_alert',           lambda: device.filter_alert, ganz)
    # Die beiden Schwenkwinkel haben jetzt eine Faehigkeitsabfrage - der
    # Empfangsweg hatte sie schon immer, der Sendeweg nicht.
    if getattr(device, 'supports_horizontal_swing_angle', False):
        nimm('horizontal_swing_angle', lambda: getattr(device.horizontal_swing_angle, 'name', None))
    if getattr(device, 'supports_vertical_swing_angle', False):
        nimm('vertical_swing_angle',   lambda: getattr(device.vertical_swing_angle, 'name', None))
    nimm('freeze_protection_mode', lambda: device.freeze_protection, ganz)
    nimm('sleep_mode',             lambda: device.sleep, ganz)
    nimm('follow_me',              lambda: device.follow_me, ganz)
    nimm('purifier',               lambda: device.purifier, ganz)
    nimm('total_energy_usage',     lambda: device.total_energy_usage)
    nimm('current_energy_usage',   lambda: device.current_energy_usage)
    nimm('real_time_power_usage',  lambda: device.real_time_power_usage)
    nimm('self_clean_active',      lambda: device.self_clean_active, ganz)
    nimm('rate_select',            lambda: getattr(device.rate_select, 'name', device.rate_select))
    nimm('breeze_mode',            lambda: getattr(device._breeze_mode, 'name', device._breeze_mode))
    nimm('ieco',                   lambda: device.ieco, ganz)

    if not paare:
        _LOGGER.error("Kein einziger Wert von Midea.%s lesbar - nichts gesendet.", geraet_id)
        return

    await veroeffentlichen(paare, support_mode)
    _LOGGER.info("Device is Online! %d Werte fuer Midea.%s gesendet.", len(paare), geraet_id)


async def _im_faden(arbeit):
    """Blockierendes ausserhalb der Ereignisschleife laufen lassen.

    Der Grund, gemessen: veroeffentlichen() war zwar als Koroutine
    geschrieben, enthielt aber KEIN einziges await - nur
    wait_for_publish() und requests.get(), die beide blockieren. Damit
    stand die ganze Schleife, solange der Broker oder der Miniserver
    schwieg: bis zu 28 Werte je Geraet mal lox_timeout. In dieser Zeit lief
    weder der Empfang noch der Herzschlag, und der Reiter Test zeigte einen
    roten Herzschlag bei kerngesundem Dienst.
    """
    return await asyncio.get_running_loop().run_in_executor(None, arbeit)


# ---------------------------------------------------------------------------
# Retain wird JE THEMA entschieden (ab 4.5.4)
# ---------------------------------------------------------------------------
#
# Hausstandard seit 03.09.2026: Zustaende retained, Messwerte mit Zeitbezug
# nicht, das Lebenszeichen nie.
#
# Die Begruendung fuer das Lebenszeichen ist die wichtigste: retained zeigte
# es immer "lebt". Nach einem Neustart des Miniservers stuende der letzte
# Herzschlag sofort wieder da - auch dann, wenn der Dienst laengst tot ist.
# Ein Lebenszeichen, das den eigenen Tod ueberlebt, ist keines.
#
# Messwerte mit Zeitbezug (Temperatur, Leistung, Zaehlerstand) sind aus
# demselben Grund nicht retained: ein alter Wert saehe aus wie ein aktueller.
# Der virtuelle Eingang in Loxone traegt dafuer seinen Fehlwert nach Ablauf.
#
# Zustaende dagegen GEHOEREN retained: an/aus, Betriebsart, Sollwert,
# Erreichbarkeit. Sie gelten weiter, bis etwas anderes gemeldet wird, und
# Loxone hat sie nach einem Neustart sofort.
#
# Am Broker gemessen (13.09.2026, Midea2Lox 4.5.3): fuenf retained Themen -
# und vier davon waren das Lebenszeichen.
#
# SEIT 4.5.9 EINE POSITIVLISTE, UND SIE STEHT IN mi_mqtt.py.
#
# Bis 4.5.8 stand hier die Liste der Themen OHNE Retain; alles andere ging
# retained hinaus - ein neues Thema also still zurueckbehalten. Gemessen am
# empfangenen Paket (Pruefung-Midea2Lox-4.5.9, Faelle R5, R8-R13): online
# eines Geraets und die vier Themen der Automatik kamen retained an, obwohl
# sie Aussagen des Dienstes sind (Regeln/07, Entscheidung 19.09.2026) bzw.
# eine Restzeit tragen. Die Erreichbarkeit gehoert deshalb NICHT mehr zu den
# Zustaenden oben: sie setzt der Dienst selbst (device._online = False in
# send_to_midea), und retained stuende nach seinem Tod die letzte 1 da.
# Die Liste steht in mi_mqtt.py, weil uninstall/uninstall sie ebenfalls
# braucht (mi_mqtt.py --mqtt-leeren) und dieses Modul beim Import den ganzen
# Dienst aufbaut. mi_mqtt wird unten mit den uebrigen Bibliotheken geladen.
def retain_fuer(thema):
    """Geht dieses Thema retained hinaus? Entscheidung: mi_mqtt.retain_fuer()."""
    return mi_mqtt.retain_fuer(thema)

# Der zuletzt veroeffentlichte Wert je Thema (ohne Praefix). Gebraucht nur
# beim einmaligen Abraeumen der Altwerte: unmittelbar nach der Loeschung geht
# der gueltige Wert hinterher, denn das MQTT-Gateway reicht eine Loeschung
# als leeren Wert an den Miniserver weiter (Regeln/07; Bauart APC-UPS 1.2.13).
_ZULETZT = {}


async def veroeffentlichen(paare, support_mode=0):
    """Ueber MQTT, sonst per HTTP an virtuelle Eingaenge.

    paare ist eine Liste aus (Thema ohne Praefix, Wert).
    """
    if support_mode == 0:
        for thema, wert in paare:
            _ZULETZT[thema] = wert
    if MQTT == 1 and support_mode == 0 and mqtt_error == 0:
        for thema, wert in paare:
            try:
                publish = client.publish(MQTT_PRAEFIX + '/' + thema, wert,
                                         qos=2, retain=retain_fuer(thema))
                # MIT Zeitgrenze. Bis 4.2.12 stand hier wait_for_publish()
                # ohne Argument: QoS 2 verlangt den vollen Vier-Wege-
                # Handschlag, und brach der Broker waehrend des Sendens weg,
                # wartete paho unbegrenzt. Weil der Empfang im selben Ablauf
                # lag, nahm der Dienst dann keine Pakete mehr an - und der
                # Waechter griff nicht, weil der Prozess lebte.
                try:
                    await _im_faden(
                        lambda: publish.wait_for_publish(timeout=LOX_TIMEOUT))
                except TypeError:
                    # paho vor 1.6 kennt das Argument nicht.
                    await _im_faden(publish.wait_for_publish)
                _LOGGER.debug("Publishing: MsgNum:%s: %s = %s",
                              publish.mid, thema, wert)
            except Exception as fehler:
                _LOGGER.error("MQTT: %s konnte nicht gesendet werden: %s", thema, fehler)
        return

    # HTTP-Weg
    address_loxone = ("http://%s:%s@%s:%s/dev/sps/io/"
                      % (quote(str(LoxUser), safe=''), quote(str(LoxPassword), safe=''),
                         LoxIP, LoxPort))
    for thema, wert in paare:
        if support_mode == 1:   # Loxone-Konfigurationen aus Midea2Lox V2.x
            name = ('Midea/' + thema).replace('/', '.')
        else:
            name = MQTT_PRAEFIX + '_' + thema.replace('/', '_')
        ziel = address_loxone + name + '/' + str(wert)
        try:
            # Ohne Zeitgrenze wartet requests unbegrenzt. Haengt der
            # Miniserver, steht der Dienst.
            r = await _im_faden(lambda: requests.get(ziel, timeout=LOX_TIMEOUT))
            if r.status_code != 200:
                _LOGGER.error("Error %s on set Loxone Input '%s', please check user, "
                              "password and IP of the Miniserver in the LoxBerry "
                              "configuration and the names of the Loxone inputs.",
                              r.status_code, name)
        except requests.exceptions.Timeout:
            _LOGGER.error("Miniserver %s hat innerhalb von %s s nicht geantwortet - "
                          "Wert '%s' verworfen.", LoxIP, LOX_TIMEOUT, name)
        except requests.exceptions.RequestException as fehler:
            _LOGGER.error("Miniserver %s nicht erreichbar: %s", LoxIP, fehler)


# ===========================================================================
# MQTT
# ===========================================================================

# Klartext der CONNACK-Codes, fuer BEIDE Zaehlweisen.
#
# paho 1.x liefert die Codes aus MQTT 3.1.1 (1-5), paho 2.x bildet
# dieselben Faelle auf die Ursachencodes von MQTT 5 ab (132-136) - und
# dieser Dienst legt den Client mit CallbackAPIVersion.VERSION2 an, sobald
# paho 2.x vorliegt. Bis 4.5.2 standen hier nur 1-5; im venv dieses Plugins
# steckt paho 1.6.1 (am Geraet gemessen 11.09.2026), deshalb fiel es nicht
# auf. Nach einem paho-2-Upgrade haette das Protokoll nur noch
# "Ungueltiger Rueckgabecode 135" gemeldet - also die Zahl statt des
# Grundes, und ausgerechnet bei falschen Zugangsdaten.
#
# Am echten Mosquitto gemessen (11.09.2026): falsches Kennwort UND anonyme
# Anmeldung ergeben beide 5 bzw. 135 („nicht berechtigt"), nie 4/134.
CONNACK_KLARTEXT = {
    1: "Falsche Protokollversion", 132: "Falsche Protokollversion",
    2: "Identifizierung fehlgeschlagen", 133: "Identifizierung fehlgeschlagen",
    3: "Server nicht erreichbar", 136: "Server nicht erreichbar",
    4: "Falscher Benutzername oder Passwort", 134: "Falscher Benutzername oder Passwort",
    5: "Nicht autorisiert", 135: "Nicht autorisiert",
}

# Ist ein Callback, der ausgefuehrt wird, wenn sich mit dem Broker verbunden wird
def on_connect(client, userdata, flags, rc, properties=None):
    global mqtt_error
    mqtt_error = 1
    if rc == 0:
        _LOGGER.info("MQTT: Verbindung akzeptiert")
        mqtt_error = 0
        publish = client.publish(MQTT_PRAEFIX + '/connection/status', 'connected',
                                 qos=2, retain=True)
        _LOGGER.debug("Publishing: MsgNum:%s: connection/status = connected", publish.mid)
        # M6 (Durchgang 30.09.2026): nach JEDEM Verbinden die zuletzt
        # gesendeten Zustaende einmal vollstaendig retained senden (Regeln/07
        # Abschnitt 2). Bis 4.5.9 kam nach einem Broker-Neustart ohne
        # gespeicherte Werte nur connection/status - die Zustaende fehlten,
        # bis Loxone wieder "<ID> status" schickte (in WSL gemessen, Bericht
        # mqtt M6). Beim ersten Verbinden ist die Liste leer. client.publish()
        # wartet nicht; der Netzwerkfaden wird nicht aufgehalten.
        try:
            erneut = [(t, w) for t, w in list(_ZULETZT.items()) if retain_fuer(t)]
        except RuntimeError:
            erneut = []
        for _t, _w in erneut:
            client.publish(MQTT_PRAEFIX + '/' + _t, _w, qos=2, retain=True)
        if erneut:
            _LOGGER.info("MQTT: nach dem Verbinden %d Zustaende erneut gesendet (retained).",
                         len(erneut))
        # Die Abonnements gehoeren HIERHER, nicht neben den Verbindungsaufbau.
        #
        # Ein subscribe(), das einmal beim Start steht, ist nach dem ersten
        # Abriss weg: der Broker vergisst die Abonnements einer getrennten
        # Sitzung (clean session). Am 31.08.2026 ist gemessen worden, dass
        # dieser Dienst nach einem Abriss wirklich neu verbindet - dann muss
        # er auch neu abonnieren, sonst laeuft die Automatik ab dem ersten
        # Netzhaenger blind weiter.
        # c1: dazu die Fensterthemen (leer, solange die Kopplung aus ist).
        for _thema in (AUTO_THEMA_REGEL, AUTO_THEMA_PV) + tuple(sorted(FENSTER_THEMEN)):
            if _thema:
                try:
                    client.subscribe(_thema, qos=0)
                    _LOGGER.info("MQTT: abonniert %s", _thema)
                except Exception as fehler:
                    _LOGGER.error("MQTT: %s liess sich nicht abonnieren: %s",
                                  _thema, fehler)
        # Altwerte frueherer Fassungen - in einem eigenen Faden, auf einer
        # eigenen Verbindung; dieser Rueckruf laeuft im Netzwerkfaden von paho.
        altlast_anstossen()
        # Fruehere Praefixe (M1) und entfernte Geraete (M2) - ebenso.
        nachlauf_anstossen()
    else:
        # Den Code als Zahl lesen, gleich welcher Rueckruffassung: paho 2.x
        # uebergibt ein ReasonCode-Objekt, paho 1.x eine Zahl.
        try:
            code = int(getattr(rc, 'value', rc))
        except (TypeError, ValueError):
            code = rc
        grund = CONNACK_KLARTEXT.get(code)
        if grund:
            _LOGGER.error("MQTT: %s (Code %s)", grund, code)
        else:
            # Nicht benennbar ist nicht in Ordnung: die Zahl wird genannt,
            # damit sie nachschlagbar bleibt.
            _LOGGER.error("MQTT: Anmeldung abgelehnt, Code %s ohne bekannte "
                          "Bedeutung", code)
        # a1: die lange Kennung abgewiesen (CONNACK 2 bzw. 133, etwa ein
        # Broker, der nur MQTT 3.1 mit hoechstens 23 Zeichen spricht). paho
        # verbindet von selbst neu - dann mit der kurzen Form. Ueber
        # _client_id, weil paho 1.6.1 und 2.x die Kennung nur im Konstruktor
        # annehmen; beide lesen sie bei jedem Verbinden aus diesem Feld.
        if code in (2, 133) and hasattr(client, '_client_id'):
            kurz = KENNUNG_KURZ.encode('utf-8')
            if client._client_id != kurz:
                client._client_id = kurz
                _LOGGER.warning("MQTT: der Broker lehnt die Client-Kennung %s ab (%d Zeichen) - "
                                "der naechste Versuch nimmt die kurze Form %s (23 Zeichen, "
                                "MQTT 3.1).", KENNUNG, len(KENNUNG), KENNUNG_KURZ)


def on_message(client, userdata, nachricht):
    """Ein abonnierter Wert ist eingetroffen.

    Laeuft im Netzwerkfaden von paho. Hier wird deshalb NICHTS entschieden
    und nichts an ein Geraet geschickt - der Wert wird nur abgelegt. Was
    daraus folgt, entscheidet automatik_schleife() in der Ereignisschleife.
    """
    try:
        text = nachricht.payload.decode('utf-8', 'replace').strip()
    except Exception:
        return
    # C2: das Retain-Merkmal auswerten - siehe abo_merken().
    zurueck = bool(getattr(nachricht, 'retain', False))
    abo_merken(nachricht.topic, text, zurueck)
    # c1: Fensterthemen zusaetzlich fuer die Kopplung merken.
    fenster_merken(nachricht.topic, text, zurueck)
    _LOGGER.debug("MQTT empfangen: %s = %s%s", nachricht.topic, text[:40],
                  " (zurueckbehalten, Alter unbekannt - zaehlt nicht)" if zurueck else "")


def on_disconnect(client, userdata, *rest):
    """Beim Trennen.

    paho ruft hier VERSCHIEDEN, am Geraet an 2.1.0 gemessen (06.09.2026):
    VERSION1 mit drei Argumenten (client, userdata, rc), VERSION2 mit fuenf
    (client, userdata, DisconnectFlags, ReasonCode, Properties). Die
    Argumente VERSCHIEBEN sich also - das dritte ist unter VERSION2 nicht
    der Code, sondern die Flags.

    Bis 4.2.12 standen hier VIER Pflichtparameter - das passte zu KEINER von
    beiden. Danach vier mit Vorgabewerten; das nahm zwar die Zahl der
    Argumente hin, las unter VERSION2 aber die Flags als Code und haette
    jeden sauberen Abschied mit "rc=DisconnectFlags(...)" protokolliert.
    """
    global mqtt_error
    mqtt_error = 1
    rc = rest[1] if len(rest) >= 3 else (rest[0] if rest else 0)
    _LOGGER.info("MQTT Disconnected (rc=%s)", rc)


# ---------------------------------------------------------------------------
# Altwerte frueherer Fassungen EINMAL abraeumen (ab 4.5.9)
# ---------------------------------------------------------------------------
#
# Was bis 4.5.8 retained hinausging und heute fluechtig geht (online eines
# Geraets, automatik/*; aus 4.5.3 und frueher auch das Lebenszeichen und die
# Messwerte - Liste in mi_mqtt.py), liegt sonst fuer immer im Broker und
# kommt nach jedem Neustart von Broker oder Gateway als frische Aussage beim
# Miniserver an. Ein Wert verschwindet nicht dadurch, dass niemand ihn mehr
# sendet (am Broker gemessen 13.09.2026, siehe README 4.5.4).
#
# Am Broker, nicht blind (mi_mqtt.broker_leeren): geloescht wird nur, was
# wirklich retained liegt, danach wird NACHGELESEN, und erst dann faellt der
# Merker. CONNACK ungleich 0 und SUBACK 0x80 heissen "nicht zu fragen" - kein
# Merker, neuer Versuch nach zehn Minuten (aus dem Herzschlag) oder beim
# naechsten Verbinden. Der Merker traegt Praefix und Themenliste
# (mi_mqtt.altlast_kennung); eine Vorfassung hat keinen und kann nichts
# vortaeuschen. Gemessen in WSL gegen einen eigenen Broker
# (Pruefung-Midea2Lox-4.5.9, Faelle A1-A12).
_ALTLAST = {'erledigt': '', 'naechster': 0.0, 'laeuft': False}


def _altlast_merker():
    return os.path.join(data_path, 'retain_altlast')


def altlast_anstossen():
    """Startet das Abraeumen in einem eigenen Faden, wenn es faellig ist.

    Gefragt wird nach client und mqtt_error, nicht nach MQTT: on_connect kann
    im Netzwerkfaden kommen, bevor der Modulrumpf die Zeile "MQTT = 1" hinter
    loop_start() erreicht hat.
    """
    if client is None or mqtt_error != 0:
        return
    kennung = mi_mqtt.altlast_kennung(MQTT_PRAEFIX)
    if _ALTLAST['erledigt'] == kennung or _ALTLAST['laeuft']:
        return
    try:
        with open(_altlast_merker(), encoding='utf-8') as f:
            if f.read().strip() == kennung:
                _ALTLAST['erledigt'] = kennung
                return
    except OSError:
        pass
    jetzt = time.time()
    if jetzt < _ALTLAST['naechster']:
        return
    _ALTLAST['naechster'] = jetzt + 600
    _ALTLAST['laeuft'] = True
    threading.Thread(target=_altlast_abraeumen, args=(kennung,), daemon=True).start()


def _altlast_abraeumen(kennung):
    try:
        praefix = MQTT_PRAEFIX

        def auswahl(thema):
            return (thema.startswith(praefix + '/')
                    and mi_mqtt.altlast_thema(thema[len(praefix) + 1:]))

        def hinterher(geleert):
            # Unmittelbar nach der Loeschung, noch vor dem Nachlesen, den
            # gueltigen Wert fluechtig hinterher - sofern es schon einen gibt.
            for thema in geleert:
                unter = thema[len(praefix) + 1:]
                if unter in _ZULETZT and client is not None and mqtt_error == 0:
                    client.publish(thema, _ZULETZT[unter], qos=1,
                                   retain=retain_fuer(unter))

        zugang = {'host': MQTThost, 'port': int(MQTTport),
                  'user': MQTTuser, 'pass': MQTTpass}
        erg = mi_mqtt.broker_leeren(zugang, praefix, auswahl, nach_loeschen=hinterher)
        if erg['rc'] == 0:
            _ALTLAST['erledigt'] = kennung
            if erg['geleert']:
                _LOGGER.info("MQTT: %d zurueckbehaltene Altwerte frueherer Fassungen "
                             "geloescht und nachgelesen (%s).", len(erg['geleert']),
                             ', '.join(erg['geleert']))
            try:
                ziel = _altlast_merker()
                with open(ziel + '.tmp', 'w', encoding='utf-8') as f:
                    f.write(kennung + '\n')
                os.replace(ziel + '.tmp', ziel)
            except OSError as fehler:
                _LOGGER.warning("MQTT: der Merker %s liess sich nicht schreiben (%s) - "
                                "nach dem naechsten Start wird noch einmal nachgelesen.",
                                _altlast_merker(), fehler)
        elif erg['rc'] == 1:
            _LOGGER.warning("MQTT: %d zurueckbehaltene Altwerte stehen nach dem Loeschen "
                            "noch im Broker (zum Beispiel %s) - neuer Versuch in zehn "
                            "Minuten.", len(erg['rest']), erg['rest'][0])
        else:
            _LOGGER.warning("MQTT: zurueckbehaltene Altwerte frueherer Fassungen nicht "
                            "abgeraeumt - %s. Neuer Versuch in zehn Minuten.", erg['grund'])
    except Exception as fehler:
        _LOGGER.error("MQTT: Abraeumen der Altwerte gescheitert: %s", fehler, exc_info=True)
    finally:
        _ALTLAST['laeuft'] = False


# ---------------------------------------------------------------------------
# Nachlauf nach dem Verbinden (ab 4.5.10): fruehere Praefixe (M1) und
# entfernte Geraete (M2)
# ---------------------------------------------------------------------------
#
# M1: Bis 4.5.9 blieben nach einem Praefixwechsel (Reiter MQTT oder
# Zurueckspielen einer Sicherung) alle retained Themen des alten Zweigs
# stehen, dazu der Letzte Wille "disconnected" des beendeten Dienstes - 19
# Themen, und die Deinstallation raeumte nur das eingestellte Praefix ab (in
# WSL gemessen, Bericht mqtt M1). Jetzt fuehrt die Linie eine Liste der
# Praefixe, unter denen sie gesendet hat (config/plugins/<ordner>.mqtt_praefixe,
# NEBEN dem Konfigordner, damit sie ein Update uebersteht). Nach dem
# Verbinden traegt der Dienst sein Praefix ein und raeumt jedes andere ab,
# ueber eine eigene TCP-Verbindung mit Nachlesen (mi_mqtt.broker_leeren);
# erst nach Erfolg faellt es aus der Liste. Die Deinstallation raeumt alle
# gemerkten ab. Bauform Heimkino 1.3.15.
#
# M2: Ein aus devices.cfg entferntes Geraet (Zurueckspielen, Handaenderung)
# meldete in Loxone nach jedem Neustart weiter seine alten Zustaende (in WSL
# gemessen, Bericht mqtt M2: 17 retained, 0 PUB). Entscheidung 8: fuer ein
# entferntes Geraet einmal '-' retained. Dazu wird am Broker nachgesehen,
# welche Geraetenummern unter dem Praefix Zustaende tragen, die devices.cfg
# nicht mehr nennt; deren Zustaende gehen einmal auf '-', danach wird
# nachgelesen. Ist devices.cfg nicht lesbar, wird NICHTS gesetzt - eine
# unlesbare Datei ist kein leeres Haus.
_NACHLAUF = {'praefixe': False, 'geraete': None, 'naechster': 0.0, 'laeuft': False}


def geraete_ids_lesen():
    """Die Geraetenummern aus devices.cfg - oder None, wenn die Datei fehlt
    oder nicht lesbar ist (dann darf M2 nichts setzen)."""
    datei = cfg_path + '/devices.cfg'
    if not os.path.isfile(datei):
        return None
    try:
        c = configparser.RawConfigParser()
        if not c.read(datei):
            return None
    except (configparser.Error, OSError, UnicodeError):
        return None
    aus = set()
    for ab in c.sections():
        if c.has_option(ab, 'id'):
            aus.add(str(c.get(ab, 'id')).strip())
        elif ab.startswith('Midea_'):
            aus.add(ab[6:])
    return tuple(sorted(x for x in aus if x.isdigit()))


def nachlauf_anstossen():
    """Startet den Nachlauf in einem eigenen Faden, wenn etwas offen ist."""
    if client is None or mqtt_error != 0 or _NACHLAUF['laeuft']:
        return
    ids = geraete_ids_lesen()
    offen = (not _NACHLAUF['praefixe']) or (ids is not None and _NACHLAUF['geraete'] != ids)
    if not offen or time.time() < _NACHLAUF['naechster']:
        return
    _NACHLAUF['laeuft'] = True
    threading.Thread(target=_nachlauf, args=(ids,), daemon=True).start()


def _nachlauf(ids):
    try:
        zugang = {'host': MQTThost, 'port': int(MQTTport),
                  'user': MQTTuser, 'pass': MQTTpass}
        gut = True
        if not _NACHLAUF['praefixe']:
            erg = mi_mqtt.praefixe_abraeumen(zugang, MQTT_PRAEFIX,
                                             mi_mqtt.praefixe_datei(cfg_path))
            for zeile in erg['meldungen']:
                (_LOGGER.info if erg['rc'] == 0 else _LOGGER.warning)("MQTT: %s", zeile)
            if erg['rc'] == 0:
                _NACHLAUF['praefixe'] = True
            else:
                gut = False
        if ids is not None and _NACHLAUF['geraete'] != ids:
            bekannt = set(ids)
            praefix = MQTT_PRAEFIX

            def auswahl(thema):
                if not thema.startswith(praefix + '/'):
                    return False
                paar = mi_mqtt.geraet_und_wert(thema[len(praefix) + 1:])
                return (paar is not None and paar[0] not in bekannt
                        and paar[1] in mi_mqtt.MIT_RETAIN)

            erg = mi_mqtt.broker_leeren(zugang, praefix, auswahl, ersatz='-')
            if erg['rc'] == 0:
                _NACHLAUF['geraete'] = ids
                if erg['geleert']:
                    _LOGGER.info("MQTT: %d Zustaende entfernter Geraete einmal auf '-' "
                                 "gesetzt und nachgelesen (%s).", len(erg['geleert']),
                                 ', '.join(erg['geleert']))
            else:
                gut = False
                _LOGGER.warning("MQTT: Zustaende entfernter Geraete nicht auf '-' gesetzt - "
                                "%s. Neuer Versuch in zehn Minuten.",
                                erg['grund'] or ('%d stehen noch' % len(erg['rest'])))
        _NACHLAUF['naechster'] = 0.0 if gut else time.time() + 600
    except Exception as fehler:
        _NACHLAUF['naechster'] = time.time() + 600
        _LOGGER.error("MQTT: Nachlauf gescheitert: %s", fehler, exc_info=True)
    finally:
        _NACHLAUF['laeuft'] = False


##########

try:
    import asyncio
    import json
    import time
    import configparser
    from ipaddress import ip_address, IPv4Address
    from urllib.parse import quote
    import math
    import socket

    from msmart.device import AirConditioner as ac
    from msmart import __version__
    import requests
    import paho.mqtt.client as mqtt
    # Retain-Liste und Abraeumen am Broker (ab 4.5.9), liegt neben diesem
    # Programm im Datenordner.
    import mi_mqtt

    # Ein Schloss um jede Unterhaltung mit einem Geraet. Damit bleibt die
    # Reihenfolge genau die, die bis 4.2.12 galt - siehe Kopfkommentar.
    GERAETE_SCHLOSS = asyncio.Lock()

    # Miniserver Daten Laden
    cfg = configparser.RawConfigParser()
    cfg.read(cfg_path + '/midea2lox.cfg')
except Exception as fehler:
    # Ohne die Bibliotheken geht gar nichts. Der Klartext gehoert in die
    # Ausgabe, nicht eine SystemExit-Spur.
    logging.basicConfig(level=logging.INFO, filename=log_path + '/midea2lox.log',
                        format='%(asctime)s %(name)-12s %(levelname)-8s %(message)s',
                        datefmt='%d.%m.%Y %H:%M:%S')
    logging.getLogger("Midea2Lox.py").error("Start nicht moeglich: %s", fehler, exc_info=True)
    print('Midea2Lox: Start nicht moeglich: %s' % fehler)
    sys.exit(1)


def _cfg_zahl(schluessel, vorgabe, klein, gross):
    """Eine Zahl aus der Konfiguration - mit Grenzen und mit Meldung.

    Ein stiller Vorgabewert ist eine Annahme, keine Auskunft: er ist von
    einer gewaehlten Zahl nicht mehr zu unterscheiden. Deshalb wird jeder
    Rueckfall AUFGESCHRIEBEN.
    """
    try:
        roh = cfg.get('default', schluessel)
    except (configparser.NoOptionError, configparser.NoSectionError):
        _LOGGER.warning("Schluessel '%s' fehlt in midea2lox.cfg - es gilt %s. "
                        "Bitte die Einstellungen einmal speichern.", schluessel, vorgabe)
        return vorgabe
    try:
        n = int(str(roh).strip())
    except ValueError:
        _LOGGER.warning("Schluessel '%s' ist keine Zahl (%r) - es gilt %s.",
                        schluessel, roh, vorgabe)
        return vorgabe
    if n < klein or n > gross:
        _LOGGER.warning("Schluessel '%s' liegt mit %s ausserhalb von %s..%s - "
                        "es gilt %s.", schluessel, n, klein, gross, vorgabe)
        return vorgabe
    return n


# ---------------------------------------------------------------------------
# Protokoll einrichten - VOR jedem Lesen der Konfiguration, damit auch ein
# Fehler beim Lesen im Protokoll steht.
#
# RotatingFileHandler statt basicConfig plus Selbstleeren. Bis 4.0.0 wurde
# die Datei in der Empfangsschleife bei 500 kB einfach ueberschrieben
# (open(..., 'w+')). Damit war der gesamte bisherige Verlauf fort - genau
# dann, wenn er am ehesten gebraucht wird, naemlich nach laengerer Stoerung.
# ---------------------------------------------------------------------------
_LOGGER = logging.getLogger("Midea2Lox.py")
try:
    DEBUG = cfg.get('default', 'DEBUG')
except (configparser.NoOptionError, configparser.NoSectionError):
    DEBUG = '0'

import logging.handlers
os.makedirs(log_path, exist_ok=True)
_stufe = logging.DEBUG if DEBUG == "1" else logging.INFO
class WachsameRotation(logging.handlers.RotatingFileHandler):
    """Umlaufender Protokollhandler, der eine geloeschte Datei neu oeffnet.

    `log/plugins` liegt auf einer Ramdisk (zram). Wird sie geleert, raeumt
    LoxBerrys `log_maint` auf, oder loescht jemand die Datei von Hand, dann
    schreibt ein einmal geoeffneter Handler bis zum Prozessende in einen
    Inode, den es nicht mehr gibt - ohne Fehlermeldung, ohne Datei, ohne
    Hinweis. Am Geraet gemessen (06.09.2026, Python 3.13.5): FileHandler und
    RotatingFileHandler verlieren die Zeile, WatchedFileHandler nicht.

    Die Standardbibliothek hat den WatchedFileHandler, aber nicht zusammen
    mit dem Umlauf. Deshalb hier beides: vor jeder Zeile Geraetenummer und
    Inode vergleichen, bei Abweichung neu oeffnen, nach jedem Umlauf die
    Kennung nachfuehren.
    """

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._kennung = self._kennung_lesen()

    def _kennung_lesen(self):
        """(Geraetenummer, Inode) der Datei - None, wenn es sie nicht gibt."""
        try:
            s = os.stat(self.baseFilename)
        except OSError:
            return None
        return (s.st_dev, s.st_ino)

    def _nachfassen(self):
        """Neu oeffnen, wenn unter dem offenen Deskriptor eine andere (oder
        gar keine) Datei mehr liegt."""
        if self._kennung_lesen() == self._kennung:
            return
        if self.stream is not None:
            try:
                self.stream.flush()
            finally:
                self.stream.close()
                self.stream = None
        self.stream = self._open()
        self._kennung = self._kennung_lesen()

    def emit(self, record):
        try:
            self._nachfassen()
        except Exception:
            # Ein Fehlschlag beim Nachfassen darf die Zeile nicht kosten:
            # lieber in den alten Deskriptor schreiben als gar nicht.
            pass
        super().emit(record)

    def doRollover(self):
        super().doRollover()
        self._kennung = self._kennung_lesen()


_handler = WachsameRotation(
    log_path + '/midea2lox.log', maxBytes=500000, backupCount=1, encoding='utf-8')
_handler.setFormatter(logging.Formatter(
    '%(asctime)s %(name)-12s %(levelname)-8s :%(lineno)d %(message)s',
    # Mit Jahr und Sekunden. Ohne Jahr ist ueber einen Jahreswechsel hinweg
    # die Reihenfolge im Protokoll nicht mehr entscheidbar, und ohne
    # Sekunden liegen bis zu sechzig Zeilen ununterscheidbar nebeneinander.
    datefmt='%d.%m.%Y %H:%M:%S'))
logging.basicConfig(level=_stufe, handlers=[_handler])
if DEBUG == "1":
    print("Debug is True")
    _LOGGER.debug("Debug is True")

# ---------------------------------------------------------------------------
# Konfiguration
#
# Bis 4.2.12 stand hier ein sys.exit('wrong configuration, ...') in einem
# inneren try, umschlossen von einem NACKTEN "except:". SystemExit erbt von
# BaseException - das aeussere except fing es also, protokollierte
# sys.exc_info() statt des Klartexts und rief danach sys.exit() OHNE Code,
# also Rueckgabewert 0. Der Dienst starb beim Start und meldete Erfolg;
# der minuetliche Waechter startete ihn endlos neu. Nachgemessen mit
# Kontrollfall.
# ---------------------------------------------------------------------------
_fehlt = []
for _s in ('UDP_PORT', 'LoxberryIP', 'MINISERVER'):
    try:
        cfg.get('default', _s)
    except (configparser.NoOptionError, configparser.NoSectionError):
        _fehlt.append(_s)
if _fehlt:
    _LOGGER.error("Die Konfiguration ist unvollstaendig - es fehlen: %s. "
                  "Bitte im Plugin Miniserver und UDP-Port eintragen und "
                  "\"Speichern und Dienst neu starten\" druecken.", ', '.join(_fehlt))
    print('Midea2Lox: Konfiguration unvollstaendig (%s)' % ', '.join(_fehlt))
    sys.exit(1)

# --- Automatik (ab 4.5.0) ---------------------------------------------------
#
# AB WERK AUS. Eine Funktion, die von sich aus in ein Geraet greift, wird
# nicht durch ein Update eingeschaltet - der Anwender schaltet sie ein,
# nachdem er die Themen eingetragen hat.


def _cfg_text(schluessel, vorgabe=''):
    try:
        return str(cfg.get('default', schluessel)).strip()
    except Exception:
        return vorgabe


AUTO_EIN = _cfg_zahl('auto_ein', 0, 0, 1) == 1
AUTO_THEMA_REGEL = _cfg_text('auto_thema_regel')
AUTO_THEMA_PV = _cfg_text('auto_thema_pv')
AUTO_PV_AB = _cfg_zahl('auto_pv_ab', 1500, 0, 100000)
AUTO_VERSCHIEBUNG = _cfg_zahl('auto_verschiebung', 20, 0, 100) / 10.0
AUTO_SOLL_MIN = _cfg_zahl('auto_soll_min', 16, 5, 35)
AUTO_SOLL_MAX = _cfg_zahl('auto_soll_max', 30, 5, 35)
AUTO_MAX_ALTER = _cfg_zahl('auto_max_alter', 900, 60, 86400)
AUTO_SPERRZEIT = _cfg_zahl('auto_sperrzeit', 120, 0, 1440) * 60
AUTO_TAKT = _cfg_zahl('auto_takt', 300, 60, 3600)
AUTO_TURBO = _cfg_zahl('auto_turbo', 0, 0, 1) == 1
AUTO_SCHALTEN = _cfg_zahl('auto_schalten', 0, 0, 1) == 1
AUTO_GERAETE = [x.strip() for x in _cfg_text('auto_geraete').split(',') if x.strip()]

if AUTO_SOLL_MIN > AUTO_SOLL_MAX:
    _LOGGER.warning("auto_soll_min (%s) ist groesser als auto_soll_max (%s) - "
                    "die Automatik bleibt AUS.", AUTO_SOLL_MIN, AUTO_SOLL_MAX)
    AUTO_EIN = False

if AUTO_EIN and not AUTO_THEMA_REGEL and not AUTO_THEMA_PV:
    _LOGGER.warning("Die Automatik ist eingeschaltet, aber es ist KEIN Thema "
                    "eingetragen - sie kann nichts entscheiden und bleibt aus.")
    AUTO_EIN = False

# --- Fenster offen -> Klimageraet aus (Verbesserungsbau 30.09.2026, c1) -----
# Ein Stand ohne diese Schluessel (Update, die Oberflaeche noch nicht
# geoeffnet) heisst schlicht "aus" - ohne die Warnung von _cfg_zahl().


def _fenster_zahl(schluessel, vorgabe, klein, gross):
    if not cfg.has_option('default', schluessel):
        return vorgabe
    return _cfg_zahl(schluessel, vorgabe, klein, gross)


FENSTER_EIN = _fenster_zahl('fenster_ein', 0, 0, 1) == 1
FENSTER_FRIST = _fenster_zahl('fenster_frist', 60, 0, 600)
FENSTER_ZUORDNUNG = fenster_zuordnung_lesen(_cfg_text('fenster_zuordnung'))
if FENSTER_ZUORDNUNG is None:
    if FENSTER_EIN:
        _LOGGER.warning("fenster_zuordnung ist nicht lesbar (Form <Geraetenummer>:<name>+<name>,"
                        "...) - 'Fenster offen -> Geraet aus' bleibt AUS.")
    FENSTER_EIN = False
    FENSTER_ZUORDNUNG = {}
if FENSTER_EIN and not FENSTER_ZUORDNUNG:
    _LOGGER.warning("'Fenster offen -> Geraet aus' ist eingeschaltet, aber keinem Geraet ist "
                    "ein Fenster zugeordnet - die Kopplung bleibt AUS.")
    FENSTER_EIN = False
if not FENSTER_EIN:
    FENSTER_ZUORDNUNG = {}
FENSTER_THEMEN = {}     # Thema -> Fenstername
for _fg, _fn in FENSTER_ZUORDNUNG.items():
    for _f in _fn:
        FENSTER_THEMEN[FENSTER_THEMA % _f] = _f

UDP_Port = _cfg_zahl('UDP_PORT', 7013, 1, 65535)
# Groesste zulaessige Laenge eines Datagramms (siehe datagram_received).
# Der laengste zulaessige Befehl - Nummer, Schluessel, Token, IP und acht
# Werte - bleibt weit darunter.
UDP_MAX = 512
LoxberryIP = cfg.get('default', 'LoxberryIP').strip()
if not LoxberryIP:
    # Auf allen Adressen horchen ist das, was bis 4.2.12 mit leerem Wert
    # ohnehin geschah - jetzt steht es da, statt sich zu ergeben.
    LoxberryIP = '0.0.0.0'
    _LOGGER.info("LoxberryIP ist leer - es wird auf allen Adressen gehorcht.")
Miniserver = cfg.get('default', 'MINISERVER')
Abfragetakt = _cfg_zahl('abfragetakt', 0, 0, 86400)
if 0 < Abfragetakt < 30:
    _LOGGER.warning("Abfragetakt %s s ist kleiner als die Untergrenze 30 s - "
                    "es gilt 30 s.", Abfragetakt)
    Abfragetakt = 30
LOX_TIMEOUT = _cfg_zahl('lox_timeout', 5, 1, 60)
try:
    MQTT_PRAEFIX = cfg.get('default', 'mqtt_praefix').strip().strip('/')
except (configparser.NoOptionError, configparser.NoSectionError):
    MQTT_PRAEFIX = ''
if not MQTT_PRAEFIX:
    MQTT_PRAEFIX = 'Midea2Lox'
    _LOGGER.info("Kein MQTT-Praefix eingetragen - es gilt Midea2Lox.")

# Credentials to set Loxone Inputs over HTTP
try:
    cfg.read(home_path + '/config/system/general.cfg')
    LoxIP = cfg.get(Miniserver, 'IPADDRESS')
    LoxPort = cfg.get(Miniserver, 'PORT')
    LoxPassword = cfg.get(Miniserver, 'PASS')
    LoxUser = cfg.get(Miniserver, 'ADMIN')
except (configparser.Error, OSError) as fehler:
    # Hier hatte bis 4.2.12 gar keine eigene Absicherung gestanden: fehlte
    # der Abschnitt MINISERVER1 in der general.cfg, endete der Start wortlos.
    _LOGGER.error("Die Zugangsdaten des Miniservers '%s' stehen nicht in "
                  "config/system/general.cfg (%s). Bitte den Miniserver im "
                  "LoxBerry einrichten und im Plugin auswaehlen.", Miniserver, fehler)
    print('Midea2Lox: Miniserver %s nicht in general.cfg gefunden' % Miniserver)
    sys.exit(1)

# C1: die zulaessigen Absender des UDP-Befehlseingangs (siehe oben).
ERLAUBTE_ABSENDER = miniserver_adressen()

###Version
# Bis 3.4.8 stand hier ein fest verdrahteter MD5-Schluessel
# ("ef8d4aab121cb54f6379fff540319792"). LoxBerry bildet diesen Schluessel
# aus Autorenname, E-Mail und Plugin-Name - er aendert sich also, sobald
# einer dieser Werte angepasst wird, und die Fassung stand danach still
# auf "Unknown". Jetzt wird ueber den Ordnernamen gesucht, den das Plugin
# ohnehin kennt.
try:
    with open(home_path + '/data/system/plugindatabase.json') as jsonFile:
        jsonObject = json.load(jsonFile)
    plugin_folder = os.path.basename(cfg_path.rstrip('/'))
    Midea2Lox_Version = 'Unknown'
    for entry in jsonObject.get("plugins", {}).values():
        if entry.get("folder") == plugin_folder:
            Midea2Lox_Version = str(entry.get("version", 'Unknown'))
            break
    if Midea2Lox_Version == 'Unknown':
        _LOGGER.debug("Plugin '%s' nicht in der plugindatabase.json gefunden", plugin_folder)
except Exception as err:
    _LOGGER.debug('cant find Midea2Lox Version: %s', err)
    Midea2Lox_Version = 'Unknown'

# ---------------------------------------------------------------------------
# MQTT
#
# mqtt_error hat jetzt einen Anfangswert. Bis 4.2.12 wurde die Variable NUR
# in on_connect gesetzt; kam nie ein CONNACK - ueberlasteter Broker,
# haengende Anmeldung, offener Port ohne MQTT-Dienst -, endete jeder
# Statusbericht mit einem NameError. Und weil die Abfrage VOR der
# Verzweigung stand, ging dann auch ueber HTTP nichts: der Rueckfallweg, der
# eigens dafuer gebaut ist, wurde nie erreicht.
# ---------------------------------------------------------------------------
# ---------------------------------------------------------------------------
# Den UDP-Port binden - VOR der MQTT-Anmeldung (ab 4.5.10, C6)
#
# Bis 4.5.9 meldete sich der Dienst zuerst beim Broker an (connect_async im
# Modulrumpf, Kennung "Midea2Lox", Letzter Wille connection/status =
# disconnected) und scheiterte erst danach in start_server() am belegten
# UDP-Port. Liefen zwei Waechter in derselben Sekunde (Cron holt Minuten
# nach), meldete sich der unterlegene Prozess mit DERSELBEN Kennung an und
# loeste beim Sterben den Letzten Willen aus: im Broker stand "disconnected",
# waehrend der Dienst lief (in WSL gemessen, Bericht code Befund 6, Lauf 3).
# Jetzt gilt: wer den Port nicht bekommt, meldet sich nie beim Broker an.
# Das Startskript sperrt ausserdem selbst (flock, daemon/daemon).
# ---------------------------------------------------------------------------
UDP_SOCKET = None
try:
    UDP_SOCKET = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    UDP_SOCKET.bind((LoxberryIP, UDP_Port))
except OSError as fehler:
    _LOGGER.error("Socket konnte nicht gebunden werden (%s:%s): %s - laeuft schon ein "
                  "Midea2Lox? Dieser Prozess endet, BEVOR er sich beim Broker anmeldet.",
                  LoxberryIP, UDP_Port, fehler)
    print('Bind failed. Error : %s' % fehler)
    sys.exit(1)

mqtt_error = 1
MQTT = 0
client = None
# a1 (Verbesserungsbau 30.09.2026): eine eigene Kennung je Anlage statt fest
# "Midea2Lox" - Begruendung und Form bei mi_mqtt.client_kennungen().
KENNUNG, KENNUNG_KURZ = mi_mqtt.client_kennungen()
try: # check if MQTTgateway is installed or not and set MQTT Client settings
    with open(home_path + '/config/system/general.json') as jsonFile:
        jsonObject = json.load(jsonFile)
    LoxberryVersion = int(str(jsonObject["Base"]["Version"])[:1])
    MQTTuser = jsonObject["Mqtt"]["Brokeruser"]
    MQTTpass = jsonObject["Mqtt"]["Brokerpass"]
    MQTTport = jsonObject["Mqtt"]["Brokerport"]
    MQTThost = jsonObject["Mqtt"]["Brokerhost"]
    # paho 2.x verlangt eine CallbackAPIVersion; ohne sie wirft schon das
    # Anlegen. Bis 4.2.12 landete dieser Fehler im umschliessenden except
    # und schaltete STILL auf HTTP um - mit einer einzigen debug-Zeile. Der
    # Anwender sah dann HTTP statt MQTT, ohne Erklaerung.
    # Die Fassung wird abgetastet, nicht angenommen: paho-mqtt 2.x schreibt
    # bei VERSION1 eine DeprecationWarning in JEDES Protokoll (am Geraet an
    # 2.1.0 gemessen, 06.09.2026), paho 1.x kennt die Aufzaehlung gar nicht.
    if hasattr(mqtt, 'CallbackAPIVersion'):
        try:
            client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2, client_id=KENNUNG)
        except (AttributeError, TypeError):
            client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id=KENNUNG)
    else:
        client = mqtt.Client(client_id=KENNUNG)
    _LOGGER.info("MQTT: Client-Kennung %s", KENNUNG)
    client.username_pw_set(MQTTuser, MQTTpass)
    client.on_connect = on_connect
    client.on_disconnect = on_disconnect
    client.on_message = on_message
    client.will_set(MQTT_PRAEFIX + '/connection/status', 'disconnected', qos=2, retain=True)
    if LoxberryVersion <= 2:
        _LOGGER.info('found MQTT Gateway Plugin - publish over MQTT except on Midea2Lox support_mode')
    else:
        _LOGGER.info('got MQTT Settings - publish over MQTT except on Midea2Lox support_mode')
    # connect_async() statt connect(): der Netzwerkfaden baut die Verbindung
    # auf UND nach jedem Abriss neu auf.
    #
    # Mit dem bisherigen connect() gab es genau EINEN Versuch, und zwar im
    # Modulrumpf. Kam der Dienst nach einem Neustart des Rechners vor dem
    # oertlichen Broker hoch, blieb MQTT fuer die ganze Laufzeit auf 0 - und
    # der Dienst sendete bis zum naechsten Dienstneustart ueber HTTP, mit
    # ANDEREN Zielnamen als den MQTT-Themen. In Loxone blieb alles stumm,
    # und im Protokoll stand eine einzige Warnzeile.
    #
    # Bis der Broker antwortet, steht mqtt_error auf 1 (Anfangswert oben),
    # und veroeffentlichen() nimmt den HTTP-Weg. on_connect setzt es auf 0.
    #
    # Beide Aufrufe stehen hinter einer hasattr-Wache - dasselbe Muster, mit
    # dem weiter oben CallbackAPIVersion behandelt wird. paho 1.6.1 (die
    # Fassung im venv dieses Plugins, am Geraet gemessen) und paho 2.x
    # kennen beide Namen; eine aeltere Fassung faellt auf das bisherige
    # connect() zurueck, statt an einem AttributeError zu scheitern.
    if hasattr(client, 'reconnect_delay_set'):
        client.reconnect_delay_set(min_delay=1, max_delay=60)
    if hasattr(client, 'connect_async'):
        client.connect_async(MQTThost, int(MQTTport))
    else:
        client.connect(MQTThost, int(MQTTport))
    client.loop_start()
    MQTT = 1
except Exception as fehler:
    # Der Grund gehoert ins Protokoll, nicht in eine debug-Zeile: dass MQTT
    # ausfaellt, merkt der Anwender sonst erst daran, dass nichts ankommt.
    _LOGGER.warning('MQTT nicht verfuegbar (%s) - es wird ueber HTTP an die '
                    'virtuellen Eingaenge gesendet.', fehler)
    MQTT = 0

# Start script
device_list = []
device_id_list = []

if __name__ == '__main__':
    try:
        asyncio.run(start_server())
    except KeyboardInterrupt:
        _LOGGER.info("Midea2Lox beendet.")
    except Exception as fehler:
        _LOGGER.error("Midea2Lox abgebrochen: %s", fehler, exc_info=True)
        sys.exit(1)
