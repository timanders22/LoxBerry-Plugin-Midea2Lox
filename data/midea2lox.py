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
ABO_WERTE = {}          # Thema -> (roher Text, Zeitpunkt des Eintreffens)


def abo_merken(thema, text):
    with ABO_SCHLOSS:
        ABO_WERTE[thema] = (text, time.time())


def abo_stand():
    """Eine Abschrift des ganzen Speichers - fuer Anzeige und Protokoll."""
    with ABO_SCHLOSS:
        return dict(ABO_WERTE)


def abo_holen(thema, hoechstalter):
    """(Text, Alter) - oder (None, Alter) wenn zu alt, (None, None) wenn nie.

    Die beiden Nein-Faelle sind ABSICHTLICH unterscheidbar: "nie etwas
    bekommen" ist ein Einrichtungsfehler, "zu alt" ein Betriebsfehler, und
    der Anwender muss verschiedene Dinge tun.
    """
    if not thema:
        return None, None
    with ABO_SCHLOSS:
        eintrag = ABO_WERTE.get(thema)
    if not eintrag:
        return None, None
    text, wann = eintrag
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
            try:
                warteschlange.put_nowait((daten, absender))
            except asyncio.QueueFull:
                _LOGGER.error(
                    "Warteschlange voll (%d) - Paket von %s verworfen. "
                    "Antwortet ein Geraet nicht, oder schickt Loxone zu schnell?",
                    warteschlange.maxsize, absender[0] if absender else '?')

        def error_received(self, fehler):
            _LOGGER.warning("UDP-Fehler: %s", fehler)

    try:
        transport, _ = await schleife.create_datagram_endpoint(
            Empfang, local_addr=(LoxberryIP, UDP_Port))
    except OSError as fehler:
        _LOGGER.error("Socket konnte nicht gebunden werden (%s:%s): %s",
                      LoxberryIP, UDP_Port, fehler)
        print('Bind failed. Error : %s' % fehler)
        return
    _LOGGER.info("Socket bind complete, listen at {}:{}".format(LoxberryIP, UDP_Port))
    print('Socket bind complete, listen at', LoxberryIP, ":", UDP_Port)

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
                        device.target_temperature = int(eachArg)
                        _LOGGER.debug(device.target_temperature)
                    elif eachArg.startswith("temp."):
                        # Einstellige Sollwerte (8 Grad Frostschutz) und halbe
                        # Grad gingen bis 4.2.12 nicht: die Erkennung war
                        # len(eachArg) == 2 and isdigit(). "20.5" und "8"
                        # fielen durch und landeten als "unknown" im Protokoll.
                        roh = eachArg.split(".", 1)[1].replace(",", ".")
                        try:
                            device.target_temperature = float(roh)
                            _LOGGER.debug(device.target_temperature)
                        except ValueError:
                            _LOGGER.error("ungueltige Solltemperatur '{}' - Befehl verworfen".format(eachArg))
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

            #set only accepted temperatures
            # float() statt int(): int(30.5) ist 30 und damit NICHT groesser
            # als ein Maximum von 30 - ein unzulaessiger Wert waere
            # stehengeblieben.
            try:
                soll = float(device.target_temperature)
            except (TypeError, ValueError):
                soll = None
            if soll is not None:
                if soll < device.min_target_temperature:
                    _LOGGER.warning("Get Temperature {}. Allowed Temperature: {}-{}, set target Temperature to {}".format(device.target_temperature,device.min_target_temperature,device.max_target_temperature,device.min_target_temperature))
                    device.target_temperature = device.min_target_temperature
                elif soll > device.max_target_temperature:
                    _LOGGER.warning("Get Temperature {}. Allowed Temperature: {}-{}, set target Temperature to {}".format(device.target_temperature,device.min_target_temperature,device.max_target_temperature,device.max_target_temperature))
                    device.target_temperature = device.max_target_temperature

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
            if w is None:
                raise ValueError('kein Wert')
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
        nimm('horizontal_swing_angle', lambda: device.horizontal_swing_angle.name)
    if getattr(device, 'supports_vertical_swing_angle', False):
        nimm('vertical_swing_angle',   lambda: device.vertical_swing_angle.name)
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


async def veroeffentlichen(paare, support_mode=0):
    """Ueber MQTT, sonst per HTTP an virtuelle Eingaenge.

    paare ist eine Liste aus (Thema ohne Praefix, Wert).
    """
    if MQTT == 1 and support_mode == 0 and mqtt_error == 0:
        for thema, wert in paare:
            try:
                publish = client.publish(MQTT_PRAEFIX + '/' + thema, wert,
                                         qos=2, retain=True)
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
        # Die Abonnements gehoeren HIERHER, nicht neben den Verbindungsaufbau.
        #
        # Ein subscribe(), das einmal beim Start steht, ist nach dem ersten
        # Abriss weg: der Broker vergisst die Abonnements einer getrennten
        # Sitzung (clean session). Am 31.08.2026 ist gemessen worden, dass
        # dieser Dienst nach einem Abriss wirklich neu verbindet - dann muss
        # er auch neu abonnieren, sonst laeuft die Automatik ab dem ersten
        # Netzhaenger blind weiter.
        for _thema in (AUTO_THEMA_REGEL, AUTO_THEMA_PV):
            if _thema:
                try:
                    client.subscribe(_thema, qos=0)
                    _LOGGER.info("MQTT: abonniert %s", _thema)
                except Exception as fehler:
                    _LOGGER.error("MQTT: %s liess sich nicht abonnieren: %s",
                                  _thema, fehler)
    elif rc == 1:
        _LOGGER.error("MQTT: Falsche Protokollversion")
    elif rc == 2:
        _LOGGER.error("MQTT: Identifizierung fehlgeschlagen")
    elif rc == 3:
        _LOGGER.error("MQTT: Server nicht erreichbar")
    elif rc == 4:
        _LOGGER.error("MQTT: Falscher Benutzername oder Passwort")
    elif rc == 5:
        _LOGGER.error("MQTT: Nicht autorisiert")
    else:
        _LOGGER.error("MQTT: Ungueltiger Rueckgabecode %s", rc)


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
    abo_merken(nachricht.topic, text)
    _LOGGER.debug("MQTT empfangen: %s = %s", nachricht.topic, text[:40])


def on_disconnect(client, userdata, rc, properties=None, reason=None):
    """Beim Trennen.

    Die Signatur nimmt jetzt beide Formen an. paho 1.x ruft
    on_disconnect(client, userdata, rc), paho 2.x mit VERSION2 ruft mit fuenf
    Argumenten. Bis 4.2.12 standen hier VIER Pflichtparameter - das passt zu
    KEINER von beiden, und beim Trennen haette es einen TypeError im
    Netzwerkfaden gegeben. Praktisch faengt der letzte Wille den Fall ohnehin
    ab; deshalb war es nie aufgefallen.
    """
    global mqtt_error
    mqtt_error = 1
    _LOGGER.info("MQTT Disconnected (rc=%s)", rc)


##########

try:
    import asyncio
    import json
    import time
    import configparser
    from ipaddress import ip_address, IPv4Address
    from urllib.parse import quote

    from msmart.device import AirConditioner as ac
    from msmart import __version__
    import requests
    import paho.mqtt.client as mqtt

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
_handler = logging.handlers.RotatingFileHandler(
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
mqtt_error = 1
MQTT = 0
client = None
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
    if hasattr(mqtt, 'CallbackAPIVersion'):
        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1, client_id='Midea2Lox')
    else:
        client = mqtt.Client(client_id='Midea2Lox')
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
