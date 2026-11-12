#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - der HTTP-Weg an den Miniserver (ab 4.5.27).

Ein reines Modul: kein Protokoll, keine Datei. midea2lox.py (HTTP-Weg der
Werte) und lebenszeichen.py (HTTP-Rueckfall des Lebenszeichens) holen sich
daraus die Verbindungsart und den einen Sendeweg; der Pruefstand ruft
dieselben Funktionen mit erfundenen Lagen auf.

WAS SICH IN 4.5.27 GEAENDERT HAT

1. Zugangsdaten im Authorization-Kopf, nicht in der Adresse. Bis 4.5.26
   bauten beide Programme http://<benutzer>:<kennwort>@<ip>:<port>/dev/sps/io/
   (Hausregel CLAUDE.md 9: in der Kopfzeile statt in der Adresse).
2. Klartextfelder. Bis 4.5.26 kamen Benutzer und Kennwort aus ADMIN/PASS
   der general.cfg und wurden noch einmal mit quote() kodiert. LoxBerry
   fuehrt dort wie in general.json ADMIN/PASS fuer Adressen kodiert und
   ADMIN_RAW/PASS_RAW im Klartext (Regeln/07, Beschattungswaechter Issue #2).
   Jetzt: ADMIN_RAW/PASS_RAW, fehlt das Feld, ADMIN/PASS mit unquote()
   zurueckgewandelt (nicht unquote_plus: ein '+' bleibt ein '+').
3. Verbindungsart wie LoxBerry (Punkt G1-G4, Bauart Dashboard 0.9.44 und
   BLE-Scanner 1.3.41). Einstellung tls in midea2lox.cfg: "auto" (wie
   LoxBerry, Vorgabe), "0" (unverschluesselt), "1" (verschluesselt). Jeder
   andere Wert wird nicht gedeutet: es wird nichts gesendet (G1). Ein
   bestehendes "0" bleibt "0"; den Widerspruch zum LoxBerry meldet
   widerspruch_tls() (G3). Einer Umleitung wird nie gefolgt; eine Umleitung
   auf https erkennt umleitung_erkannt() (G4). Gemessen am Geraet
   11.11.2026: die Miniserver-Firmware 17.3 leitet http://<IP>:80 mit 308
   auf eine https-Adresse um; general.json fuehrt den Miniserver mit
   Transport "https", Preferhttps "1", Porthttps "443", Port "80".
   Gemessen am Geraet 12.11.2026: die general.cfg kennt PORTHTTPS,
   PREFERHTTPS und TRANSPORT nicht - die Verbindungsart steht nur in
   general.json.
"""
import base64
import configparser
import json
import os
import re
import ssl
import urllib.error
import urllib.parse
import urllib.request

TLS_ERLAUBT = ("auto", "0", "1")

TLS_UNGUELTIG = ("Die Einstellung 'Verbindung zum Miniserver' (tls) hat den ungueltigen Wert %r; erlaubt sind "
                 "auto, 0 und 1. An den Miniserver wird nichts per HTTP gesendet.")

UMLEITUNG_TEXT = ("Der Miniserver verlangt eine verschluesselte Verbindung (Umleitung auf https). Im Reiter "
                  "Einstellungen 'Verbindung zum Miniserver' auf 'wie LoxBerry' oder 'verschluesselt (TLS)' "
                  "stellen.")


def lb_sagt_https(ms):
    """Fuehrt der LoxBerry diesen Miniserver verschluesselt? Wahr bei Preferhttps 1
    oder Transport https. Der Rumpf ist wortgleich mit lb_sagt_https() im Dashboard 0.9.44."""
    pref = ms.get("Preferhttps")
    if pref is True or (not isinstance(pref, bool) and str(pref).strip() == "1"):
        return True
    return str(ms.get("Transport") or "").strip().lower() == "https"


def _port_aus(roh, ersatz):
    """Ein Port: 1 bis 65535 in Ziffern, sonst der Ersatz."""
    t = str(roh if roh is not None else "").strip()
    if re.match(r"^\d{1,5}$", t) and 1 <= int(t) <= 65535:
        return int(t)
    return ersatz


def verbindungsart(wahl, ms):
    """(TLS ja/nein, Port) aus der Einstellung tls und dem Miniserver-Eintrag.

    "auto": TLS genau dann, wenn der LoxBerry https sagt (lb_sagt_https);
    "1" immer, "0" nie. Jeder andere Wert wird nicht gedeutet: ValueError.
    Port bei TLS Porthttps (fehlt oder ungueltig: 443), sonst Port (fehlt oder
    ungueltig: 80). Dieselbe Regel steht in mi_ms_verbindung() (mi_lib.php).
    """
    # Nur genau "auto", "0" und "1" - die Konfiguration ist eine INI-Datei, ihre Werte sind Zeichenketten.
    if not isinstance(wahl, str) or wahl not in TLS_ERLAUBT:
        raise ValueError(TLS_UNGUELTIG % (wahl,))
    tls = lb_sagt_https(ms) if wahl == "auto" else wahl == "1"
    if tls:
        return True, _port_aus(ms.get("Porthttps"), 443)
    return False, _port_aus(ms.get("Port"), 80)


def _klartext(werte, roh, kodiert):
    """Ein Zugangsfeld im Klartext: erst das *_RAW-Feld, sonst das kodierte zurueckgewandelt."""
    w = werte.get(roh)
    if isinstance(w, str) and w != "":
        return w
    w = werte.get(kodiert)
    if isinstance(w, str) and w != "":
        return urllib.parse.unquote(w)
    return ""


def miniserver_daten(home, abschnitt):
    """Der gewaehlte Miniserver (abschnitt wie in midea2lox.cfg, z. B. MINISERVER1).

    Adresse, Port und Zugang aus der general.cfg wie bis 4.5.26 (der Dienst
    endet beim Start, wenn der Abschnitt fehlt); Benutzer und Kennwort aus
    ADMIN_RAW/PASS_RAW. Was LoxBerry ueber die Verbindungsart sagt
    (Porthttps, Preferhttps, Transport), aus general.json unter derselben
    Nummer. Rueckgabe: dict mit adresse, Port, Porthttps, Preferhttps,
    Transport, benutzer, kennwort, json_gelesen; wirft configparser.Error
    oder KeyError, wenn der Abschnitt in der general.cfg fehlt.
    """
    allg = configparser.RawConfigParser()
    allg.optionxform = str.upper
    allg.read(os.path.join(home, "config", "system", "general.cfg"))
    if not allg.has_section(abschnitt):
        raise KeyError(abschnitt)
    werte = {k.upper(): v for k, v in allg.items(abschnitt)}
    d = {
        "adresse": str(werte.get("IPADDRESS") or "").strip(),
        "Port": str(werte.get("PORT") or "").strip(),
        "Porthttps": None, "Preferhttps": None, "Transport": None,
        "benutzer": _klartext(werte, "ADMIN_RAW", "ADMIN"),
        "kennwort": _klartext(werte, "PASS_RAW", "PASS"),
        "json_gelesen": False,
    }
    nr = abschnitt[len("MINISERVER"):] if abschnitt.upper().startswith("MINISERVER") else ""
    try:
        with open(os.path.join(home, "config", "system", "general.json"), encoding="utf-8") as f:
            ms = (json.load(f).get("Miniserver") or {}).get(nr)
    except (OSError, ValueError, AttributeError):
        ms = None
    if isinstance(ms, dict):
        d["json_gelesen"] = True
        for k in ("Porthttps", "Preferhttps", "Transport"):
            d[k] = ms.get(k)
    return d


def widerspruch_tls(wahl, ms):
    """'' oder ein Satz, wenn tls "0" steht, der LoxBerry den Miniserver aber mit https fuehrt (G3: nicht
    umgedeutet, nur gemeldet)."""
    if wahl != "0" or not lb_sagt_https(ms):
        return ""
    return ("Verbindungsart: eingestellt ist 'unverschluesselt' (tls=0), der LoxBerry fuehrt den Miniserver aber "
            "mit https. Ein Miniserver, der https verlangt, leitet http um, und dort kommt nichts an. Rat: im Reiter "
            "Einstellungen 'Verbindung zum Miniserver' auf 'wie LoxBerry' stellen.")


def umleitung_erkannt(f):
    """Ist das die Umleitung von http:// auf https (G4)? urllib.error.HTTPError mit 301/302/307/308 und einer
    Location auf https. Der Rumpf ist wortgleich mit umleitung_erkannt() im BLE-Scanner 1.3.41."""
    text = str(f)
    if type(f).__name__ == "InvalidURI" or ("isn't a valid URI" in text and "https://" in text):
        return True
    antwort = getattr(f, "response", None)
    code = getattr(antwort, "status_code", None) or getattr(f, "status_code", None) or getattr(f, "code", None)
    if code in (301, 302, 307, 308):
        kopf = getattr(antwort, "headers", None) or getattr(f, "headers", None) or {}
        try:
            ort = str(kopf.get("Location") or kopf.get("location") or "")
        except AttributeError:
            ort = ""
        return ort.lower().startswith("https://")
    return False


class KeineUmleitung(urllib.request.HTTPRedirectHandler):
    """Einer Umleitung wird nicht gefolgt. requests folgte bis 4.5.26 jeder Umleitung (allow_redirects ist
    bei GET an); die Anfrage endet jetzt mit HTTPError, Code und Location bleiben lesbar."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):  # noqa: ARG002
        return None


def ssl_kontext():
    """Verschluesselt, nicht beglaubigt: das Zertifikat des Miniservers lautet auf einen Namen unter
    dyndns.loxonecloud.com, angesprochen wird die IP. LoxBerry haelt es selbst so (Bauart BLE-Scanner 1.3.41)."""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    return ctx


def ms_url(ms, tls, port, pfad):
    """Die Adresse eines Aufrufs - ohne Zugangsdaten, an EINER Stelle."""
    return "{0}://{1}:{2}/{3}".format("https" if tls else "http", ms["adresse"], int(port), str(pfad).lstrip("/"))


def senden(ms, wahl, name, wert, zeitgrenze=5, agent="Midea2Lox"):
    """Einen virtuellen Eingang setzen: /dev/sps/io/<name>/<wert>.

    Rueckgabe (code, fehler, umleitung): code ist der HTTP-Status oder None, fehler '' bei 200, umleitung
    wahr bei einer Umleitung auf https. Eine ungueltige Einstellung tls sendet nichts.
    """
    try:
        tls, port = verbindungsart(wahl, ms)
    except ValueError as f:
        return None, str(f), False
    url = ms_url(ms, tls, port, "dev/sps/io/{0}/{1}".format(urllib.parse.quote(str(name), safe=""),
                                                            urllib.parse.quote(str(wert), safe="")))
    anfrage = urllib.request.Request(url)
    anfrage.add_header("User-Agent", agent)
    if ms.get("benutzer"):
        roh = "{0}:{1}".format(ms["benutzer"], ms.get("kennwort") or "").encode("utf-8")
        anfrage.add_header("Authorization", "Basic " + base64.b64encode(roh).decode("ascii"))
    handler = [KeineUmleitung()]
    if tls:
        handler.append(urllib.request.HTTPSHandler(context=ssl_kontext()))
    oeffner = urllib.request.build_opener(*handler)
    try:
        with oeffner.open(anfrage, timeout=zeitgrenze) as r:
            code = getattr(r, "status", None) or r.getcode()
            return code, ("" if code == 200 else "HTTP %s" % code), False
    except urllib.error.HTTPError as f:
        if umleitung_erkannt(f):
            return f.code, ("HTTP %s - Umleitung auf eine https-Adresse; ihr wird nicht gefolgt." % f.code), True
        return f.code, "HTTP %s" % f.code, False
    except (urllib.error.URLError, OSError, ValueError) as f:
        grund = getattr(f, "reason", f)
        return None, "%s: %s" % (type(f).__name__, grund), False
