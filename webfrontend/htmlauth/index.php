<?php
/**
 * Midea2Lox - Bedienoberflaeche
 *
 * Ausschliesslich Oberflaeche. Die Werte holt der Dienst data/midea2lox.py,
 * der ueber system/daemons laeuft; Befehle nimmt er per UDP entgegen.
 *
 * ==================================================================
 * REIHENFOLGE IN DIESER DATEI - BAUVORSCHRIFT, NICHT GESCHMACK
 * ==================================================================
 *   1. Bibliothek laden
 *   2. Konfiguration lesen, Vorgaben vervollstaendigen
 *   3. WACHPOSTEN
 *   4. Reiterwahl
 *   5. ALLE Handler - darunter JEDER Download, der mit exit endet
 *   6. ERST JETZT LBWeb::lbheader()
 *   7. HTML
 *
 * Stand ein Download hinter dem Kopf, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON statt
 * einer Datei. Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos
 * und headers_sent() immer falsch.
 */

require_once 'loxberry_web.php';
require_once __DIR__ . '/mi_lib.php';

$mi_p        = mi_paths();
$mi_meldung  = '';
$mi_fehler   = array();
$mi_hinweise = array();

$mi_cfg = mi_config_read();
$mi_fehlten = mi_cfg_fehlende();

/* ---------------------------------------------------------------- *
 * 3. Der Wachposten
 *
 * EIN Posten, nicht sieben einzelne Abfragen. Bis 4.2.12 gab es ihn
 * nicht - der Kopfkommentar behauptete ihn, gemessen trugen neun von
 * neun Formularen kein Merkmal. Damit genuegte eine fremde Seite, um bei
 * einem angemeldeten Anwender den Dienst anzuhalten oder die
 * Sicherungsdatei samt Midea-Passwort abzuholen.
 * ---------------------------------------------------------------- */
$mi_wache = mi_wachposten();
if ($mi_wache !== '') {
    // Abgewiesen heisst gemeldet - und es wird NICHTS ausgefuehrt.
    $_POST = array();
    $mi_fehler[] = $mi_wache;
}

/* Aktiver Reiter.
 *
 * Er kommt aus dem abgesendeten Formular (activetab) oder aus der Adresse
 * (?form=...). Letzteres brauchen die Reiter, seit sie echte Verweise sind.
 * Die Positivliste MUSS jeden Reiter enthalten - fehlt einer, ist er
 * sichtbar und anklickbar, aber nach jedem Absenden springt die Seite
 * zurueck auf Einstellungen. Die Zeile darunter, die Reiterleiste im HTML
 * und die id der Flaechen muessen deckungsgleich bleiben; der Reiter Test
 * zaehlt das nach (mi_smactive_probe). */
$mi_muster = '/^tab-(settings|mqtt|loxone|automatik|test|log)$/';
$mi_wunsch = isset($_POST['activetab']) && is_string($_POST['activetab'])
    ? $_POST['activetab']
    : (isset($_GET['form']) && is_string($_GET['form']) ? 'tab-' . $_GET['form'] : '');
$mi_tab = preg_match($mi_muster, $mi_wunsch) ? $mi_wunsch : 'tab-settings';

$mi_test_titel = '';
$mi_test_text  = '';

/* GENAU EINE Aktion je Anfrage.
 *
 * Bis 4.3.2 stand jeder Handler fuer sich, und ein POST, das zwei
 * Aktionsfelder trug, loeste BEIDE aus. Gemessen mit "speichern" und
 * "dienst=stop" zugleich: der UDP-Port wurde geschrieben, angezeigt wurde
 * aber ausschliesslich der rote Kasten "Es wurde nichts gespeichert" - die
 * Ueberschrift log. Ein Sicherheitsloch war es nicht (ohne gueltiges
 * Formularmerkmal kommt nichts durch), eine falsche Auskunft schon.
 *
 * Fail closed: bei mehr als einer Aktion wird KEINE ausgefuehrt. */
$mi_aktionen = array('speichern', 'speichern_suchen', 'mqtt_speichern',
                     'bez_speichern', 'dienst', 'test', 'schalten', 'vorlage',
                     'mi_sichern', 'mi_zurueck', 'auto_speichern');
$mi_gesetzt = array();
foreach ($mi_aktionen as $mi_a) {
    if (isset($_POST[$mi_a])) { $mi_gesetzt[] = $mi_a; }
}
if (count($mi_gesetzt) > 1) {
    $mi_fehler[] = sprintf(mi_t('UI.MEHRERE_AKTIONEN'),
                           mi_e(implode(', ', $mi_gesetzt)));
    $_POST = array();
}

/* Ohne ablegbares Formularmerkmal ist die Oberflaeche nicht bedienbar - das
 * gehoert gesagt, bevor der erste Knopf ins Leere greift. */
if (mi_formtoken() === '') {
    $mi_hinweise[] = sprintf(mi_t('UI.WACHE_UNABLEGBAR'), mi_e($mi_p['datadir']));
}

/* Fehlende Schluessel werden AUFGESCHRIEBEN, nicht bei jedem Lauf neu
 * angenommen. Ein stiller Vorgabewert ist eine Annahme, keine Auskunft. */
if ($mi_fehlten && $mi_wache === '') {
    if (mi_config_write($mi_cfg)) {
        $mi_hinweise[] = sprintf(mi_t('UI.CFG_ERGAENZT'), mi_e(implode(', ', $mi_fehlten)));
    }
}

/* ---------------------------------------------------------------- *
 * 5. Handler
 * ---------------------------------------------------------------- */

// ---------- Einstellungen speichern ----------
if (isset($_POST['speichern']) || isset($_POST['speichern_suchen'])) {
    $neu = $mi_cfg;

    /* Jedes Feld durch mi_wert_pruefen() - dieselbe Positivliste, die auch
     * die zurueckgespielte Sicherung benutzt. Eine zweite Wahrheit ueber
     * zulaessige Werte gibt es nicht. */
    foreach (array('MINISERVER', 'UDP_PORT', 'maxConnectionLifetime',
                   'region', 'abfragetakt', 'lox_timeout') as $feld) {
        $roh = isset($_POST[$feld]) ? $_POST[$feld] : '';
        if (!is_string($roh)) {
            $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), mi_e($feld));
            continue;
        }
        $roh = trim($roh);
        $f = mi_wert_pruefen($feld, $roh);
        if ($f !== '') {
            $mi_fehler[] = $f;
        } else {
            $neu[$feld] = $roh;
        }
    }

    $neu['DEBUG'] = (isset($_POST['DEBUG']) && $_POST['DEBUG'] === '1') ? '1' : '0';

    /* is_string() ZUERST. Mit MideaPassword[]=x war die Pruefung "leer heisst
     * unveraendert" bis 4.2.12 wirkungslos: ein Feld ist nicht '', der Wert
     * ging durch, und in der Datei stand danach MideaPassword=Array - das
     * gespeicherte Kennwort war zerstoert. Gemessen. */
    /* Auch diese beiden durch mi_wert_pruefen().
     *
     * Bis 4.3.2 wurde hier nur is_string() geprueft. Ein Kennwort mit einem
     * Zeilenumbruch darin fiel dann erst in mi_config_write() ueber
     * mi_wert_taugt() - richtig abgewiesen, aber gemeldet als "Die Datei
     * midea2lox.cfg liess sich nicht schreiben. Bitte die Rechte im
     * Konfigurationsordner pruefen." Der Anwender suchte damit im
     * Rechteordner nach einem Fehler, der in seinem Eingabefeld stand.
     * Gemessen. */
    if (isset($_POST['MideaUser'])) {
        if (!is_string($_POST['MideaUser'])) {
            $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), 'MideaUser');
        } else {
            $f = mi_wert_pruefen('MideaUser', trim($_POST['MideaUser']));
            if ($f !== '') {
                $mi_fehler[] = $f;
            } else {
                $neu['MideaUser'] = trim($_POST['MideaUser']);
            }
        }
    }
    if (isset($_POST['MideaPassword'])) {
        if (!is_string($_POST['MideaPassword'])) {
            $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), 'MideaPassword');
        } elseif ($_POST['MideaPassword'] !== ''
                  && ($f = mi_wert_pruefen('MideaPassword', $_POST['MideaPassword'])) !== '') {
            $mi_fehler[] = $f;
        } elseif ($_POST['MideaPassword'] !== '') {
            // Leeres Passwortfeld heisst "unveraendert lassen", nicht
            // "loeschen" - geloescht wird ueber den ausdruecklichen Haken.
            $neu['MideaPassword'] = $_POST['MideaPassword'];
        }
    }
    if (isset($_POST['MideaPassword_loeschen']) && $_POST['MideaPassword_loeschen'] === '1') {
        $neu['MideaPassword'] = '';
    }

    /* Auch der selbst ermittelte Wert wird geprueft.
     *
     * mi_localip() faellt auf gethostbyname(gethostname()) zurueck, und das
     * liefert bei Misserfolg den HOSTNAMEN zurueck, nicht etwa nichts. In
     * der Datei stand dann "LoxberryIP=loxberry" - ein Wert, den die eigene
     * Positivliste abweist, und der Dienst endete beim Binden mit
     * "Bind failed". Ist der Wert unbrauchbar, bleibt der bisherige stehen
     * und die Seite sagt es. */
    $mi_ipneu = mi_localip();
    if (mi_wert_pruefen('LoxberryIP', $mi_ipneu) === '') {
        $neu['LoxberryIP'] = $mi_ipneu;
    } else {
        $mi_hinweise[] = sprintf(mi_t('UI.LOCALIP_UNBRAUCHBAR'), mi_e($mi_ipneu));
    }

    if (!$mi_fehler) {
        if (mi_config_write($neu)) {
            $mi_cfg = mi_config_read();
            if (isset($_POST['speichern_suchen'])) {
                require_once __DIR__ . '/mi_test.php';
                /* Auch dieser Zweig zieht den Dienst nach.
                 *
                 * Bis 4.3.2 fehlte der Neustart hier als einzigem
                 * Speicherweg. Wer den UDP-Port aenderte und diesen Knopf
                 * nahm, hatte danach eine Datei mit dem neuen Port, einen
                 * Dienst auf dem alten - der Python-Teil liest den Port nur
                 * beim Start - und einen Reiter Loxone, der ab sofort den
                 * neuen dokumentierte. */
                $was = mi_dienst('restart');
                list($mi_test_titel, $mi_test_text) = mi_test_ausfuehren('discover', $mi_cfg);
                $mi_meldung = ($was === '')
                    ? mi_t('UI.GESPEICHERT_SUCHE')
                    : mi_t('UI.GESPEICHERT_SUCHE_OHNE_DIENST');
                $mi_tab = 'tab-test';
            } else {
                $was = mi_dienst('restart');
                if ($was === '') {
                    $mi_meldung = mi_t('UI.GESPEICHERT_NEUSTART');
                } else {
                    $mi_meldung = mi_t('UI.GESPEICHERT_OHNE_DIENST');
                }
                $mi_tab = 'tab-settings';
            }
        } else {
            $mi_fehler[] = mi_t('UI.CFG_SCHREIBFEHLER');
        }
    }
    if ($mi_fehler) {
        $mi_tab = 'tab-settings';
    }
}

// ---------- MQTT speichern (eigener Handler, eigener Reiter) ----------
if (isset($_POST['mqtt_speichern'])) {
    $roh = isset($_POST['mqtt_praefix']) ? $_POST['mqtt_praefix'] : '';
    if (!is_string($roh)) {
        $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), 'mqtt_praefix');
    } else {
        $roh = trim($roh, "/ \t\n\r\0\x0B");
        $f = mi_wert_pruefen('mqtt_praefix', $roh);
        if ($f !== '') {
            $mi_fehler[] = $f;
        } else {
            $neu = $mi_cfg;
            $neu['mqtt_praefix'] = $roh;
            if (mi_config_write($neu)) {
                $mi_cfg = mi_config_read();
                /* Das Abo wandert mit. Wer das Praefix aendert und die
                 * mqtt_subscriptions.cfg stehen laesst, abonniert danach
                 * einen Zweig, in den niemand mehr schreibt.
                 *
                 * Der Rueckgabewert wird ANGESEHEN. Bis 4.3.2 wurde er
                 * verworfen, und die Seite meldete gruen "Das Abo wurde
                 * mitgeschrieben", waehrend im selben Seitenaufbau darunter
                 * stand, die Datei fehle. Zwei einander widersprechende
                 * Saetze auf einer Seite - gemessen. */
                if (!mi_abo_datei_schreiben($mi_cfg)) {
                    $mi_hinweise[] = sprintf(mi_t('UI.ABO_SCHREIBFEHLER'),
                                             mi_e($mi_p['abo']));
                }
                $was = mi_dienst('restart');
                if ($was === '') {
                    $mi_meldung = mi_t('UI.MQTT_GESPEICHERT');
                } else {
                    $mi_meldung = mi_t('UI.GESPEICHERT_OHNE_DIENST');
                }
            } else {
                $mi_fehler[] = mi_t('UI.CFG_SCHREIBFEHLER');
            }
        }
    }
    $mi_tab = 'tab-mqtt';
}

// ---------- Die Automatik speichern ----------
if (isset($_POST['auto_speichern'])) {
    $mi_neu_auto = $mi_cfg;
    /* Jedes Feld durch mi_wert_pruefen(), und Beanstandungen werden
     * GESAMMELT, nicht ueberschrieben - sonst berichtigt der Anwender einen
     * Fehler nach dem anderen statt alle auf einmal. */
    foreach (array('auto_ein', 'auto_thema_regel', 'auto_thema_pv', 'auto_pv_ab',
                   'auto_verschiebung', 'auto_soll_min', 'auto_soll_max',
                   'auto_turbo', 'auto_schalten', 'auto_sperrzeit',
                   'auto_max_alter', 'auto_takt', 'auto_geraete') as $mi_k) {
        if (!isset($_POST[$mi_k])) { continue; }
        if (!is_string($_POST[$mi_k])) {
            $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), mi_e($mi_k));
            continue;
        }
        $mi_w = trim($_POST[$mi_k]);
        $mi_f = mi_wert_pruefen($mi_k, $mi_w);
        if ($mi_f !== '') {
            $mi_fehler[] = $mi_f;
            continue;
        }
        $mi_neu_auto[$mi_k] = $mi_w;
    }
    /* Zwei Werte, die einzeln stimmen und zusammen nicht: eine Untergrenze
     * ueber der Obergrenze. Der Dienst faengt das ab und bleibt aus - hier
     * gehoert es aber schon gesagt, statt es erst im Protokoll zu finden. */
    if (!$mi_fehler
        && (int) $mi_neu_auto['auto_soll_min'] > (int) $mi_neu_auto['auto_soll_max']) {
        $mi_fehler[] = mi_t('UI.PRUEF_SOLL_VERDREHT');
    }
    /* Eingeschaltet ohne ein einziges Thema waere ein Schalter ohne Wirkung. */
    if (!$mi_fehler && $mi_neu_auto['auto_ein'] === '1'
        && $mi_neu_auto['auto_thema_regel'] === ''
        && $mi_neu_auto['auto_thema_pv'] === '') {
        $mi_fehler[] = mi_t('UI.PRUEF_AUTO_OHNE_THEMA');
    }
    if (!$mi_fehler) {
        if (!mi_config_write($mi_neu_auto)) {
            $mi_fehler[] = mi_t('UI.CFG_SCHREIBFEHLER');
        } else {
            $mi_cfg = mi_config_read();
            // Der Dienst liest die Automatik NUR beim Start - ohne Neustart
            // gilt weiter, was vorher eingestellt war.
            $mi_was_auto = mi_dienst('restart');
            $mi_meldung = ($mi_was_auto === '')
                ? mi_t('UI.AUTO_GESPEICHERT')
                : mi_t('UI.AUTO_GESPEICHERT_OHNE_DIENST');
        }
    }
    $mi_tab = 'tab-automatik';
}

// ---------- Bezeichnungen der Geraete speichern ----------
if (isset($_POST['bez_speichern'])) {
    $zuordnung = array();
    $roh = isset($_POST['bezeichnung']) ? $_POST['bezeichnung'] : array();
    if (!is_array($roh)) {
        $mi_fehler[] = sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), 'bezeichnung');
    } else {
        foreach ($roh as $id => $wert) {
            $id = trim((string) $id);
            // 10 bis 19 Ziffern - dieselbe Grenze wie im Dienst.
            // data/midea2lox.py sucht die Geraetenummer mit
            // "len(eachArg) in range(10,20) and eachArg.isdigit()". Eine
            // neun- oder zwanzigstellige Nummer nahm die Oberflaeche bis
            // 4.3.2 an, der Dienst erkannte sie nie als Geraetenummer.
            if (!preg_match('/^\d{10,19}$/', $id)) {
                $mi_fehler[] = sprintf(mi_t('UI.PRUEF_GERAETE_ID'), mi_e($id));
                continue;
            }
            if (!is_string($wert) || !mi_wert_taugt($wert) || strlen(trim($wert)) > 120) {
                $mi_fehler[] = sprintf(mi_t('UI.PRUEF_BEZEICHNUNG'), mi_e($id));
                continue;
            }
            $zuordnung[$id] = trim($wert);
        }
    }
    // Alle Beanstandungen melden - aber nur dann speichern, wenn keine da
    // ist. Melden ist richtig, halb speichern nicht.
    if (!$mi_fehler && $zuordnung) {
        if (mi_devices_bezeichnung_schreiben($zuordnung)) {
            $mi_meldung = mi_t('UI.BEZ_GESPEICHERT');
        } else {
            $mi_fehler[] = mi_t('UI.BEZ_SCHREIBFEHLER');
        }
    }
    $mi_tab = 'tab-settings';
}

// ---------- Dienst steuern ----------
if (isset($_POST['dienst'])) {
    $was = is_string($_POST['dienst']) ? $_POST['dienst'] : '';
    $ergebnis = mi_dienst($was);
    if ($ergebnis === '') {
        if ($was === 'stop') {
            $mi_meldung = mi_t('UI.DIENST_ANGEHALTEN');
        } elseif ($was === 'start') {
            $mi_meldung = mi_t('UI.DIENST_GESTARTET');
        } else {
            $mi_meldung = mi_t('UI.DIENST_NEU');
        }
    } elseif ($ergebnis === 'fehlt') {
        // Bis 4.2.12 meldeten BEIDE Faelle "Startskript fehlt" - auch der,
        // in dem gar keine gueltige Aktion angefordert war.
        $mi_fehler[] = sprintf(mi_t('UI.DIENST_SKRIPT_FEHLT'), mi_e($mi_p['daemon']));
    } elseif ($ergebnis === 'fehlgeschlagen') {
        // Seit 4.4.0 unterscheidbar: das Startskript LIEF, hat aber mit
        // einem Fehler geendet. Bis dahin meldete die Oberflaeche in diesem
        // Fall gruen "Der Dienst wurde gestartet".
        $mi_fehler[] = mi_t('UI.DIENST_FEHLGESCHLAGEN');
    } else {
        $mi_fehler[] = mi_t('UI.DIENST_UNBEKANNT');
    }
    $mi_tab = 'tab-settings';
}

// ---------- Pruefungen und Schaltbefehle des Reiters Test ----------
if (isset($_POST['test'])) {
    require_once __DIR__ . '/mi_test.php';
    $was = is_string($_POST['test']) ? $_POST['test'] : '';
    list($mi_test_titel, $mi_test_text) = mi_test_ausfuehren($was, $mi_cfg);
    $mi_tab = 'tab-test';
}
if (isset($_POST['schalten'])) {
    require_once __DIR__ . '/mi_test.php';
    $id  = isset($_POST['schalt_id'])  && is_string($_POST['schalt_id'])  ? $_POST['schalt_id']  : '';
    $bef = isset($_POST['schalt_bef']) && is_string($_POST['schalt_bef']) ? $_POST['schalt_bef'] : '';
    $trocken = isset($_POST['schalten']) && $_POST['schalten'] === 'trocken';
    list($mi_test_titel, $mi_test_text) = mi_schalten($mi_cfg, $id, $bef, $trocken);
    $mi_tab = 'tab-test';
}

// ---------- Loxone-Vorlagen herunterladen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vorlage'])) {
    $welche = is_string($_POST['vorlage']) ? $_POST['vorlage'] : '';
    if ($welche === 'ausgang') {
        list($mi_vname, $mi_vinhalt) = mi_vorlage_ausgang($mi_cfg);
    } else {
        list($mi_vname, $mi_vinhalt) = mi_vorlage($mi_cfg);
    }
    header('Content-Type: application/x-download');
    header('Content-Disposition: attachment; filename="' . $mi_vname . '"');
    echo $mi_vinhalt;
    exit;
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Bei diesem
 * Plugin ist das nicht das Midea-Kennwort, sondern token und key je Geraet
 * aus devices.cfg: nur damit kommt das Plugin ohne die Wolke an ein
 * V3-Geraet. Ohne sie stuenden nach dem Zurueckspielen alle Felder richtig,
 * und man muesste trotzdem neu suchen lassen - die Datei waere fuer ihren
 * eigentlichen Zweck, den Umzug, wertlos.
 *
 * Damit traegt sie ein Geheimnis, und der Warnkasten am Knopf sagt das.
 *
 * Bis 4.2.12 stand hier mi_cfg() ohne Argumente. Die Funktion verlangt zwei;
 * gemessen unter 7.4.33 und 8.4.24 endete der Knopf mit ArgumentCountError,
 * Rueckgabewert 255 und 0 Byte Ausgabe - auf jeder Anlage eine leere Seite.
 * Es ist mi_config_read(), das die volle Konfiguration liefert. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mi_sichern'])) {
    /* JSON_INVALID_UTF8_SUBSTITUTE: eine von Hand in Latin-1 bearbeitete
     * devices.cfg liess json_encode() bis 4.3.2 mit false enden, und der
     * Knopf "Einstellungen sichern" meldete daraufhin, es liesse sich nichts
     * SCHREIBEN - dabei wird beim Herunterladen gar nichts geschrieben. */
    $mi_js = json_encode(mi_sicherung_bauen($mi_cfg),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($mi_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="midea2lox_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $mi_js;
        exit;
    }
    $mi_fehler[] = mi_t('UI.SICH_DL_FEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei des
 * Servers unterschieben. Dann die Groessengrenze - eine Sicherung dieses
 * Plugins ist wenige Kilobyte gross; alles darueber wird gar nicht gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mi_zurueck'])) {
    /* Den Fehlercode ZUERST. Eine Datei, die an upload_max_filesize
     * gescheitert ist, kommt mit leerem tmp_name an und wurde bis 4.3.2 als
     * "Es wurde keine Datei ausgewaehlt" gemeldet - der Anwender hatte aber
     * eine ausgewaehlt, sie war nur zu gross fuer den Server. */
    $mi_uf = isset($_FILES['mi_sicherung']['error']) ? (int) $_FILES['mi_sicherung']['error'] : -1;
    if ($mi_uf !== UPLOAD_ERR_OK && $mi_uf !== -1 && $mi_uf !== UPLOAD_ERR_NO_FILE) {
        $mi_fehler[] = sprintf(mi_t('UI.SICH_UPLOAD_FEHLER'), $mi_uf);
    } elseif (!isset($_FILES['mi_sicherung']) || !is_array($_FILES['mi_sicherung'])
        || !isset($_FILES['mi_sicherung']['tmp_name'])
        || !is_string($_FILES['mi_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['mi_sicherung']['tmp_name'])) {
        $mi_fehler[] = mi_t('UI.SICH_KEINE_DATEI');
    } elseif ((int) $_FILES['mi_sicherung']['size'] > 262144) {
        $mi_fehler[] = mi_t('UI.SICH_ZU_GROSS');
    } else {
        $mi_erg = mi_sicherung_lesen(
            (string) @file_get_contents($_FILES['mi_sicherung']['tmp_name']));
        list($mi_neu, $mi_mangel, $mi_n, $mi_ng) = $mi_erg;
        $mi_unberuehrt = isset($mi_erg[4]) && is_array($mi_erg[4]) ? $mi_erg[4] : array();
        if ($mi_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert wird
             * nichts. Eine zur Haelfte uebernommene Konfiguration ist
             * schlimmer als die alte, und man sieht es ihr nicht an. */
            $mi_fehler[] = mi_t('UI.SICH_ABGELEHNT') . ' ' . implode(' ', $mi_mangel);
        } elseif (!mi_devices_write($mi_neu[1])) {
            /* Die GERAETEDATEI ZUERST.
             *
             * Bis 4.3.2 stand hier midea2lox.cfg zuerst. Gemessen:
             * config_write gelingt, devices_write scheitert - und danach
             * steht die halbe Sicherung auf der Platte, waehrend die Seite
             * "Es wurde nichts gespeichert" ueberschreibt. Genau der
             * Zustand, den mi_sicherung_lesen() mit seinem "entweder den
             * ganzen Stand oder gar keinen" ausschliessen soll.
             *
             * Die Reihenfolge allein macht es nicht unteilbar, aber sie
             * dreht den Schaden um: scheitert der erste Schritt, ist NICHTS
             * geschrieben. Scheitert der zweite, steht die Konfiguration
             * neu und die Geraete alt - und genau das sagt die Meldung
             * dann auch. */
            $mi_fehler[] = mi_t('UI.SICH_GERAETE_SCHREIBFEHLER');
        } elseif (!mi_config_write($mi_neu[0])) {
            $mi_fehler[] = mi_t('UI.SICH_HALB');
        } else {
            /* DIE KONFIGURATION NEU LESEN. Ohne diese Zeile zeigte das
             * Formular darunter weiter den alten Stand; zusammen mit der
             * fehlenden Meldung sah die Seite aus wie vor dem Klick. Wer
             * daraufhin "Speichern" drueckte, schrieb alles zurueck - ausser
             * dem Kennwort, das aus der Sicherung stehen blieb. Genau die
             * halb zurueckgespielte Konfiguration, die mi_sicherung_lesen()
             * verhindern soll. Gemessen. */
            $mi_cfg = mi_config_read();
            if (!mi_abo_datei_schreiben($mi_cfg)) {
                $mi_hinweise[] = sprintf(mi_t('UI.ABO_SCHREIBFEHLER'),
                                         mi_e($mi_p['abo']));
            }
            if ($mi_unberuehrt) {
                // Was die Datei nicht enthielt, ist unveraendert geblieben -
                // und das gehoert gesagt, nicht verschwiegen.
                $mi_hinweise[] = sprintf(mi_t('UI.SICH_UNBERUEHRT'),
                                         mi_e(implode(', ', $mi_unberuehrt)));
            }
            /* Punkt 7 der sieben: den Dienst nachziehen UND sagen, was mit
             * ihm geschehen ist.
             *
             * AUSGESCHRIEBEN statt als Bedingungsausdruck.
             * sprachplatzhalter_pruefen.py erkennt einen Schluessel nur,
             * wenn er woertlich in der Klammer der Sprachfunktion steht, und
             * findet ihn nicht mehr, sobald er hinter einem Fragezeichen
             * liegt - es meldete daraufhin fuer beide Texte "traegt
             * Platzhalter, wird aber nirgends durch sprintf gereicht". Ein
             * Werkzeug, das den Schluessel nicht sieht, prueft ihn auch
             * nicht.
             *
             * (Das Beispiel steht hier bewusst NICHT in der Aufrufform. Ein
             * Erklaertext, der das gesuchte Muster woertlich zeigt, wird vom
             * Sucher getroffen und meldet einen Schluessel, den es nie gab -
             * genau das ist beim Schreiben dieser Zeilen passiert.) */
            $was = mi_dienst('restart');
            if ($was === '') {
                $mi_meldung = sprintf(mi_t('UI.SICH_UEBERNOMMEN'), $mi_n, $mi_ng);
            } else {
                $mi_meldung = sprintf(mi_t('UI.SICH_UEBERNOMMEN_OHNE_DIENST'), $mi_n, $mi_ng);
            }
        }
    }
    $mi_tab = 'tab-settings';
}


$mi_pid     = mi_dienst_pid();
$mi_geraete = mi_devices();
$mi_mq      = mi_mqtt_config();
$mi_topic   = mi_mqtt_topic($mi_cfg);
$mi_port    = mi_cfg($mi_cfg, 'UDP_PORT', '7013');
$mi_ip      = mi_cfg($mi_cfg, 'LoxberryIP', mi_localip());
$mi_bsp     = mi_beispiel_id();

$mi_version = '';
if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
    $mi_version = (string) LBSystem::pluginversion();
}

/* Eine unbekannte Region ist ein Aktualisierungsfall, kein Grund zu raten.
 * Bis 4.2.12 bot die Liste elf Regionen an; msmart-ng kennt drei. */
if (!array_key_exists($mi_cfg['region'], mi_regionen())) {
    $mi_hinweise[] = sprintf(mi_t('UI.REGION_UNBEKANNT'), mi_e($mi_cfg['region']));
}

LBWeb::lbheader('Midea2Lox' . ($mi_version !== '' ? ' V' . $mi_version : ''),
                'https://wiki.loxberry.de/plugins/midea2lox/start', 'help.html');

?>

<style>
.sm-wrap { max-width: 1100px; }
.sm-wrap h2 { color: #4f7d17; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px;
  font-size: 1.15em; margin: 22px 0 8px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.02em; margin: 16px 0 6px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-mono { font-family: monospace; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  text-decoration: none !important; display: inline-block;
  padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
.sm-rollen { overflow-x: auto; max-width: 100%; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; }
.sm-tbl td, .sm-tbl th { border: 1px solid #ddd; padding: 6px 9px; text-align: left; font-size: 0.9em; }
.sm-tbl th { background: #f0f0f0; }
.sm-row { margin: 8px 0; }
.sm-row label { display: block; font-weight: 600; font-size: 0.9em; margin-bottom: 2px; }
.sm-row input, .sm-row select { width: 100%; max-width: 420px; padding: 7px; box-sizing: border-box; }
.sm-alert { padding: 10px 12px; border-radius: 6px; margin: 10px 0; font-size: 0.9em; }
.sm-ok   { background: #eaf5e0; border: 1px solid #6dac20; }
.sm-warn { background: #fdf3e3; border: 1px solid #e0620d; }
.sm-info { background: #eef3f7; border: 1px solid #546e7a; }
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.sm-knopfreihe form { margin: 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.sm-wrap .sm-knopfreihe button, .sm-wrap .sm-btn {
  border: 0 !important; border-radius: 6px !important; padding: 9px 16px !important;
  font-size: 0.9em !important; cursor: pointer; color: #fff !important;
  font-weight: 600 !important; text-shadow: none !important; box-shadow: none !important;
  opacity: 1 !important; margin: 0 !important; text-decoration: none; display: inline-block; }
.sm-wrap .sm-b-lesen button,   .sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-b-lesen button:hover,   .sm-wrap .sm-b-lesen button:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-b-technik button, .sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-b-technik button:hover, .sm-wrap .sm-b-technik button:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-b-aktion button,  .sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
.sm-wrap .sm-b-aktion button:hover,  .sm-wrap .sm-b-aktion button:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
.sm-step { border-left: 3px solid #6dac20; padding: 2px 0 2px 12px; margin: 14px 0; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-family: monospace;
  white-space: pre-wrap; font-size: 0.86em; }
.sm-scheibe { width: 12px; height: 12px; border-radius: 50%; display: inline-block;
  margin-right: 6px; vertical-align: -1px; }
.sm-gruen { background: #6dac20; } .sm-rot { background: #c62828; } .sm-grau { background: #9e9e9e; }
/* Statuskacheln - woertlich aus VORLAGE_hausstandard.css.html. In dieser
   Formatierung ist das <b> die grosse gruene Wertzeile, NICHT die
   Beschriftung. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }
</style>

<div class="sm-wrap">

<?php if ($mi_fehler) { ?>
<div class="sm-alert sm-warn"><b><?php echo mi_t('UI.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($mi_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($mi_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $mi_meldung; ?></div>
<?php } ?>
<?php foreach ($mi_hinweise as $h) { ?>
<div class="sm-alert sm-info"><?php echo $h; ?></div>
<?php } ?>

<!--
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 4.0.0 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und die Reiter
 * liessen sich nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * AUSGESCHRIEBEN, nicht aus einem Feld erzeugt. Eine erzeugte Leiste macht
 * hausstandard_pruefen.py blind: die Spalte "tab" stand fuer dieses Plugin
 * seit jeher auf einem Strich. Dass die drei Stellen dabei auseinanderlaufen
 * koennen, faengt die Selbstpruefung im Reiter Test ab (mi_smactive_probe).
 * Genau dieser Fehler - Schleife statt Ausschreiben - steht zweimal in
 * REGELN_1 unter "eigene Fehler"; die Aufloesung ist nicht "Schleife oder
 * Hand", sondern ausschreiben UND pruefen lassen.
-->
<div class="sm-tabs">
  <a class="sm-tab<?php echo $mi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" data-ziel="tab-settings"
     href="index.php?form=settings"><?php echo mi_te('COMMON.LABEL_SETTINGS'); ?></a>
  <a class="sm-tab<?php echo $mi_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" data-ziel="tab-mqtt"
     href="index.php?form=mqtt"><?php echo mi_te('COMMON.LABEL_MQTT'); ?></a>
  <a class="sm-tab<?php echo $mi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" data-ziel="tab-loxone"
     href="index.php?form=loxone"><?php echo mi_te('COMMON.LABEL_LOXONE'); ?></a>
  <a class="sm-tab<?php echo $mi_tab === 'tab-automatik' ? ' sm-active' : ''; ?>" data-ziel="tab-automatik"
     href="index.php?form=automatik"><?php echo mi_te('COMMON.LABEL_AUTOMATIK'); ?></a>
  <a class="sm-tab<?php echo $mi_tab === 'tab-test' ? ' sm-active' : ''; ?>" data-ziel="tab-test"
     href="index.php?form=test"><?php echo mi_te('COMMON.LABEL_TEST'); ?></a>
  <a class="sm-tab<?php echo $mi_tab === 'tab-log' ? ' sm-active' : ''; ?>" data-ziel="tab-log"
     href="index.php?form=log"><?php echo mi_te('COMMON.LABEL_LOG'); ?></a>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-pane<?php echo $mi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">

<h2><?php echo mi_te('SETTINGS.HEAD_SERVICE'); ?></h2>
<p><span class="sm-scheibe <?php echo $mi_pid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php
if ($mi_pid !== null) {
    echo sprintf(mi_t('UI.DIENST_LAEUFT_PID'), mi_e($mi_pid));
} else {
    echo sprintf(mi_t('UI.DIENST_GESTOPPT_SIEHE'), mi_te('COMMON.LABEL_LOG'));
}
?></p>
<p class="sm-small"><?php echo mi_t('UI.MSMART_NG'); ?> <span class="sm-mono"><?php
  echo mi_e(mi_msmart_version() ?: mi_t('UI.UNBEKANNT')); ?></span> <?php echo mi_t('UI.PYTHON'); ?> <span class="sm-mono"><?php
  echo mi_e(mi_python_version() ?: mi_t('UI.UNBEKANNT')); ?></span></p>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mi_t('UI.LEG_LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mi_t('UI.LEG_TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.LEG_AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="dienst" value="start"><?php echo mi_t('UI.DIENST_STARTEN'); ?></button>
  </form>
</div>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="restart"><?php echo mi_t('UI.DIENST_NEU_STARTEN'); ?></button>
  </form>
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="dienst" value="stop"><?php echo mi_t('UI.DIENST_ANHALTEN'); ?></button>
  </form>
</div>

<form method="post" action="index.php" autocomplete="off">
<?php echo mi_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo mi_te('SETTINGS.HEAD_LOXONE'); ?></h2>
<div class="sm-row">
  <label for="MINISERVER"><?php echo mi_t('UI.MINISERVER'); ?></label>
  <select data-role="none" id="MINISERVER" name="MINISERVER">
<?php
$mi_liste = mi_miniserver_liste();
if (!$mi_liste) {
    echo '<option value="MINISERVER1" selected>MINISERVER1</option>';
} else {
    foreach ($mi_liste as $nr => $ms) {
        $wert = 'MINISERVER' . $nr;
        $name = isset($ms['Name']) ? $ms['Name'] : $wert;
        $ip   = isset($ms['IPAddress']) ? $ms['IPAddress'] : '';
        echo '<option value="' . mi_e($wert) . '"'
           . ($mi_cfg['MINISERVER'] === $wert ? ' selected' : '') . '>'
           . mi_e($name) . ($ip !== '' ? ' (' . mi_e($ip) . ')' : '') . '</option>';
    }
}
?>
  </select>
</div>
<div class="sm-row">
  <label for="UDP_PORT"><?php echo mi_t('UI.UDP_PORT'); ?></label>
  <input data-role="none" type="text" id="UDP_PORT" name="UDP_PORT"
         value="<?php echo mi_e($mi_cfg['UDP_PORT']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.UDP_PORT_HILFE'); ?></p>
</div>
<div class="sm-row">
  <label for="lox_timeout"><?php echo mi_t('UI.LOX_TIMEOUT'); ?></label>
  <input data-role="none" type="text" id="lox_timeout" name="lox_timeout"
         value="<?php echo mi_e($mi_cfg['lox_timeout']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.LOX_TIMEOUT_HILFE'); ?></p>
</div>

<h2><?php echo mi_te('SETTINGS.HEAD_MIDEA'); ?></h2>
<div class="sm-hinweis"><?php echo mi_t('UI.KONTO_ERKLAERUNG'); ?></div>
<div class="sm-row">
  <label for="MideaUser"><?php echo mi_t('UI.BENUTZER'); ?></label>
  <input data-role="none" type="text" id="MideaUser" name="MideaUser"
         value="<?php echo mi_e($mi_cfg['MideaUser']); ?>">
</div>
<div class="sm-row">
  <label for="MideaPassword"><?php echo mi_t('UI.PASSWORT'); ?></label>
  <input data-role="none" type="password" id="MideaPassword" name="MideaPassword" value=""
         placeholder="<?php
             if ($mi_cfg['MideaPassword'] !== '') { echo mi_te('UI.PASSWORT_GESETZT'); }
             else { echo mi_te('UI.PASSWORT_LEER'); } ?>">
  <p class="sm-small">
    <label style="font-weight:normal; display:inline;">
      <input data-role="none" type="checkbox" name="MideaPassword_loeschen" value="1"
             style="width:auto; display:inline;">
      <?php echo mi_t('UI.PASSWORT_LOESCHEN'); ?>
    </label>
  </p>
</div>
<div class="sm-row">
  <label for="region"><?php echo mi_t('UI.REGION'); ?></label>
  <select data-role="none" id="region" name="region">
<?php foreach (mi_regionen() as $k => $v) { ?>
    <option value="<?php echo mi_e($k); ?>"<?php
      echo $mi_cfg['region'] === $k ? ' selected' : ''; ?>><?php
      echo mi_e($v[0]) . ' &ndash; ' . mi_e(sprintf(mi_t('UI.REGION_SERVER'), $v[1])); ?></option>
<?php } ?>
  </select>
  <p class="sm-small"><?php echo mi_t('UI.REGION_HILFE'); ?></p>
</div>

<h2><?php echo mi_te('SETTINGS.HEAD_ADVANCED'); ?></h2>
<div class="sm-row">
  <label for="abfragetakt"><?php echo mi_t('UI.ABFRAGETAKT'); ?></label>
  <input data-role="none" type="text" id="abfragetakt" name="abfragetakt"
         value="<?php echo mi_e($mi_cfg['abfragetakt']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.ABFRAGETAKT_HILFE'); ?></p>
</div>
<div class="sm-row">
  <label for="maxConnectionLifetime"><?php echo mi_t('UI.LEBENSDAUER'); ?></label>
  <input data-role="none" type="text" id="maxConnectionLifetime" name="maxConnectionLifetime"
         value="<?php echo mi_e($mi_cfg['maxConnectionLifetime']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.LEBENSDAUER_HILFE'); ?></p>
</div>
<div class="sm-row">
  <label for="DEBUG"><?php echo mi_t('UI.DEBUG'); ?></label>
  <select data-role="none" id="DEBUG" name="DEBUG">
    <option value="0"<?php echo $mi_cfg['DEBUG'] !== '1' ? ' selected' : ''; ?>><?php echo mi_te('UI.AUS'); ?></option>
    <option value="1"<?php echo $mi_cfg['DEBUG'] === '1' ? ' selected' : ''; ?>><?php echo mi_te('UI.EIN'); ?></option>
  </select>
  <p class="sm-small"><?php echo mi_t('UI.DEBUG_HILFE'); ?></p>
</div>

<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?php echo mi_t('UI.SPEICHERN_NEUSTART'); ?></button>
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_suchen" value="1"><?php echo mi_t('UI.SPEICHERN_SUCHEN'); ?></button>
</div>
</form>

<h2><?php echo mi_te('SETTINGS.HEAD_DEVICES'); ?></h2>
<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-info"><?php echo mi_t('UI.KEINE_GERAETE'); ?></div>
<?php } else { ?>
<form method="post" action="index.php">
<?php echo mi_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:24%"><?php echo mi_te('UI.GERAETE_ID'); ?></th>
    <th style="width:22%"><?php echo mi_te('UI.IP_ADRESSE'); ?></th>
    <th style="width:14%"><?php echo mi_te('UI.PROTOKOLL'); ?></th>
    <th><?php echo mi_te('UI.BEZEICHNUNG'); ?></th></tr>
<?php foreach ($mi_geraete as $d) { ?>
<tr><td class="sm-mono"><?php echo mi_e($d['id']); ?></td>
    <td class="sm-mono"><?php echo mi_e($d['ip']); ?></td>
    <td><?php
        if ($d['token'] !== '' && $d['key'] !== '') { echo mi_te('UI.PROTOKOLL_V3'); }
        else { echo mi_te('UI.PROTOKOLL_V2'); } ?></td>
    <td><input data-role="none" type="text" style="max-width:100%"
        name="bezeichnung[<?php echo mi_e($d['id']); ?>]"
        value="<?php echo mi_e($d['bezeichnung']); ?>"
        placeholder="<?php echo mi_e(mi_geraetename($d)); ?>"></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="bez_speichern" value="1"><?php echo mi_t('UI.BEZ_SPEICHERN'); ?></button>
</div>
</form>
<p class="sm-small"><?php echo mi_t('UI.GERAETE_DATEI'); ?>
<span class="sm-mono"><?php echo mi_e($mi_p['devices']); ?></span> <?php echo mi_t('UI.GERAETE_UEBERLEBT'); ?></p>
<?php } ?>

<h2><?php echo mi_te('UI.H_SICHERUNG'); ?></h2>
<div class="sm-hinweis"><?php echo mi_t('UI.SICH_ERKLAERUNG'); ?></div>
<div class="sm-warnung"><?php echo mi_t('UI.SICH_WARNUNG'); ?></div>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="mi_sichern" value="1"><?php echo mi_t('UI.K_SICHERN'); ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="file" name="mi_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mi_zurueck" value="1"><?php echo mi_t('UI.K_ZURUECK'); ?></button>
  </form>
</div>
<p class="sm-hilfe"><?php echo mi_t('UI.SICH_HILFE'); ?></p>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.LEG_AKTION'); ?></span>
</div>

<h2><?php echo mi_te('UI.H_MQTT_ZUSTAND'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.MQTT_SYSTEMBESTANDTEIL'); ?></p>

<?php if (!$mi_mq) { ?>
<div class="sm-alert sm-warn"><?php echo mi_t('UI.MQTT_UNLESBAR'); ?></div>
<?php } else { ?>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo mi_te('UI.GROESSE'); ?></th><th><?php echo mi_te('UI.WERT'); ?></th></tr>
<tr><td><?php echo mi_te('UI.BROKER'); ?></td><td class="sm-mono"><?php
  echo mi_e($mi_mq['host'] . ':' . $mi_mq['port']); ?></td></tr>
<tr><td><?php echo mi_te('UI.EIGENER_BROKER'); ?></td><td><?php
  if ($mi_mq['local']) { echo mi_te('UI.JA'); } else { echo mi_t('UI.BROKER_FREMD'); } ?></td></tr>
<tr><td><?php echo mi_te('UI.GATEWAY_AUTOSTART'); ?></td><td><?php
  if ($mi_mq['autostart']) { echo mi_te('UI.JA'); } else { echo mi_t('UI.AUTOSTART_AUS'); } ?></td></tr>
<tr><td><?php echo mi_te('UI.BENUTZER'); ?></td><td class="sm-mono"><?php
  if ($mi_mq['user'] !== '') { echo mi_e($mi_mq['user']); } else { echo mi_te('UI.OHNE'); } ?></td></tr>
</table>
</div>
<?php } ?>

<h2><?php echo mi_te('UI.H_MQTT_EINSTELLUNGEN'); ?></h2>
<form method="post" action="index.php">
<?php echo mi_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row">
  <label for="mqtt_praefix"><?php echo mi_t('UI.MQTT_PRAEFIX'); ?></label>
  <input data-role="none" type="text" id="mqtt_praefix" name="mqtt_praefix"
         value="<?php echo mi_e($mi_cfg['mqtt_praefix']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.MQTT_PRAEFIX_HILFE'); ?></p>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtt_speichern" value="1"><?php echo mi_t('UI.MQTT_SPEICHERN'); ?></button>
</div>
</form>

<h2><?php echo mi_te('UI.H_ABO'); ?></h2>
<div class="sm-hinweis"><?php echo mi_abo_text(); ?></div>
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>
<?php
list($mi_abo_lage, $mi_abo_ist, $mi_abo_soll) = mi_abo_datei($mi_cfg);
if ($mi_abo_lage !== 'ok') { ?>
<div class="sm-alert sm-warn"><?php
  if ($mi_abo_lage === 'fehlt') {
      echo mi_t('UI.ABO_DATEI_FEHLT');
  } else {
      echo sprintf(mi_t('UI.ABO_DATEI_ABWEICHEND'), mi_e($mi_abo_ist), mi_e($mi_abo_soll));
  } ?></div>
<?php } ?>

<h2><?php echo mi_te('UI.H_THEMEN'); ?></h2>
<?php if (!$mi_geraete) { ?>
<p class="sm-small"><?php echo sprintf(mi_t('UI.THEMEN_OHNE_GERAETE'), mi_e($mi_topic)); ?></p>
<?php } ?>
<?php
$mi_gruppen = array('grund' => 'UI.GRUPPE_GRUND', 'komfort' => 'UI.GRUPPE_KOMFORT',
                    'energie' => 'UI.GRUPPE_ENERGIE');
foreach ($mi_gruppen as $mi_g => $mi_gt) { ?>
<h3><?php echo mi_te($mi_gt); ?></h3>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo mi_te('UI.THEMA'); ?></th><th style="width:12%"><?php echo mi_te('UI.EINHEIT'); ?></th><th><?php echo mi_te('UI.BEDEUTUNG'); ?></th></tr>
<?php foreach (mi_werte() as $wert => $info) {
        if ($info[2] !== $mi_g) { continue; } ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '/' . $mi_bsp . '/' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo mi_e(mi_t($info[1])); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>

<h3><?php echo mi_te('UI.GRUPPE_STATUS'); ?></h3>
<div class="sm-hinweis"><?php echo mi_t('UI.STATUS_ERKLAERUNG'); ?></div>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo mi_te('UI.THEMA'); ?></th><th style="width:12%"><?php echo mi_te('UI.EINHEIT'); ?></th><th><?php echo mi_te('UI.BEDEUTUNG'); ?></th></tr>
<?php foreach (array_merge(mi_status_werte(), mi_automatik_werte()) as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '/' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo mi_e(mi_t($info[1])); ?></td></tr>
<?php } ?>
</table>
</div>

<p class="sm-small"><b><?php echo mi_t('UI.MQTT_REGELWEG'); ?></b> <?php echo mi_t('UI.UDP_AUSWEICHWEG'); ?></p>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo mi_t('UI.LEG_TECHNIK'); ?></span>
</div>

<h2><?php echo mi_te('UI.H_EINBINDUNG'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.EINBINDUNG_EINLEITUNG'); ?></p>

<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-warn"><?php echo sprintf(mi_t('UI.EINBINDUNG_OHNE_GERAETE'), '123456789'); ?></div>
<?php } ?>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT1'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT1_TEXT'); ?>
</div>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT2'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT2_TEXT'); ?>
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>
<?php echo mi_abo_text(); ?>
</div>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT3'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT3_TEXT'); ?>
<?php foreach (($mi_geraete ?: array(array('id' => $mi_bsp, 'typ' => '', 'bezeichnung' => '', 'name' => mi_e(mi_t('UI.KLIMAGERAET'))))) as $d) { ?>
<p class="sm-small"><?php echo sprintf(mi_t('UI.GERAET_UEBERSCHRIFT'), $d['name'], mi_e($d['id'])); ?></p>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th><?php echo mi_te('UI.TITEL_EINGANG'); ?></th><th style="width:12%"><?php echo mi_te('UI.EINHEIT'); ?></th><th style="width:34%"><?php echo mi_te('UI.BEDEUTUNG'); ?></th></tr>
<?php foreach (mi_werte() as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $d['id'] . '_' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo mi_e(mi_t($info[1])); ?></td></tr>
<?php } ?>
</table>
</div>
<?php } ?>
<p class="sm-small"><?php echo sprintf(mi_t('UI.UDP_WEG'), mi_e($mi_port)); ?></p>
<pre class="sm-pre">\i<?php echo mi_e($mi_topic); ?>/<?php echo mi_e($mi_bsp); ?>/indoor_temperature,\i\v</pre>
<p class="sm-small"><?php echo mi_t('UI.UDP_MUSTER_ERKLAERUNG'); ?></p>
</div>

<?php if ($mi_geraete) { ?>
<div class="sm-step"><b><?php echo mi_te('UI.H_VORLAGE'); ?></b><br><br>
<div class="sm-hinweis"><?php echo mi_t('UI.H_VORLAGE_TEXT'); ?></div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="eingang"><?php echo mi_t('UI.K_VORLAGE'); ?></button>
  </form>
  <form action="index.php" method="post">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="ausgang"><?php echo mi_t('UI.K_VORLAGE_AUS'); ?></button>
  </form>
</div>
<p class="sm-hilfe"><?php echo mi_t('UI.VORLAGE_WARNUNG'); ?></p>
</div>
<?php } ?>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT4'); ?></b><br><br>
<?php echo sprintf(mi_t('UI.SCHRITT4_TEXT'), mi_e($mi_ip), mi_e($mi_port)); ?>
<pre class="sm-pre">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></pre>
<?php echo mi_t('UI.SCHRITT4_TEXT2'); ?>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo mi_te('UI.WAS'); ?></th><th><?php echo mi_te('UI.BEFEHL_EIN'); ?></th><th><?php echo mi_te('UI.BEFEHL_AUS'); ?></th></tr>
<?php foreach (mi_befehle() as $b) { ?>
<tr><td><?php echo mi_e(mi_t($b[0])); ?></td>
    <td class="sm-mono"><?php echo mi_e($mi_bsp . ' ' . $b[1]); ?></td>
    <td class="sm-mono"><?php echo $b[2] !== null ? mi_e($mi_bsp . ' ' . $b[2]) : '&mdash;'; ?></td></tr>
<?php } ?>
</table>
</div>
<?php echo mi_t('UI.SCHRITT4_TEMP'); ?>
</div>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT5'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT5_TEXT'); ?>
</div>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT6'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT6_TEXT'); ?>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th>#</th><th><?php echo mi_te('UI.BAUSTEIN_TYP'); ?></th><th><?php echo mi_te('UI.NAME_VORSCHLAG'); ?></th><th><?php echo mi_te('UI.PARAMETER'); ?></th><th><?php echo mi_te('UI.EINGAENGE'); ?></th></tr>
<tr><td>1</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_indoor_temperature'); ?></td><td><?php echo mi_te('UI.BL_P_TEMP1'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>2</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_outdoor_temperature'); ?></td><td><?php echo mi_te('UI.BL_P_TEMP1'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>3</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_target_temperature'); ?></td><td><?php echo mi_te('UI.BL_P_TEMP1'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>4</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_power_state'); ?></td><td><?php echo mi_te('UI.BL_P_DIGITAL'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>5</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_online'); ?></td><td><?php echo mi_te('UI.BL_P_DIGITAL'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>6</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_status_ok'); ?></td><td><?php echo mi_te('UI.BL_P_DIGITAL'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>7</td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_status_ts'); ?></td><td><?php echo mi_te('UI.BL_P_SEKUNDEN'); ?></td><td><?php echo mi_te('UI.BL_GATEWAY'); ?></td></tr>
<tr><td>8</td><td><?php echo mi_te('UI.BL_VA'); ?></td><td>Midea2Lox</td><td><?php echo mi_te('UI.BL_P_ADRESSE'); ?> <span class="sm-mono">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></span></td><td><?php echo mi_te('UI.BL_KEINE'); ?></td></tr>
<tr><td>9</td><td><?php echo mi_te('UI.BL_VAB'); ?></td><td><?php echo mi_te('UI.BL_N_EINAUS'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp . ' power.True'); ?> / <?php echo mi_e($mi_bsp . ' power.False'); ?></td><td><?php echo mi_te('UI.BL_VOM_SCHALTER'); ?></td></tr>
<tr><td>10</td><td><?php echo mi_te('UI.BL_VAB'); ?></td><td><?php echo mi_te('UI.BL_N_SOLL'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp . ' <v>'); ?></td><td><?php echo mi_te('UI.BL_VON_18'); ?></td></tr>
<tr><td>11</td><td><?php echo mi_te('UI.BL_NICHT'); ?></td><td><?php echo mi_te('UI.BL_N_OFFLINE'); ?></td><td><?php echo mi_te('UI.BL_KEINE'); ?></td><td><?php echo mi_te('UI.BL_EINGANG5'); ?></td></tr>
<tr><td>12</td><td><?php echo mi_te('UI.BL_EINVERZ'); ?></td><td><?php echo mi_te('UI.BL_N_AUSFALL'); ?></td><td><?php echo mi_te('UI.BL_P_VERZ'); ?> <b>600</b> s</td><td><?php echo mi_te('UI.BL_EINGANG11'); ?></td></tr>
<tr><td>13</td><td><?php echo mi_te('UI.BL_FORMEL'); ?></td><td><?php echo mi_te('UI.BL_N_ALTER'); ?></td><td class="sm-mono">(T-1230768000)-I1</td><td><?php echo mi_te('UI.BL_ALTER_EIN'); ?></td></tr>
<tr><td>14</td><td><?php echo mi_te('UI.BL_SCHWELLE'); ?></td><td><?php echo mi_te('UI.BL_N_ZUALT'); ?></td><td><?php echo mi_te('UI.BL_P_SCHWELLE'); ?></td><td><?php echo mi_te('UI.BL_ALTER_AUS'); ?></td></tr>
<tr><td>15</td><td><?php echo mi_te('UI.BL_ODER_TYP'); ?></td><td><?php echo mi_te('UI.BL_N_SAMMEL'); ?></td><td><?php echo mi_te('UI.BL_KEINE'); ?></td><td><?php echo mi_te('UI.BL_ODER'); ?></td></tr>
<tr><td>16</td><td><?php echo mi_te('UI.BL_STATUS'); ?></td><td><?php echo mi_te('UI.BL_N_STATUS'); ?></td><td><?php echo mi_te('UI.BL_P_STATUS'); ?></td><td>v1 = #1, v2 = #3</td></tr>
<tr><td>17</td><td><?php echo mi_te('UI.BL_BENACHR'); ?></td><td><?php echo mi_te('UI.BL_N_BENACHR'); ?></td><td><?php echo mi_te('UI.BL_P_BENACHR'); ?></td><td><?php echo mi_te('UI.BL_VOM_ODER'); ?></td></tr>
<tr><td>18 <i><?php echo mi_te('UI.OPTIONAL'); ?></i></td><td><?php echo mi_te('UI.BL_RAUMREG'); ?></td><td><?php echo mi_te('UI.BL_N_RAUMREG'); ?></td><td><?php echo mi_te('UI.BL_P_RAUMREG'); ?></td><td><?php echo mi_te('UI.BL_RAUMREG_EIN'); ?></td></tr>
<tr><td>19 <i><?php echo mi_te('UI.OPTIONAL'); ?></i></td><td><?php echo mi_te('UI.BL_MERKER'); ?></td><td><?php echo mi_te('UI.BL_N_FREIGABE'); ?></td><td><?php echo mi_te('UI.BL_KEINE'); ?></td><td><?php echo mi_te('UI.BL_FREIGABE_EIN'); ?></td></tr>
<tr><td>20 <i><?php echo mi_te('UI.OPTIONAL'); ?></i></td><td><?php echo mi_te('UI.BL_VE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $mi_bsp . '_real_time_power_usage'); ?></td><td><?php echo mi_te('UI.BL_P_WATT'); ?></td><td><?php echo mi_te('UI.BL_ENERGIE_EIN'); ?></td></tr>
</table>
</div>
<br>
<b><?php echo mi_te('UI.BL_STATUSTEXT'); ?></b>
<pre class="sm-pre"><?php echo mi_e(mi_t('UI.BL_STATUSTEXT_MUSTER')); ?></pre>
<?php echo mi_t('UI.BL_ERLAEUTERUNGEN'); ?>
</div>

<div class="sm-step"><b><?php echo mi_te('UI.SCHRITT7'); ?></b><br><br>
<?php echo mi_t('UI.SCHRITT7_TEXT'); ?>
</div>
</div>

<!-- ============================== Automatik =============================== -->
<div class="sm-pane<?php echo $mi_tab === 'tab-automatik' ? ' sm-active' : ''; ?>" id="tab-automatik">

<div class="sm-alert sm-info"><?php echo mi_t('UI.AUTO_EINLEITUNG'); ?></div>

<?php
list($mi_al, $mi_ad, $mi_aalter) = mi_automatik_lage($mi_cfg);
$mi_apunkt = array('ok' => 'sm-gruen', 'aus' => 'sm-grau', 'fehlt' => 'sm-rot',
                   'alt' => 'sm-rot', 'unlesbar' => 'sm-rot');
?>
<div class="sm-kacheln">
  <div class="sm-kachel">
    <b><span class="sm-scheibe <?php echo $mi_apunkt[$mi_al]; ?>"></span><?php
      echo mi_te('UI.AUTO_LAGE_' . strtoupper($mi_al)); ?></b>
    <?php echo mi_te('UI.AUTO_K_LAGE'); ?>
  </div>
  <div class="sm-kachel">
    <b><?php echo $mi_al === 'ok' ? (int) $mi_ad['geraete'] : '&mdash;'; ?></b>
    <?php echo mi_te('UI.AUTO_K_GERAETE'); ?>
  </div>
  <div class="sm-kachel" style="min-width: 260px;">
    <b style="font-size: 1em;"><?php echo $mi_al === 'ok' && isset($mi_ad['grund'])
        ? mi_e($mi_ad['grund']) : '&mdash;'; ?></b>
    <?php echo mi_te('UI.AUTO_K_GRUND'); ?>
  </div>
</div>

<form method="post" action="index.php">
<input data-role="none" type="hidden" name="fmt" value="<?php echo mi_e(mi_formtoken()); ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-automatik">

<div class="sm-step">
<h3><?php echo mi_te('UI.AUTO_S1'); ?></h3>
<p class="sm-small"><?php echo mi_t('UI.AUTO_S1_TEXT'); ?></p>
<table class="sm-tbl">
<tr><td><?php echo mi_te('UI.AUTO_F_EIN'); ?></td>
    <td><select data-role="none" name="auto_ein">
      <option value="0"<?php echo mi_cfg($mi_cfg, 'auto_ein', '0') === '0' ? ' selected' : ''; ?>><?php echo mi_te('UI.AUS'); ?></option>
      <option value="1"<?php echo mi_cfg($mi_cfg, 'auto_ein', '0') === '1' ? ' selected' : ''; ?>><?php echo mi_te('UI.EIN'); ?></option>
    </select></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_THEMA_REGEL'); ?></td>
    <td><input data-role="none" type="text" name="auto_thema_regel" size="42"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_thema_regel', '')); ?>"
        placeholder="spot_awattar/regel/1/aktiv"></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_THEMA_PV'); ?></td>
    <td><input data-role="none" type="text" name="auto_thema_pv" size="42"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_thema_pv', '')); ?>"
        placeholder="einspeisebremse/ueberschuss"></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_PV_AB'); ?></td>
    <td><input data-role="none" type="text" name="auto_pv_ab" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_pv_ab', '1500')); ?>"> W</td></tr>
</table>
<p class="sm-small"><?php echo mi_t('UI.AUTO_S1_HINWEIS'); ?></p>
</div>

<div class="sm-step">
<h3><?php echo mi_te('UI.AUTO_S2'); ?></h3>
<p class="sm-small"><?php echo mi_t('UI.AUTO_S2_TEXT'); ?></p>
<table class="sm-tbl">
<tr><td><?php echo mi_te('UI.AUTO_F_VERSCHIEBUNG'); ?></td>
    <td><input data-role="none" type="text" name="auto_verschiebung" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_verschiebung', '20')); ?>">
        <span class="sm-small"><?php echo mi_te('UI.AUTO_F_VERSCHIEBUNG_EINHEIT'); ?></span></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_SOLL_MIN'); ?></td>
    <td><input data-role="none" type="text" name="auto_soll_min" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_soll_min', '16')); ?>"> &deg;C</td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_SOLL_MAX'); ?></td>
    <td><input data-role="none" type="text" name="auto_soll_max" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_soll_max', '30')); ?>"> &deg;C</td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_TURBO'); ?></td>
    <td><select data-role="none" name="auto_turbo">
      <option value="0"<?php echo mi_cfg($mi_cfg, 'auto_turbo', '0') === '0' ? ' selected' : ''; ?>><?php echo mi_te('UI.NEIN'); ?></option>
      <option value="1"<?php echo mi_cfg($mi_cfg, 'auto_turbo', '0') === '1' ? ' selected' : ''; ?>><?php echo mi_te('UI.JA'); ?></option>
    </select></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_SCHALTEN'); ?></td>
    <td><select data-role="none" name="auto_schalten">
      <option value="0"<?php echo mi_cfg($mi_cfg, 'auto_schalten', '0') === '0' ? ' selected' : ''; ?>><?php echo mi_te('UI.NEIN'); ?></option>
      <option value="1"<?php echo mi_cfg($mi_cfg, 'auto_schalten', '0') === '1' ? ' selected' : ''; ?>><?php echo mi_te('UI.JA'); ?></option>
    </select></td></tr>
</table>
<div class="sm-alert sm-warn"><?php echo mi_t('UI.AUTO_S2_WARNUNG'); ?></div>
</div>

<div class="sm-step">
<h3><?php echo mi_te('UI.AUTO_S3'); ?></h3>
<p class="sm-small"><?php echo mi_t('UI.AUTO_S3_TEXT'); ?></p>
<table class="sm-tbl">
<tr><td><?php echo mi_te('UI.AUTO_F_SPERRZEIT'); ?></td>
    <td><input data-role="none" type="text" name="auto_sperrzeit" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_sperrzeit', '120')); ?>">
        <span class="sm-small"><?php echo mi_te('UI.AUTO_MINUTEN'); ?></span></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_MAX_ALTER'); ?></td>
    <td><input data-role="none" type="text" name="auto_max_alter" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_max_alter', '900')); ?>">
        <span class="sm-small"><?php echo mi_te('UI.AUTO_SEKUNDEN'); ?></span></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_TAKT'); ?></td>
    <td><input data-role="none" type="text" name="auto_takt" size="8"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_takt', '300')); ?>">
        <span class="sm-small"><?php echo mi_te('UI.AUTO_SEKUNDEN'); ?></span></td></tr>
<tr><td><?php echo mi_te('UI.AUTO_F_GERAETE'); ?></td>
    <td><input data-role="none" type="text" name="auto_geraete" size="42"
        value="<?php echo mi_e(mi_cfg($mi_cfg, 'auto_geraete', '')); ?>"
        placeholder="<?php echo mi_e(mi_t('UI.AUTO_F_GERAETE_LEER')); ?>"></td></tr>
</table>
</div>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.LEG_AKTION'); ?></span>
</div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="auto_speichern" value="1"><?php echo mi_te('UI.AUTO_SPEICHERN'); ?></button>
</div>
</form>

<div class="sm-step">
<h3><?php echo mi_te('UI.AUTO_S4'); ?></h3>
<p class="sm-small"><?php echo mi_t('UI.AUTO_S4_TEXT'); ?></p>
<table class="sm-tbl">
<tr><th><?php echo mi_te('UI.AUTO_TH_THEMA'); ?></th><th><?php echo mi_te('UI.AUTO_TH_BEDEUTUNG'); ?></th></tr>
<?php /* AUS DERSELBEN QUELLE wie der Reiter MQTT und die Loxone-Vorlage.
         Eine zweite, von Hand gepflegte Liste waere eine zweite Wahrheit -
         und die laeuft frueher oder spaeter auseinander. */
foreach (mi_automatik_werte() as $mi_t1 => $mi_t2) { ?>
<tr><td class="sm-mono"><?php echo mi_e(mi_mqtt_topic($mi_cfg) . '/' . $mi_t1); ?></td>
    <td><?php echo mi_te($mi_t2[1]); ?></td></tr>
<?php } ?>
</table>
</div>

</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mi_t('UI.LEG_LESEN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.LEG_AKTION'); ?></span>
</div>

<?php if ($mi_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $mi_test_titel; ?></b></div>
<?php echo $mi_test_text; ?>
<?php } ?>

<h2><?php echo mi_te('TEST.HEAD_SELFCHECK'); ?></h2>
<div class="sm-rollen">
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo mi_te('UI.FRAGE'); ?></th><th><?php echo mi_te('UI.ANTWORT'); ?></th></tr>
<?php
require_once __DIR__ . '/mi_test.php';
foreach (mi_pruefungen($mi_cfg) as $c) {
    // Drei Ausgaenge: Haken, Kreuz, Strich. Ein Strich ist ausdruecklich
    // KEIN Haken - was nicht gemessen werden konnte, sagt das.
    $zeichen = ($c[1] === 1) ? '&#10004; ' : (($c[1] === 0) ? '&#10008; ' : '&ndash; ');
?>
<tr><td><?php echo $c[0]; ?></td><td><?php echo $zeichen . $c[2]; ?></td></tr>
<?php } ?>
</table>
</div>

<h2><?php echo mi_te('UI.H_NACHSEHEN'); ?></h2>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="umgebung"><?php echo mi_t('UI.K_UMGEBUNG'); ?></button>
  </form>
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="status"><?php echo mi_t('UI.K_STATUS'); ?></button>
  </form>
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="themen"><?php echo mi_t('UI.K_THEMEN'); ?></button>
  </form>
</div>

<h2><?php echo mi_te('UI.H_SCHALTEN'); ?></h2>
<div class="sm-warnung"><?php echo mi_t('UI.SCHALTEN_WARNUNG'); ?></div>
<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-info"><?php echo mi_t('UI.KEINE_GERAETE'); ?></div>
<?php } else { ?>
<form method="post" action="index.php">
<?php echo mi_fmt(); ?>
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<div class="sm-row">
  <label for="schalt_id"><?php echo mi_t('UI.SCHALT_GERAET'); ?></label>
  <select data-role="none" id="schalt_id" name="schalt_id">
<?php foreach ($mi_geraete as $d) { ?>
    <option value="<?php echo mi_e($d['id']); ?>"><?php echo $d['name']; ?> (<?php echo mi_e($d['id']); ?>)</option>
<?php } ?>
  </select>
</div>
<div class="sm-row">
  <label for="schalt_bef"><?php echo mi_t('UI.SCHALT_BEFEHL'); ?></label>
  <select data-role="none" id="schalt_bef" name="schalt_bef">
<?php foreach (mi_befehle() as $b) {
        if ($b[1] === '<v>') { continue; } ?>
    <option value="<?php echo mi_e($b[1]); ?>"><?php echo mi_e(mi_t($b[0]) . ' - ' . $b[1]); ?></option>
<?php   if ($b[2] !== null) { ?>
    <option value="<?php echo mi_e($b[2]); ?>"><?php echo mi_e(mi_t($b[0]) . ' - ' . $b[2]); ?></option>
<?php   }
      } ?>
  </select>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="schalten" value="trocken"><?php echo mi_t('UI.K_TROCKEN'); ?></button>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="schalten" value="senden"><?php echo mi_t('UI.K_SENDEN'); ?></button>
</div>
</form>
<?php } ?>

<h2><?php echo mi_te('UI.H_GERAETESUCHE'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.GERAETESUCHE_TEXT'); ?></p>
<div class="sm-knopfreihe">
  <form method="post" action="index.php">
    <?php echo mi_fmt(); ?>
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="discover"><?php echo mi_t('UI.K_SUCHEN'); ?></button>
  </form>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo $mi_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo mi_te('UI.H_LOGDATEIEN'); ?></h2>
<?php
/* ==================================================================
 * DER EIGENE PROTOKOLLINHALT WIRD IMMER GEZEIGT - BEFUND VOM GERAET
 * ==================================================================
 *
 * Bis 4.3.1 stand hier ein ENTWEDER-ODER: gibt es LBWeb::loglist_html(),
 * wird nur die benutzt, sonst der Rueckfall. Am Geraet gemessen
 * (29.08.2026, LoxBerry 4, Gateway V1): der Reiter zeigte ausser der Ueberschrift
 * NICHTS.
 *
 * Der Grund ist keine kaputte Funktion. loglist_html() listet die
 * Protokolle, die ueber LoxBerry::Log angemeldet sind - der Python-Dienst
 * dieses Plugins schreibt seine Datei aber selbst, mit einem
 * RotatingFileHandler, und meldet nichts an. LoxBerry kennt also kein
 * Protokoll und liefert korrekt eine leere Liste.
 *
 * Warum die Pruefkette das nicht gefunden hat: die SDK-Attrappe liefert bei
 * loglist_html() eine feste Zeichenkette ("Protokoll-Attrappe"), also immer
 * etwas. Der Zweig wurde betreten und sah gefuellt aus. Das ist dieselbe zu
 * milde Attrappe wie beim Sprachpfad.
 *
 * Jetzt UND statt ENTWEDER-ODER: die Liste von LoxBerry, falls sie etwas
 * hergibt, und darunter immer das eigene Protokoll - das ist die Datei, in
 * der wirklich etwas steht.
 * ================================================================== */
$mi_lbliste = '';
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    $mi_lbliste = trim((string) LBWeb::loglist_html());
}
if ($mi_lbliste !== '') {
    echo $mi_lbliste;
}
$mi_zeilen = mi_log_tail();
?>
<h3><?php echo mi_te('UI.H_LOG_EIGEN'); ?></h3>
<p class="sm-small"><?php echo mi_t('UI.LOG_RUECKFALL'); ?>
<span class="sm-mono"><?php echo mi_e($mi_p['log']); ?></span></p>
<?php if (!$mi_zeilen) { ?>
<div class="sm-alert sm-info"><?php echo mi_t('UI.LOG_LEER'); ?></div>
<?php } else { ?>
<div class="sm-log"><?php
  foreach ($mi_zeilen as $z) { echo mi_e($z) . "\n"; }
?></div>
<?php } ?>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
    var reiter = document.querySelectorAll('.sm-tab');
    var seiten = document.querySelectorAll('.sm-pane');
    function zeige(ziel) {
        for (var i = 0; i < reiter.length; i++) {
            reiter[i].classList.toggle('sm-active',
                reiter[i].getAttribute('data-ziel') === ziel);
        }
        for (var j = 0; j < seiten.length; j++) {
            seiten[j].classList.toggle('sm-active', seiten[j].id === ziel);
        }
    }
    for (var k = 0; k < reiter.length; k++) {
        reiter[k].addEventListener('click', function (e) {
            e.preventDefault();
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($mi_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
