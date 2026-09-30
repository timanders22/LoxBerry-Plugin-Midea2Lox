#!REPLACELBPBINDIR/venv/bin/python3
# -*- coding: utf-8 -*-
"""Midea2Lox - meldet, ob der Dauerlaeufer laeuft.

Aufruf:  lebenszeichen.py <1|0> [<grund>]

WARUM DAS NICHT DER DIENST SELBST TUT

Ein Dienst, der seinen eigenen Tod melden soll, ist der falsche Zeuge. Der
letzte Wille des MQTT-Brokers hilft nur, wenn die Verbindung sauber
abreisst - bei einem haengenden Prozess, einem OOM-Kill oder einem
abgestuerzten Netzwerkfaden bleibt das Thema stehen, wie es war. Und ein
virtueller Eingang behaelt seinen letzten Wert ohnehin: in Loxone stuende
weiter "laeuft", bis jemand nachsieht.

Deshalb misst der minuetliche Cron-Lauf von aussen und schickt das Ergebnis.
Der Dienst selbst schickt ts, zaehler und ok - zwei unabhaengige Zeugen.

Der Weg ist derselbe wie beim Dienst: ueber MQTT, wenn ein Broker
konfiguriert ist, sonst per HTTP an den virtuellen Eingang. Sonst waere das
Lebenszeichen genau in der Lage stumm, in der das Plugin ohne MQTT laeuft.
"""
import json
import os
import re
import sys

cfg_path = 'REPLACELBPCONFIGDIR' #### REPLACE LBPCONFIGDIR ####
log_path = 'REPLACELBPLOGDIR' #### REPLACE LBPLOGDIR ####
home_path = 'REPLACELBHOMEDIR' #### REPLACE LBHOMEDIR ####

import configparser
import logging
import threading
import time

logging.basicConfig(
    level=logging.INFO, filename=log_path + '/midea2lox.log',
    format='%(asctime)s %(name)-12s %(levelname)-8s %(message)s',
    datefmt='%d.%m %H:%M')
_LOGGER = logging.getLogger("lebenszeichen.py")


# Klartext der CONNACK-Codes (MQTT 3.1.1: 1-5; paho 2.x: 132-136).
CONNACK_TEXT = {1: 'Protokollfassung abgelehnt', 2: 'Client-Kennung abgelehnt',
                3: 'Broker nicht verfuegbar', 4: 'Benutzername oder Kennwort falsch',
                5: 'nicht berechtigt', 132: 'Protokollfassung abgelehnt',
                133: 'Client-Kennung abgelehnt', 134: 'Benutzername oder Kennwort falsch',
                135: 'nicht berechtigt', 136: 'Broker nicht verfuegbar'}


def wert(cfg, schluessel, vorgabe=''):
    try:
        return str(cfg.get('default', schluessel)).strip()
    except (configparser.NoOptionError, configparser.NoSectionError):
        return vorgabe


def main(argv):
    if len(argv) < 2 or argv[1] not in ('0', '1'):
        print('Aufruf: lebenszeichen.py <1|0> [<grund>]')
        return 2
    laeuft = argv[1]
    grund = argv[2] if len(argv) > 2 else ''

    cfg = configparser.RawConfigParser()
    try:
        cfg.read(cfg_path + '/midea2lox.cfg')
    except configparser.Error as fehler:
        _LOGGER.debug('Lebenszeichen: midea2lox.cfg nicht lesbar: %s', fehler)
        return 1
    praefix = wert(cfg, 'mqtt_praefix', 'Midea2Lox').strip('/') or 'Midea2Lox'
    thema = praefix + '/status/dienst'

    # 1. MQTT
    try:
        with open(home_path + '/config/system/general.json') as f:
            allgemein = json.load(f)
        m = allgemein['Mqtt']
        import paho.mqtt.client as mqtt
        # Die Fassung wird abgetastet, nicht angenommen: paho-mqtt 2.x schreibt
        # bei VERSION1 eine DeprecationWarning in JEDES Protokoll (am Geraet an
        # 2.1.0 gemessen, 06.09.2026), paho 1.x kennt die Aufzaehlung gar nicht.
        if hasattr(mqtt, 'CallbackAPIVersion'):
            try:
                c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2,
                                client_id='Midea2Lox_leben')
            except (AttributeError, TypeError):
                c = mqtt.Client(mqtt.CallbackAPIVersion.VERSION1,
                                client_id='Midea2Lox_leben')
        else:
            c = mqtt.Client(client_id='Midea2Lox_leben')
        c.username_pw_set(m['Brokeruser'], m['Brokerpass'])
        # M5 (Durchgang 30.09.2026): die Antwort des Brokers AUSWERTEN. Bis
        # 4.5.9 wurde nach connect() einfach gesendet; wies der Broker die
        # Anmeldung ab (CONNACK 5), endete das Skript mit 0 und schrieb
        # "Lebenszeichen ... =1" ins Protokoll, der HTTP-Rueckfall wurde nie
        # versucht (in WSL gemessen, Bericht mqtt M5; Regeln/07 "Eine
        # abgelehnte Anmeldung sieht aus wie eine gelungene").
        antwort = {'rc': None}
        angemeldet = threading.Event()

        def bei_verbindung(_c, _u, _f, rc, *_rest):
            try:
                antwort['rc'] = int(getattr(rc, 'value', rc))
            except (TypeError, ValueError):
                antwort['rc'] = -1
            angemeldet.set()

        c.on_connect = bei_verbindung
        c.connect(m['Brokerhost'], int(m['Brokerport']), keepalive=15)
        c.loop_start()
        try:
            if not angemeldet.wait(3):
                raise RuntimeError('der Broker hat binnen 3 s nicht auf die Anmeldung geantwortet')
            if antwort['rc'] != 0:
                raise RuntimeError('der Broker hat die Anmeldung abgewiesen (CONNACK %s: %s)' % (
                    antwort['rc'], CONNACK_TEXT.get(antwort['rc'], 'unbekannter Grund')))
            # NICHT retained. Dieses Thema ist das Lebenszeichen des Dienstes;
            # retained stuende es nach einem Neustart des Miniservers sofort
            # wieder da und meldete "laeuft", auch wenn der Dienst tot ist.
            # Hausstandard seit 03.09.2026.
            info = c.publish(thema, laeuft, qos=1, retain=False)
            try:
                info.wait_for_publish(timeout=5)
            except TypeError:
                info.wait_for_publish()
            if not info.is_published():
                raise RuntimeError('der Broker hat das Lebenszeichen nicht binnen 5 s bestaetigt')
        finally:
            c.loop_stop()
            c.disconnect()
        if grund:
            _LOGGER.info('Lebenszeichen %s=%s (%s)', thema, laeuft, grund)
        return 0
    except Exception as fehler:
        _LOGGER.warning('Lebenszeichen %s=%s ueber MQTT nicht zugestellt (%s) - '
                        'es wird HTTP versucht.', thema, laeuft, fehler)

    # 2. HTTP an den virtuellen Eingang
    try:
        import requests
        from urllib.parse import quote
        ms = wert(cfg, 'MINISERVER', 'MINISERVER1')
        allg = configparser.RawConfigParser()
        allg.read(home_path + '/config/system/general.cfg')
        ziel = ('http://%s:%s@%s:%s/dev/sps/io/%s/%s'
                % (quote(allg.get(ms, 'ADMIN'), safe=''),
                   quote(allg.get(ms, 'PASS'), safe=''),
                   allg.get(ms, 'IPADDRESS'), allg.get(ms, 'PORT'),
                   praefix + '_status_dienst', laeuft))
        r = requests.get(ziel, timeout=5)
        if r.status_code != 200:
            _LOGGER.warning('Lebenszeichen: Miniserver antwortete mit %s auf '
                            '%s_status_dienst.', r.status_code, praefix)
            return 1
        _LOGGER.info('Lebenszeichen %s_status_dienst=%s ueber HTTP zugestellt.', praefix, laeuft)
        return 0
    except Exception as fehler:
        # Ehrlich: weder MQTT noch HTTP haben es zugestellt (M5). Zugangsdaten
        # einer Adresse ("//benutzer:kennwort@") kommen nie ins Protokoll.
        _LOGGER.warning('Lebenszeichen %s=%s auch ueber HTTP nicht zugestellt: %s',
                        thema, laeuft, re.sub(r'//[^/@\s]*@', '//***@', str(fehler)))
        return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv))
