#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - "Mit Sonnenstrom vorkuehlen" (ab 4.5.22): Einstellungen und Entscheidung.

Ein reines Modul: kein Protokoll, keine Verbindung, keine Datei, kein Geraet.
midea2lox.py holt sich daraus vk_lesen(), zaehlen(), entscheiden(),
start_pruefen() und hand_pruefen(); der Pruefstand (vb_mi4522_skripte)
ruft dieselben Funktionen mit erfundenen Lagen auf. Die Oberflaeche fuehrt
dieselbe Feldtabelle in mi_lib.php (mi_vk_felder) - dass beide gleich
bleiben, prueft mi_vk_probe() nach.

DIE EINSTELLUNG (Schluessel vorkuehlen in midea2lox.cfg)

    <Geraetenummer>:ein=1/ab=800/dauer=10/...,<Geraetenummer>:...

Je Geraet hoechstens ein Eintrag, Felder in beliebiger Reihenfolge, ein
fehlendes Feld traegt seine Vorgabe (so nimmt eine spaetere Fassung neue
Felder auf, ohne alte Dateien zu verwerfen). aussen und raum duerfen leer
sein: dann entfaellt diese Bedingung. Ein Geraet ohne Eintrag hat ein=0 -
AB WERK AUS.

DIE ENTSCHEIDUNG

Eingeschaltet wird nur, wenn ALLES zutrifft: Schalter an, nicht schon heute
von Hand uebernommen, haus/energie lebt (Zaehler binnen 120 s live
veraendert, ok = 1, Werte frisch), im Zeitfenster, keine Sperre (Fenster
offen, Anwesenheitskopplung haelt, Automatik greift gerade), Mindestpause
seit dem letzten eigenen Ausschalten vorbei, Anwesenheit passt (nur wenn
eingestellt), aussen warm genug (nur wenn haus/wetter gueltig ist - sonst
entfaellt die Bedingung), der Ueberschuss liegt seit der Mindestdauer
ununterbrochen ueber der Schwelle und - falls gewaehlt und haus/pv/ok = 1 -
die Prognose der naechsten Stunde liegt nicht unter der Schwelle. Danach
fragt der Dienst das Geraet FRISCH ab (start_pruefen): laeuft es schon, wird
nichts angefasst; ist der Raum kuehler als eingestellt, auch nicht.

Ausgeschaltet wird nur, was die Automatik selbst eingeschaltet hat, und erst
nach der Mindestlaufzeit (Kompressorschutz): Bezug ueber der Abschaltschwelle
seit der eingestellten Dauer, Zeitfenster vorbei, Schalter aus, oder
haus/energie faellt laenger als AUSFALL Sekunden aus. Die Hysterese ergibt
sich aus zwei Groessen: eingeschaltet wird bei UEBERSCHUSS, ausgeschaltet
erst bei BEZUG - das Geraet darf seinen eigenen Ueberschuss also aufbrauchen.

Hat jemand das laufende Geraet bedient (ausgeschaltet, Betriebsart oder
Sollwert geaendert, oder ein Befehl aus Loxone/MQTT), laesst die Automatik
los, ohne zu schalten, und das Geraet bis zum naechsten Tag in Ruhe.
"""

# Name, Vorgabe, kleinster, groesster Wert (leer erlaubt bei aussen/raum).
VK_FELDER = (
    ('ein', 0, 0, 1),            # Mit Sonnenstrom vorkuehlen - ab Werk aus
    ('ab', 800, 100, 20000),     # W Ueberschuss zum Einschalten
    ('dauer', 10, 1, 120),       # min, so lange muss der Ueberschuss ununterbrochen anliegen
    ('bezug', 200, 0, 20000),    # W Bezug aus dem Netz, ab dem ausgeschaltet wird
    ('bezugdauer', 5, 1, 120),   # min, so lange muss der Bezug anliegen
    ('von', 11, 0, 23),          # Uhr, Beginn des Zeitfensters
    ('bis', 18, 1, 24),          # Uhr, Ende des Zeitfensters (ausschliesslich)
    ('aussen', 24, -20, 50),     # degC, nur ab dieser Aussentemperatur (leer = egal)
    ('raum', 24, 10, 40),        # degC, nur ab dieser Raumtemperatur (leer = egal)
    ('soll', 23, 16, 30),        # degC, Solltemperatur beim Vorkuehlen
    ('lauf', 20, 0, 120),        # min Mindestlaufzeit
    ('pause', 15, 0, 240),       # min Mindestpause nach dem eigenen Ausschalten
    ('anw', 'egal', None, None),  # egal | da (nur wenn jemand zu Hause) | weg (nur wenn niemand)
    ('prognose', 1, 0, 1),       # PV-Prognose der naechsten Stunde beachten (nur mit haus/pv/ok = 1)
)
VK_LEER_ERLAUBT = ('aussen', 'raum')
VK_ANW = ('egal', 'da', 'weg')

STILLE = 120        # s: Lebenszeichen und Werte der Haus-Themen (Regeln/07)
AUSFALL = 300       # s: faellt haus/energie waehrend eines Laufs so lange aus, wird regulaer beendet
NACHPRUEFEN = 600   # s: nach einer Absage von start_pruefen() erst so spaet wieder abfragen


def vk_vorgaben():
    return {n: v for n, v, _k, _g in VK_FELDER}


def _feld(name, roh):
    """(Wert, Fehler) eines Feldes. Fehler ist '' oder der Feldname."""
    for n, v, klein, gross in VK_FELDER:
        if n != name:
            continue
        w = str(roh).strip()
        if n == 'anw':
            return (w, '') if w in VK_ANW else (None, n)
        if w == '' and n in VK_LEER_ERLAUBT:
            return None, ''
        neg = w.startswith('-')
        if not (w[1:] if neg else w).isdigit() or len(w) > 6:
            return None, n
        z = int(w)
        if z < klein or z > gross:
            return None, n
        return z, ''
    return None, name


def vk_lesen(roh):
    """{Geraetenummer: {Feld: Wert}} - oder (None, Grund) bei einem Formfehler.

    Rueckgabe immer ein Paar (Ergebnis, Grund); Grund ist '' wenn alles stimmt.
    Dieselbe Regel wie mi_vk_lesen() in mi_lib.php."""
    aus = {}
    text = str(roh or '').strip()
    if not text:
        return aus, ''
    for teil in text.split(','):
        gid, sep, rest = teil.strip().partition(':')
        gid = gid.strip()
        if not sep or not gid.isdigit() or not 10 <= len(gid) <= 19:
            return None, 'Geraetenummer'
        if gid in aus:
            return None, 'doppelt ' + gid
        k = vk_vorgaben()
        gesehen = set()
        for paar in rest.split('/'):
            if not paar.strip():
                continue
            name, gleich, wert = paar.partition('=')
            name = name.strip()
            if not gleich or name in gesehen:
                return None, '%s %s' % (gid, name or '?')
            gesehen.add(name)
            w, fehler = _feld(name, wert)
            if fehler:
                return None, '%s %s' % (gid, fehler)
            k[name] = w
        if k['bis'] <= k['von']:
            return None, '%s von/bis' % gid
        aus[gid] = k
    return aus, ''


def im_fenster(k, minute_des_tages):
    return k['von'] * 60 <= minute_des_tages < k['bis'] * 60


def neuer_zustand():
    """Der Zustand eines Geraets. laeuft_seit, soll, aus_seit und hand_tag
    stehen im Merker (uebersteht Neustart und Update), der Rest nicht."""
    return {'laeuft_seit': None, 'soll': None, 'aus_seit': None, 'hand_tag': None,
            'ueber_seit': None, 'bezug_seit': None, 'gueltig_zuletzt': None,
            'pruefen_bis': 0.0, 'pruefen_grund': ''}


def zaehlen(k, z, lage, jetzt):
    """Ueberschuss- und Bezugsdauer nachfuehren (vor entscheiden()).

    lage['energie_ok'] False (Stille, ok = 0, kein Wert) setzt beide Dauern
    zurueck: eine Luecke ist kein ununterbrochener Ueberschuss."""
    if not lage.get('energie_ok'):
        z['ueber_seit'] = None
        z['bezug_seit'] = None
        return
    z['gueltig_zuletzt'] = jetzt
    ue = lage.get('ueberschuss')
    if ue is not None and ue >= k['ab']:
        if z['ueber_seit'] is None:
            z['ueber_seit'] = jetzt
    else:
        z['ueber_seit'] = None
    be = lage.get('bezug')
    if be is not None and be > k['bezug']:
        if z['bezug_seit'] is None:
            z['bezug_seit'] = jetzt
    else:
        z['bezug_seit'] = None


def _min(s):
    return int(s / 60 + 0.5)


def entscheiden(k, z, lage, jetzt, tag, minute_des_tages):
    """(aktion, grund) - aktion: 'nichts', 'pruefen' (Geraet frisch abfragen,
    dann start_pruefen()) oder 'aus' (eigene Kuehlung regulaer beenden).

    lage: energie_ok, ueberschuss, bezug (W oder None), sperre (Text oder ''),
    anw ('1', '0' oder None = keine gueltige Aussage), aussen (degC oder None =
    keine gueltige Aussage), pv_naechste (W oder None = keine gueltige Prognose)."""
    if z.get('laeuft_seit') is None:
        if not k.get('ein'):
            return 'nichts', 'aus'
        if z.get('hand_tag') == tag:
            return 'nichts', 'heute von Hand bedient - Ruhe bis morgen'
        if not lage.get('energie_ok'):
            return 'nichts', 'wartet auf haus/energie (schweigt oder ok = 0)'
        if not im_fenster(k, minute_des_tages):
            return 'nichts', 'wartet auf das Zeitfenster %d-%d Uhr' % (k['von'], k['bis'])
        if lage.get('sperre'):
            return 'nichts', 'gesperrt: %s' % lage['sperre']
        if z.get('aus_seit') is not None and jetzt - z['aus_seit'] < k['pause'] * 60:
            return 'nichts', 'Mindestpause, noch %d min' % _min(k['pause'] * 60 - (jetzt - z['aus_seit']))
        if k['anw'] != 'egal':
            a = lage.get('anw')
            if a not in ('0', '1'):
                return 'nichts', 'wartet auf haus/anwesenheit (schweigt)'
            if k['anw'] == 'da' and a != '1':
                return 'nichts', 'wartet, bis jemand zu Hause ist'
            if k['anw'] == 'weg' and a != '0':
                return 'nichts', 'wartet, bis niemand zu Hause ist'
        if k['aussen'] is not None and lage.get('aussen') is not None and lage['aussen'] < k['aussen']:
            return 'nichts', 'aussen %.1f degC unter %d degC' % (lage['aussen'], k['aussen'])
        ue = lage.get('ueberschuss')
        if z.get('ueber_seit') is None:
            return 'nichts', 'wartet auf Ueberschuss ab %d W (jetzt %s W)' % (
                k['ab'], '-' if ue is None else int(ue))
        if jetzt - z['ueber_seit'] < k['dauer'] * 60:
            return 'nichts', 'Ueberschuss seit %d von %d min' % (int((jetzt - z['ueber_seit']) / 60), k['dauer'])
        if k['prognose'] and lage.get('pv_naechste') is not None and lage['pv_naechste'] < k['ab']:
            return 'nichts', 'Prognose naechste Stunde %d W unter %d W' % (int(lage['pv_naechste']), k['ab'])
        if z.get('pruefen_bis', 0) > jetzt:
            return 'nichts', z.get('pruefen_grund') or 'wartet'
        return 'pruefen', 'Ueberschuss %s W seit %d min' % (
            '-' if ue is None else int(ue), int((jetzt - z['ueber_seit']) / 60))

    # Eine eigene Kuehlung laeuft.
    gelaufen = jetzt - z['laeuft_seit']
    grund = ''
    if not k.get('ein'):
        grund = 'Schalter aus'
    elif z.get('gueltig_zuletzt') is not None and jetzt - z['gueltig_zuletzt'] > AUSFALL:
        grund = 'haus/energie faellt seit %d min aus' % int((jetzt - z['gueltig_zuletzt']) / 60)
    elif z.get('gueltig_zuletzt') is None and gelaufen > AUSFALL:
        grund = 'haus/energie seit dem Start des Dienstes stumm'
    elif z.get('bezug_seit') is not None and jetzt - z['bezug_seit'] >= k['bezugdauer'] * 60:
        grund = 'Bezug ueber %d W seit %d min' % (k['bezug'], int((jetzt - z['bezug_seit']) / 60))
    elif not im_fenster(k, minute_des_tages):
        grund = 'Zeitfenster %d-%d Uhr zu Ende' % (k['von'], k['bis'])
    if not grund:
        return 'nichts', 'laeuft seit %d min' % int(gelaufen / 60)
    if gelaufen < k['lauf'] * 60:
        return 'nichts', '%s - Mindestlaufzeit noch %d min' % (grund, _min(k['lauf'] * 60 - gelaufen))
    return 'aus', grund


def start_pruefen(k, geraet):
    """Nach der frischen Abfrage: ('ein', grund) oder ('nichts', grund).

    geraet: online (bool), an (bool), raum (degC oder None)."""
    if not geraet.get('online'):
        return 'nichts', 'Geraet antwortet nicht'
    if geraet.get('an'):
        return 'nichts', 'Geraet laeuft schon - es wird nichts angefasst'
    r = geraet.get('raum')
    if k['raum'] is not None and r is not None and r < k['raum']:
        return 'nichts', 'Raum %.1f degC unter %d degC' % (r, k['raum'])
    return 'ein', ''


def hand_pruefen(z, geraet):
    """Grund, wenn jemand das laufende Geraet bedient hat - sonst ''.

    geraet: online (bool), an (bool), modus ('cool', ...), soll (degC).
    Ein Geraet, das nicht antwortet, sagt nichts - dann wird nicht geraten."""
    if not geraet.get('online'):
        return ''
    if not geraet.get('an'):
        return 'ausgeschaltet (von Hand oder einer anderen Kopplung)'
    if geraet.get('modus') not in (None, 'cool'):
        return 'Betriebsart geaendert (%s)' % geraet.get('modus')
    s, eigen = geraet.get('soll'), z.get('soll')
    if s is not None and eigen is not None and abs(float(s) - float(eigen)) > 0.05:
        return 'Sollwert geaendert (%.1f statt %.1f)' % (float(s), float(eigen))
    return ''
