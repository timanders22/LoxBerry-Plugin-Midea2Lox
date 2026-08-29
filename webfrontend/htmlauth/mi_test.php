<?php
/**
 * Midea2Lox - die Aktionen und Pruefungen des Reiters Test
 */

require_once __DIR__ . '/mi_lib.php';

function mi_block($text)
{
    return '<div class="sm-log">' . mi_e($text) . '</div>';
}

/**
 * Die Selbstpruefung: je Zeile eine Frage mit Haekchen, Kreuz oder Strich.
 *
 * Liefert eine Liste aus (Frage, 1|0|2, Antworttext).
 *   1 = Haken, 0 = Kreuz, 2 = Strich ("nicht feststellbar").
 *
 * Ein Strich ist ausdruecklich KEIN Haken. Bis 4.2.12 gab es nur zwei
 * Zustaende, und "ich kann es nicht messen" sah aus wie "kaputt" oder wie
 * "in Ordnung", je nachdem, wie die Zeile gebaut war.
 *
 * Jede Zeile, die die eigene Datei liest, meldet die Zahl der angesehenen
 * Stellen mit. Eine Null ist dann kein "in Ordnung", sondern der Hinweis,
 * dass nichts gemessen wurde.
 */
function mi_pruefungen($cfg)
{
    $p = mi_paths();
    $z = array();

    // ---- Umgebung ----
    $venv = is_executable($p['venv']);
    $z[] = array(mi_e(mi_t('PRUEF.VENV')), $venv ? 1 : 0,
        $venv ? mi_e(mi_t('UI.VORHANDEN'))
              : sprintf(mi_t('UI.VENV_FEHLT'), mi_e($p['venv'])));

    $msmart = mi_msmart_version();
    $z[] = array(mi_e(mi_t('PRUEF.MSMART')), $msmart !== '' ? 1 : 0,
        $msmart !== '' ? sprintf(mi_t('UI.FASSUNG_IST'), mi_e($msmart))
                       : mi_t('UI.NICHT_LADBAR'));

    $py = mi_python_version();
    $z[] = array(mi_e(mi_t('PRUEF.PYTHON')), $py !== '' ? 1 : 2,
        $py !== '' ? sprintf(mi_t('UI.FASSUNG_IST'), mi_e($py)) : '');

    /* OFFENER PUNKT bis zur Messung am Geraet: unter paho-mqtt 2.x ist
     * mqtt.Client(client_id=...) ohne CallbackAPIVersion ein Fehler beim
     * Anlegen - der Dienst faellt dann STILL auf HTTP zurueck. Der Dienst
     * seit 4.3.0 uebergibt sie, wenn die Bibliothek sie kennt; diese Zeile
     * macht die Fassung sichtbar, damit die Frage beantwortbar wird, statt
     * offen zu bleiben. postinstall.sh klemmt paho auf < 2.0.0. */
    $paho = mi_paho_version();
    $z[] = array(mi_e(mi_t('PRUEF.PAHO')), $paho !== '' ? 1 : 2,
        $paho !== '' ? sprintf(mi_t('UI.FASSUNG_IST'), mi_e($paho)) : '');

    // ---- Dienst ----
    $pid = mi_dienst_pid();
    $z[] = array(mi_e(mi_t('PRUEF.DIENST')), $pid !== null ? 1 : 0,
        $pid !== null ? sprintf(mi_t('UI.LAEUFT_PID'), mi_e($pid))
                      : mi_e(mi_t('UI.GESTOPPT')));

    /* Arbeitet der Dienst noch? Ein Prozess kann dastehen und nichts tun.
     * Ueber einen Dienst, der gar nicht laeuft, wird kein Herzschlag
     * beurteilt - sonst stuenden hier zwei rote Zeilen fuer eine Ursache. */
    list($alter, $zaehler, $ok) = mi_lebenszeichen();
    if ($pid === null) {
        $z[] = array(mi_e(mi_t('PRUEF.HERZSCHLAG')), 2, mi_e(mi_t('UI.DIENST_LAEUFT_NICHT')));
    } elseif ($alter === null) {
        $z[] = array(mi_e(mi_t('PRUEF.HERZSCHLAG')), 2, mi_e(mi_t('UI.KEIN_LEBENSZEICHEN')));
    } else {
        // Der Dienst schlaegt jede Minute. Bis zum dreifachen Takt ist alles
        // in Ordnung; darueber ist es eine Auskunft, kein Urteil.
        $gut = ($alter >= -60 && $alter <= 180);
        /* Der dritte Zustand bekommt seinen eigenen Satz. "ok=0" las sich
         * wie ein Fehlschlag, auch wenn noch gar kein Durchgang stattgefunden
         * hatte - am Geraet genau so aufgetreten. */
        if ((int) $ok === -1) {
            $text = sprintf(mi_t('UI.HERZSCHLAG_OHNE_DURCHGANG'), (int) $alter,
                            $zaehler === null ? '?' : (int) $zaehler);
        } else {
            $text = sprintf(mi_t('UI.HERZSCHLAG_ALTER'), (int) $alter,
                            $zaehler === null ? '?' : (int) $zaehler,
                            $ok === null ? '?' : (int) $ok);
        }
        $z[] = array(mi_e(mi_t('PRUEF.HERZSCHLAG')), $gut ? 1 : 0, $text);
    }

    // ---- Konfiguration ----
    list($lage, $lagetext) = mi_cfg_lage();
    $z[] = array(mi_e(mi_t('PRUEF.KONFIGURATION')), $lage === 'ok' ? 1 : 0, $lagetext);

    $f = mi_wert_pruefen('UDP_PORT', mi_cfg($cfg, 'UDP_PORT', ''));
    $z[] = array(mi_e(mi_t('PRUEF.UDP_PORT')), $f === '' ? 1 : 0,
        $f === '' ? mi_e(mi_cfg($cfg, 'UDP_PORT', '')) : $f);

    // ---- Midea-Konto ----
    /* Bis 4.2.12 stand hier "Midea-Zugangsdaten hinterlegt" mit einem Kreuz,
     * wenn das Feld leer war, und dem Satz "Geraetesuche nicht moeglich".
     * Beides war falsch: die Felder wurden im ganzen data/-Baum NIRGENDS
     * gelesen, und die Suche laeuft ohne Konto ueber die Sammelkonten von
     * msmart-ng. Seit 4.3.0 werden sie wirklich uebergeben - und die Zeile
     * sagt, was tatsaechlich der Fall ist. */
    $u = mi_cfg($cfg, 'MideaUser', '');
    $pw = mi_cfg($cfg, 'MideaPassword', '');
    if ($u !== '' && $pw !== '') {
        $z[] = array(mi_e(mi_t('PRUEF.KONTO')), 1,
            sprintf(mi_t('UI.KONTO_EIGEN'), mi_e($u)));
    } elseif ($u === '' && $pw === '') {
        $z[] = array(mi_e(mi_t('PRUEF.KONTO')), 1, mi_t('UI.KONTO_SAMMEL'));
    } else {
        // msmart-ng wirft bei genau einem von beiden ein ValueError.
        $z[] = array(mi_e(mi_t('PRUEF.KONTO')), 0, mi_t('UI.KONTO_HALB'));
    }

    $reg = mi_cfg($cfg, 'region', '');
    $ms = mi_region_msmart($reg);
    $z[] = array(mi_e(mi_t('PRUEF.REGION')), $ms !== '' ? 1 : 0,
        $ms !== '' ? sprintf(mi_t('UI.REGION_ZUORDNUNG'), mi_e($reg), mi_e($ms))
                   : sprintf(mi_t('UI.REGION_UNBEKANNT'), mi_e($reg)));

    // ---- Geraete ----
    $dev = mi_devices();
    $z[] = array(mi_e(mi_t('PRUEF.GERAETE')), $dev ? 1 : 0,
        $dev ? sprintf(mi_t('UI.GERAETE_ANZAHL'), count($dev))
             : mi_t('UI.GERAETE_KEINE'));

    // ---- MQTT ----
    $mq = mi_mqtt_config();
    if (!$mq) {
        $z[] = array(mi_e(mi_t('PRUEF.GATEWAY')), 2, mi_t('UI.GATEWAY_UNLESBAR'));
    } elseif (!$mq['autostart']) {
        $z[] = array(mi_e(mi_t('PRUEF.GATEWAY')), 0,
            sprintf(mi_t('UI.GATEWAY_OHNE_AUTOSTART'), mi_e($mq['host'] . ':' . $mq['port'])));
    } else {
        $z[] = array(mi_e(mi_t('PRUEF.GATEWAY')), 1,
            sprintf(mi_t('UI.GATEWAY_OK'), mi_e($mq['host'] . ':' . $mq['port'])));
    }

    $gwf = mi_gateway_fassung();
    $z[] = array(mi_e(mi_t('PRUEF.GATEWAY_FASSUNG')), $gwf > 0 ? 1 : 2,
        $gwf > 0 ? sprintf(mi_t('UI.GATEWAY_FASSUNG_IST'), $gwf)
                 : mi_t('UI.GATEWAY_FASSUNG_UNBEKANNT'));

    /* OFFENER PUNKT: dass LoxBerry config/plugins/<Ordner>/mqtt_subscriptions.cfg
     * ueberhaupt liest, steht in der README dieses Plugins und ist nicht am
     * Geraet gemessen. Diese Zeile beantwortet nur, was sie beantworten
     * KANN - ob die Datei da ist und zum Praefix passt. */
    list($abolage, $aboist, $abosoll) = mi_abo_datei($cfg);
    $z[] = array(mi_e(mi_t('PRUEF.ABO_DATEI')), $abolage === 'ok' ? 1 : 0,
        $abolage === 'ok' ? mi_e($abosoll)
            : ($abolage === 'fehlt' ? mi_t('UI.ABO_DATEI_FEHLT')
               : sprintf(mi_t('UI.ABO_DATEI_ABWEICHEND'), mi_e($aboist), mi_e($abosoll))));

    /* Die Frage, die wirklich zaehlt - und die erst seit 4.3.2 gestellt wird.
     *
     * Gemessen am Geraet (29.08.2026, Gateway V1): das Gateway liest
     * config/plugins/<Ordner>/mqtt_subscriptions.cfg im Betrieb NICHT. Zwei
     * Probedateien, eine davon in einem wirklich installierten Plugin, sind
     * nach einem Neustart des Gateways in keinem Abonnement gelandet. Die
     * Zeile darueber sagt also nur, dass die mitgelieferte DATEI stimmt -
     * diese hier sagt, ob das Thema auch abonniert IST. */
    list($eingetragen, $treffer, $gesamt) = mi_abo_eingetragen($cfg);
    if ($eingetragen === 'unlesbar') {
        $z[] = array(mi_e(mi_t('PRUEF.ABO_EINGETRAGEN')), 2, mi_t('UI.ABO_LISTE_UNLESBAR'));
    } elseif ($gesamt === 0) {
        $z[] = array(mi_e(mi_t('PRUEF.ABO_EINGETRAGEN')), 2, mi_t('UI.ABO_LISTE_LEER'));
    } elseif ($eingetragen === 'ja') {
        $z[] = array(mi_e(mi_t('PRUEF.ABO_EINGETRAGEN')), 1,
            sprintf(mi_t('UI.ABO_EINGETRAGEN_JA'), mi_e(implode(', ', $treffer)), $gesamt));
    } else {
        $z[] = array(mi_e(mi_t('PRUEF.ABO_EINGETRAGEN')), 0,
            sprintf(mi_t('UI.ABO_EINGETRAGEN_NEIN'), mi_e(mi_mqtt_topic($cfg)), $gesamt));
    }

    // ---- Die eigene Datei gegen sich selbst ----
    list($tok, $tl, $tv, $tf, $laenge) = mi_smactive_probe();
    $z[] = array(mi_e(mi_t('PRUEF.REITER')), $laenge === 0 ? 2 : ($tok ? 1 : 0),
        $laenge === 0 ? mi_t('UI.NICHTS_ANGESEHEN')
            : sprintf(mi_t('UI.REITER_ZAHLEN'), $tl, $tv, $tf));

    list($fok, $fform, $fmerk) = mi_formularprobe();
    $z[] = array(mi_e(mi_t('PRUEF.FORMULARE')), $fform === 0 ? 2 : ($fok ? 1 : 0),
        $fform === 0 ? mi_t('UI.NICHTS_ANGESEHEN')
            : sprintf(mi_t('UI.FORMULAR_ZAHLEN'), $fform, $fmerk));

    list($thok, $thg, $thn, $thd) = mi_themen_probe();
    $z[] = array(mi_e(mi_t('PRUEF.THEMEN')), $thok === null ? 2 : ($thok ? 1 : 0),
        $thok === null ? mi_t('UI.DIENST_NICHT_LESBAR')
            : sprintf(mi_t('UI.THEMEN_ZAHLEN'), $thg, $thn)
              . ($thd ? ' &ndash; ' . mi_e(implode(', ', $thd)) : ''));

    list($spok, $spanz, $spfehlt) = mi_sprache_probe();
    $z[] = array(mi_e(mi_t('PRUEF.SPRACHE')), $spanz === 0 ? 2 : ($spok ? 1 : 0),
        $spanz === 0 ? mi_t('UI.NICHTS_ANGESEHEN')
            : ($spok ? sprintf(mi_t('UI.SPRACHE_ZAHLEN'), $spanz)
                     : sprintf(mi_t('UI.SPRACHE_FEHLEN'), count($spfehlt), $spanz,
                               mi_e(implode(', ', $spfehlt)))));

    list($vok, $vanz, $vname) = mi_vorlagen_probe($cfg);
    $z[] = array(mi_e(mi_t('PRUEF.VORLAGEN')), $vanz === 0 ? 2 : ($vok ? 1 : 0),
        $vanz === 0 ? mi_t('UI.NICHTS_ANGESEHEN')
            : ($vok ? sprintf(mi_t('UI.VORLAGEN_OK'), $vanz)
                    : sprintf(mi_t('UI.VORLAGEN_KAPUTT'), mi_e($vname))));

    return $z;
}

/** Geraetesuche anstossen. */
function mi_discover()
{
    $p = mi_paths();
    $skript = $p['datadir'] . '/discover.py';
    if (!is_readable($skript)) {
        return sprintf(mi_t('UI.DISCOVER_FEHLT'), $skript);
    }
    $alt = getcwd();
    @chdir($p['datadir']);
    list($code, $aus) = mi_python(array($skript));
    if ($alt !== false) { @chdir($alt); }
    /* Den Rueckgabewert auswerten, nicht nur die Ausgabe. Bis 4.2.12 wurde
     * $code geholt und nie angesehen: ein abgestuerztes discover.py und ein
     * erfolgreiches ohne Ausgabe endeten fuer den Anwender im gleichen
     * gruenen Kasten. Eine leere Ausgabe bei Rueckgabewert ungleich 0 ist
     * kein "nichts gefunden", sondern das Gegenteil. */
    $text = trim($aus);
    if ($code !== 0) {
        return sprintf(mi_t('UI.DISCOVER_ABBRUCH'), (int) $code) . "\n\n"
             . ($text !== '' ? $text : mi_t('UI.DISCOVER_OHNE_AUSGABE'));
    }
    return $text !== '' ? $text : mi_t('UI.DISCOVER_OHNE_AUSGABE');
}

/**
 * Zustand aller Geraete.
 *
 * Bewusst nur lesend: eine eigene Abfrage wuerde mit dem laufenden Dienst
 * um die Verbindung zum Klimageraet streiten. Wer wirklich schalten will,
 * nimmt den Abschnitt "Schalten" - der geht ueber den Dienst.
 */
function mi_status()
{
    $p = mi_paths();
    $geraete = mi_devices();
    if (!$geraete) {
        return mi_t('UI.STATUS_KEINE_GERAETE');
    }
    $aus = mi_t('UI.STATUS_HINTERLEGT') . "\n";
    foreach ($geraete as $d) {
        $aus .= sprintf("  %-16s %-16s %-4s %s\n", $d['id'], $d['ip'],
                        ($d['token'] !== '' ? 'V3' : 'V2'),
                        html_entity_decode(mi_geraetename($d), ENT_QUOTES, 'UTF-8'));
    }
    list($alter, $zaehler, $ok) = mi_lebenszeichen();
    $aus .= "\n" . mi_t('UI.STATUS_LEBENSZEICHEN') . ' ';
    if ($alter === null) {
        $aus .= mi_t('UI.KEIN_LEBENSZEICHEN');
    } elseif ((int) $ok === -1) {
        $aus .= sprintf(mi_t('UI.HERZSCHLAG_OHNE_DURCHGANG_ROH'), (int) $alter,
                        $zaehler === null ? '?' : (int) $zaehler);
    } else {
        $aus .= sprintf(mi_t('UI.HERZSCHLAG_ALTER_ROH'), (int) $alter,
                        $zaehler === null ? '?' : (int) $zaehler,
                        $ok === null ? '?' : (int) $ok);
    }
    $aus .= "\n\n" . mi_t('UI.STATUS_LETZTE_ZEILEN') . "\n";
    $aus .= "--------------------------------------------------------------\n";
    if (!is_readable($p['log'])) {
        return $aus . mi_t('UI.STATUS_KEIN_LOG');
    }
    $zeilen = mi_log_tail(40);
    $aus .= $zeilen ? implode("\n", array_reverse($zeilen)) : mi_t('UI.LOG_LEER');
    return $aus;
}

/** Die Themenliste gegen den Sendecode des Dienstes halten. */
function mi_themen_bericht()
{
    list($ok, $gesendet, $genannt, $abweichend) = mi_themen_probe();
    if ($ok === null) {
        return mi_t('UI.DIENST_NICHT_LESBAR') . "\n" . mi_paths()['dienst'];
    }
    $aus = sprintf(mi_t('UI.THEMEN_ZAHLEN_ROH'), $gesendet, $genannt) . "\n\n";
    if (!$abweichend) {
        return $aus . mi_t('UI.THEMEN_DECKUNGSGLEICH');
    }
    $aus .= mi_t('UI.THEMEN_ABWEICHEND') . "\n";
    foreach ($abweichend as $t) {
        $aus .= '  ' . $t . "\n";
    }
    return $aus;
}

/**
 * Einen Befehl an den eigenen Dienst schicken.
 *
 * Ueber UDP an den eigenen Dienst, nicht unmittelbar an das Klimageraet:
 * sonst streiten sich zwei Verbindungen um dasselbe Geraet. Der Weg ist
 * damit derselbe, den auch Loxone benutzt - was hier funktioniert,
 * funktioniert dort.
 *
 * Der Trockenlauf ist ein PARAMETER derselben Funktion, kein zweiter Weg.
 * Ein Trockenlauf, der durch anderen Code laeuft, misst anderen Code.
 */
function mi_schalten($cfg, $id, $befehl, $trocken)
{
    $titel = $trocken ? mi_t('UI.T_TROCKEN') : mi_t('UI.T_SCHALTEN');

    // Beide Enden pruefen: die Geraete-ID muss eine der hinterlegten sein,
    // der Befehl einer aus der Liste. Fail closed.
    $ids = array();
    foreach (mi_devices() as $d) { $ids[] = (string) $d['id']; }
    if (!in_array((string) $id, $ids, true)) {
        return array($titel, mi_block(mi_t('UI.SCHALT_ID_UNBEKANNT')));
    }
    $erlaubt = array();
    foreach (mi_befehle() as $b) {
        if ($b[1] !== '<v>') { $erlaubt[] = $b[1]; }
        if ($b[2] !== null)  { $erlaubt[] = $b[2]; }
    }
    if (!in_array((string) $befehl, $erlaubt, true)) {
        return array($titel, mi_block(mi_t('UI.SCHALT_BEFEHL_UNBEKANNT')));
    }

    $ip   = mi_cfg($cfg, 'LoxberryIP', '127.0.0.1');
    $port = (int) mi_cfg($cfg, 'UDP_PORT', '7013');
    $paket = $id . ' ' . $befehl;

    $text  = sprintf(mi_t('UI.SCHALT_ZIEL'), $ip, $port) . "\n";
    $text .= mi_t('UI.SCHALT_PAKET') . ' ' . $paket . "\n";

    if ($trocken) {
        $text .= "\n" . mi_t('UI.SCHALT_TROCKEN_HINWEIS');
        return array($titel, mi_block($text));
    }

    $s = @fsockopen('udp://' . $ip, $port, $errno, $errstr, 3);
    if (!$s) {
        $text .= "\n" . sprintf(mi_t('UI.SCHALT_FEHLER'), (int) $errno, $errstr);
        return array($titel, mi_block($text));
    }
    stream_set_timeout($s, 3);
    $n = @fwrite($s, $paket);
    fclose($s);
    $text .= "\n" . ($n === false
        ? mi_t('UI.SCHALT_NICHT_GESENDET')
        : sprintf(mi_t('UI.SCHALT_GESENDET'), (int) $n));
    $text .= "\n" . mi_t('UI.SCHALT_NACHSEHEN');
    return array($titel, mi_block($text));
}

/** Eine Aktion des Reiters Test ausfuehren. */
function mi_test_ausfuehren($was, $cfg)
{
    switch ($was) {
        case 'discover':
            return array(mi_t('UI.T_SUCHE'), mi_block(mi_discover()));
        case 'status':
            return array(mi_t('UI.T_STATUS'), mi_block(mi_status()));
        case 'themen':
            return array(mi_t('UI.T_THEMEN'), mi_block(mi_themen_bericht()));
        case 'umgebung':
            $p = mi_paths();
            $z = array();
            $z[] = sprintf('%-20s: %s', mi_t('UI.U_ORDNER'), $p['plugin']);
            $z[] = sprintf('%-20s: %s%s', mi_t('UI.U_VENV'), $p['venv'],
                is_executable($p['venv']) ? '  [' . mi_t('UI.VORHANDEN') . ']'
                                          : '  [' . mi_t('UI.U_FEHLT') . ']');   // beide woertlich
            $z[] = sprintf('%-20s: %s', mi_t('UI.U_PYTHON'),
                mi_python_version() ?: mi_t('UI.UNBEKANNT'));
            $z[] = sprintf('%-20s: %s', 'msmart-ng',
                mi_msmart_version() ?: mi_t('UI.NICHT_LADBAR'));
            $z[] = sprintf('%-20s: %s', 'paho-mqtt',
                mi_paho_version() ?: mi_t('UI.UNBEKANNT'));
            $z[] = '';
            foreach (array('config' => 'midea2lox.cfg', 'devices' => 'devices.cfg',
                           'abo' => 'mqtt_subscriptions.cfg',
                           'log' => 'midea2lox.log') as $k => $name) {
                $z[] = sprintf('%-20s: %s', $name, is_readable($p[$k])
                    ? sprintf(mi_t('UI.U_VORHANDEN_KB'),
                              number_format(filesize($p[$k]) / 1024, 1, ',', '.'))
                    : mi_t('UI.U_NICHT_VORHANDEN'));
            }
            $z[] = '';
            $mq = mi_mqtt_config();
            $z[] = sprintf('%-20s: %s', mi_t('UI.U_GATEWAY'), $mq
                ? $mq['host'] . ':' . $mq['port'] . ' (Autostart '
                  . ($mq['autostart'] ? mi_t('UI.EIN') : mi_t('UI.AUS')) . ', V'
                  . (mi_gateway_fassung() ?: '?') . ')'
                : mi_t('UI.GATEWAY_UNLESBAR'));
            $z[] = sprintf('%-20s: %s', mi_t('UI.U_PRAEFIX'), mi_mqtt_topic($cfg));
            $z[] = sprintf('%-20s: %s', mi_t('UI.U_REGION'),
                mi_cfg($cfg, 'region', '') . ' -> '
                . (mi_region_msmart(mi_cfg($cfg, 'region', '')) ?: '?'));
            return array(mi_t('UI.T_UMGEBUNG'), mi_block(implode("\n", $z)));
    }
    return array(mi_t('UI.T_UNBEKANNT'),
        '<p class="sm-small">' . mi_e(mi_t('UI.T_UNBEKANNT_TEXT')) . '</p>');
}
