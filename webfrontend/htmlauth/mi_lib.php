<?php
require_once __DIR__ . '/sprachausgabe.php';     // Stamm, fuer die Vorgaben der Sprachansagen schon beim ersten Lesen der Konfiguration
/**
 * Midea2Lox - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins, data/plugins UND config/system/general.json traegt. Das
 * trifft die uebliche Installation genauso wie eine an einem anderen Ort -
 * und es trifft auch den Fall, dass das Plugin noch als entpacktes Archiv
 * daliegt (dann findet es nichts und gibt einen Leerstring zurueck, was der
 * Aufrufer ohnehin abfangen muss).
 *
 * general.json ist seit 4.5.9 die entscheidende Bedingung (Regeln/06). Bis
 * 4.5.8 genuegten config/plugins und webfrontend - genau diese Ordner
 * hinterlaesst ein Pruefstand auf einem Arbeitsrechner; in WSL gemessen
 * (Pruefung-Midea2Lox-4.5.9, Fall P2) nahm die Oberflaeche einen solchen
 * fremden Baum als Wurzel.
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
                && is_file($d . '/config/system/general.json')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* Die Wurzel in der Reihenfolge der Hausregel: erst die Umgebung, dann die
 * Suche - und DANACH NICHTS MEHR.
 *
 * Bis 4.5.8 stand hier als dritte Stufe ein fest verdrahteter Systempfad
 * (das Heimatverzeichnis des Benutzers loxberry). Er macht jede Suche
 * wirkungslos und trifft auf einem anders installierten LoxBerry die falsche
 * Anlage (Muster 1 der Nachlese; entfernt ebenso in Tibber 0.9.19,
 * VolkswagenID 0.9.24). Ein gesetztes LBHOMEDIR gilt mit config/plugins UND
 * data/plugins darunter - general.json wird dort nicht verlangt, damit die
 * Attrappe der Pruefwerkzeuge (Werkzeuge/lb) weiter traegt. Rueckgabe ''
 * heisst "keine Wurzel". */
function mi_lbhome()
{
    $h = getenv('LBHOMEDIR');
    if ($h && is_dir($h . '/config/plugins') && is_dir($h . '/data/plugins')) {
        return rtrim($h, '/');
    }
    return lb_wurzel_ermitteln();
}

function mi_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = mi_lbhome();
    /* Der Pluginordner. LBPPLUGINDIR ist die Auskunft von LoxBerry SELBST
     * und hat Vorrang; sonst der eigene Ablageort. Der feste Name greift nur,
     * wo der abgeleitete nachweislich kein Pluginordner sein KANN - aus dem
     * ausgepackten Archiv heraus heisst er 'htmlauth'. Er heisst laut
     * plugin.cfg "Midea2Lox" mit grossen Buchstaben, und unter Linux ist das
     * ein Unterschied. Bauart wie tb_paths() in Spotpreis-Tibber 0.9.19. */
    $kein_ordner = array('', '.', '/', 'html', 'htmlauth', 'bin', 'plugins');
    $lbp = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    $lbp_gilt = !in_array($lbp, $kein_ordner, true);
    $ordner = basename(dirname(__FILE__));
    if ($lbp_gilt) {
        $ordner = $lbp;
    } elseif (in_array($ordner, $kein_ordner, true)) {
        $ordner = 'Midea2Lox';
    }
    /* ARCHIVMODUS (seit 4.5.9, Muster 3 der Nachlese). Die Pfade DER ANLAGE
     * gelten nur, wenn diese Bibliothek dort installiert liegt
     * (<Wurzel>/webfrontend/htmlauth/plugins/<ordner>, physisch verglichen)
     * oder der Aufrufer Wurzel UND Ordner ausdruecklich nennt ($LBHOMEDIR und
     * $LBPPLUGINDIR - so arbeiten die Pruefwerkzeuge mit ihrer Attrappe).
     * Sonst ist das ein ausgepacktes Archiv oder ein Pruefordner.
     *
     * Bis 4.5.8 nahm ein Archiv mit gesetztem LBHOMEDIR - am Geraet steht es
     * in /etc/environment - die Anlage: ihre Konfiguration mit dem
     * Midea-Kennwort, und der Knopf "Dienst starten" rief das Startskript der
     * Anlage (in WSL gemessen, Pruefung-Midea2Lox-4.5.9, Fall P3). */
    $gefunden = $home;
    if ($home !== '') {
        $soll = @realpath($home . '/webfrontend/htmlauth/plugins/' . basename(__DIR__));
        $ist = @realpath(__DIR__);
        $installiert = ($soll !== false && $ist !== false && $soll === $ist);
        $ausdruecklich = $lbp_gilt && $home === rtrim((string) getenv('LBHOMEDIR'), '/');
        if (!$installiert && !$ausdruecklich) { $home = ''; }
    }
    if ($home === '') {
        /* Keine Wurzel (Entwicklung, ausgepacktes Archiv, fremder Baum):
         * neben dem Plugin arbeiten, nie an der Laufwerkswurzel. Bis 4.5.8
         * wurden die Pfade hier mit leerem home zusammengesetzt und lauteten
         * /config/plugins/..., /data/plugins/..., /templates/plugins/... -
         * absolute Pfade ausserhalb jedes LoxBerry (Faelle P1 und P6). Kein
         * Startskript und keine venv: die gibt es nur in einer Anlage. */
        $basis = dirname(dirname(__DIR__));
        $p = array(
            'home'      => '',
            'plugin'    => $ordner,
            'config'    => $basis . '/config/midea2lox.cfg',
            'devices'   => $basis . '/config/devices.cfg',
            'abo'       => $basis . '/config/mqtt_subscriptions.cfg',
            'abos'      => '',
            'abos_alt'  => '',
            'log'       => $basis . '/log/midea2lox.log',
            'datadir'   => $basis . '/data',
            'sollmarke' => $basis . '/data/soll_laufen',
            'marke'     => $basis . '/data.upgrade_laeuft',
            'leben'     => $basis . '/data/lebenszeichen.json',
            'automatik' => $basis . '/data/automatik.json',
            'fenster'   => $basis . '/data/fenster.json',
            'anwesenheit' => $basis . '/data/anwesenheit.json',
            'bin'       => $basis . '/bin',
            'venv'      => '',
            'daemon'    => '',
            'general'   => '',
            'lang'      => $basis . '/templates/lang',
            'index'     => __DIR__ . '/index.php',
            'dienst'    => $basis . '/data/midea2lox.py',
            'leben_py'  => $basis . '/data/lebenszeichen.py',
            'mqtt_py'   => $basis . '/data/mi_mqtt.py',
            'praefixe'  => $basis . '/config.mqtt_praefixe',
            'einmal'    => $basis . '/data/einmalmeldung.json',
            'fassungen' => $basis . '/data/fassungen.json',
            // Die gefundene Wurzel, wenn diese Datei NICHT darin installiert
            // liegt (Archivmodus); sonst leer.
            'archiv'    => $gefunden,
        );
        return $p;
    }
    $p = array(
        'home'    => $home,
        'plugin'  => $ordner,
        'config'  => $home . '/config/plugins/' . $ordner . '/midea2lox.cfg',
        'devices' => $home . '/config/plugins/' . $ordner . '/devices.cfg',
        'abo'     => $home . '/config/plugins/' . $ordner . '/mqtt_subscriptions.cfg',
        /* Die Abonnements des Gateways - ZWEI moegliche Dateien.
         *
         * Berichtigt am 13.09.2026 am Geraet: die Liste, die das Gateway
         * heute fuehrt, ist `mqttgateway.json` (dort 52 Eintraege).
         * `subscriptions.json` war auf derselben Anlage eine Leiche vom
         * 28.08. mit fuenf fremden Eintraegen - wer nur dort nachsah,
         * bekam ein Kreuz fuer ein Thema, das laengst abonniert war.
         *
         * Beide stehen hier, die neuere zuerst; genommen wird die erste
         * lesbare. Eine feste Entscheidung fuer eine von beiden waere die
         * naechste Wette auf eine LoxBerry-Fassung. */
        'abos'    => $home . '/config/system/mqttgateway.json',
        'abos_alt' => $home . '/config/system/subscriptions.json',
        'log'     => $home . '/log/plugins/' . $ordner . '/midea2lox.log',
        'datadir'    => $home . '/data/plugins/' . $ordner,
        // Der Merker, der sagt, ob der Dienst laufen SOLL, und die Marke
        // einer laufenden Aktualisierung. Die Marke liegt NEBEN dem
        // Datenordner - purge_installation loescht den Ordner samt Inhalt.
        'sollmarke'  => $home . '/data/plugins/' . $ordner . '/soll_laufen',
        'marke'      => $home . '/data/plugins/' . $ordner . '.upgrade_laeuft',
        'leben'   => $home . '/data/plugins/' . $ordner . '/lebenszeichen.json',
        // Ab 4.5.0: derselbe Zustand, den die Automatik veroeffentlicht, als
        // Datei - die Oberflaeche kann kein MQTT lesen.
        'automatik' => $home . '/data/plugins/' . $ordner . '/automatik.json',
        // c1 (Verbesserungsbau 30.09.2026): der Stand der Kopplung
        // "Fenster offen -> Geraet aus", geschrieben vom Dienst.
        'fenster'   => $home . '/data/plugins/' . $ordner . '/fenster.json',
        // Gruppe 15 (4.5.18, V1): der Stand der Anwesenheit, geschrieben vom Dienst.
        'anwesenheit' => $home . '/data/plugins/' . $ordner . '/anwesenheit.json',
        'bin'     => $home . '/bin/plugins/' . $ordner,
        'venv'    => $home . '/bin/plugins/' . $ordner . '/venv/bin/python3',
        'daemon'  => $home . '/system/daemons/plugins/' . $ordner,
        'general' => $home . '/config/system/general.json',
        'lang'    => $home . '/templates/plugins/' . $ordner . '/lang',
        // Der eigene Quelltext - die Selbstpruefung liest ihn, um Reiter,
        // Formularmerkmale und Themenliste gegen den Sendecode zu zaehlen.
        'index'   => __DIR__ . '/index.php',
        'dienst'  => $home . '/data/plugins/' . $ordner . '/midea2lox.py',
        // status/dienst sendet NICHT der Dauerlaeufer, sondern der
        // minuetliche Cron-Lauf ueber dieses Stueck - ein Dienst, der
        // seinen eigenen Tod melden soll, ist der falsche Zeuge. Die
        // Themenpruefung muss deshalb beide Dateien ansehen; sonst
        // meldet sie status/dienst dauerhaft als fehlend.
        'leben_py' => $home . '/data/plugins/' . $ordner . '/lebenszeichen.py',
        // Ab 4.5.9: die Retain-Liste des Dienstes (MIT_RETAIN) liegt hier.
        'mqtt_py'  => $home . '/data/plugins/' . $ordner . '/mi_mqtt.py',
        // Ab 4.5.10 (M1): die Praefixe, unter denen die Linie gesendet hat -
        // NEBEN dem Konfigordner, damit die Liste ein Update uebersteht.
        'praefixe' => $home . '/config/plugins/' . $ordner . '.mqtt_praefixe',
        // Ab 4.5.10 (O1): das Ergebnis eines POST fuer das folgende GET.
        'einmal'   => $home . '/data/plugins/' . $ordner . '/einmalmeldung.json',
        // Ab 4.5.10 (O9): die zuletzt im Reiter Test ermittelten Fassungen.
        'fassungen' => $home . '/data/plugins/' . $ordner . '/fassungen.json',
        'archiv'   => '',
    );
    return $p;
}

function mi_e($s)
{
    /* ENT_SUBSTITUTE ist kein Beiwerk.
     *
     * Ohne dieses Kennzeichen liefert htmlspecialchars() bei einer
     * ungueltigen UTF-8-Folge eine LEERE Zeichenkette - nicht das Zeichen,
     * an dem es scheitert, sondern den ganzen Wert. Gemessen unter 7.4 und
     * 8.4 mit einer von Hand in Latin-1 bearbeiteten devices.cfg: die
     * Bezeichnung verschwand spurlos aus der Geraeteauswahl, aus der
     * Anleitung und aus der erzeugten Loxone-Vorlage.
     *
     * Mit ENT_SUBSTITUTE steht dort das Ersatzzeichen, und der Anwender
     * sieht, DASS etwas nicht stimmt. */
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ==================================================================
 * Sprache
 * ================================================================== */

function mi_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/** Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL". */
function mi_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        /* Seit 4.5.9 aus mi_paths()['lang']: ohne Wurzel ist das der
         * Plugin-Ordner selbst. Bis 4.5.8 wurde hier home . '/templates/...'
         * gebaut, und ohne Wurzel fragte das /templates/plugins/... an der
         * Laufwerkswurzel (in WSL gemessen, Pruefung-Midea2Lox-4.5.9, P6). */
        $ordner = mi_paths()['lang'];
        /* Rueckfall auf den Plugin-Ordner. Ohne ihn findet das Plugin seine
         * Texte NUR am installierten Ort - wer es vor der Installation
         * pruefen will, sieht eine textlose Seite und haelt sie fuer kaputt.
         * Die uebrigen Plugins des Hauses haben diesen Rueckfall. */
        if (!is_dir($ordner)) {
            $ordner = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($ordner . '/language_' . mi_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        // Englisch ist die Rueckfallebene, nicht Deutsch.
        $rueck = @parse_ini_file($ordner . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        /* Hier stand bis 4.2.12 ein trim($w, '"') mit der Begruendung,
         * INI_SCANNER_RAW liefere die Anfuehrungszeichen mit. Nachgemessen
         * unter 7.4.33 und 8.4.24: das stimmt nicht -
         *     var_dump(parse_ini_file(..., INI_SCANNER_RAW)['COMMON']['LABEL_SETTINGS'])
         *     string(13) "Einstellungen"
         * Der Aufruf war also wirkungslos - und eine Falle: er haette ein
         * Anfuehrungszeichen am Rand eines Textes stillschweigend gefressen.
         * Ersatzlos entfallen; die Anfuehrungszeichen in der Datei bleiben
         * Pflicht, weil sonst Werte mit & ( ) ! ? die GANZE Datei zu Fall
         * bringen. */
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}

/** Wie mi_t(), aber HTML-maskiert - fuer Texte, die in Attribute gehen. */
function mi_te($schluessel)
{
    return mi_e(mi_t($schluessel));
}

/* ==================================================================
 * Formularmerkmal (Wachposten)
 *
 * Bis 4.2.12 trug KEINES der neun Formulare ein Merkmal, waehrend der
 * Kopfkommentar der index.php einen Wachposten behauptete. Gemessen:
 * neun Formulare, null Merkmalsfelder. Damit genuegte eine fremde Seite,
 * um bei einem angemeldeten Anwender den Dienst anzuhalten, die
 * Konfiguration zu ueberschreiben oder die Sicherungsdatei samt
 * Midea-Passwort abzuholen.
 *
 * EIN Posten am Eingang, nicht sieben einzelne Abfragen: eine Abfrage
 * vergisst man, einen Posten nicht.
 *
 * Der Merkmalsname ist "fmt" - dieselbe Schreibweise wie in Govee,
 * Heimkino, MG iSmart, Robonect, Saugroboter, Spotpreis und WiFi-Scanner.
 * ================================================================== */

/**
 * Das Merkwort, aus dem das Formularmerkmal entsteht.
 *
 * KEINE Sitzung. Der erste Anlauf benutzte session_start(); gemessen an einem
 * echten Webserver (php -S) kam dabei KEIN Set-Cookie zustande, das Merkmal
 * war bei jedem Aufruf ein anderes, und der Wachposten wies jeden POST ab -
 * auch den mit gueltigem Merkmal. Der Pruefstand meldete daraufhin sieben
 * Befunde am Pruefling, von denen keiner einer war.
 *
 * Der Hausweg ist ein hinterlegtes Geheimnis: Govee, Heimkino, MG iSmart und
 * WiFi-Scanner bilden ihr Merkmal alle als hash_hmac ueber einen Wert, der
 * ohnehin auf der Anlage liegt. Das ist unabhaengig von Kopfzeilen,
 * Ausgabepuffern und Sitzungen und verhaelt sich am CLI wie am Geraet.
 *
 * Das Merkwort liegt unter data/, nicht in midea2lox.cfg - damit kann es
 * gar nicht erst in die Sicherungsdatei geraten. Der AKTIONSTOKEN gehoert
 * hinein, das Formularmerkmal ausdruecklich nicht; wer beide verwechselt,
 * macht aus der Umzugshilfe ein Leck. Ein Merkwort, das nicht in der Liste
 * der Einstellungen steht, kann nicht versehentlich mitgesichert werden.
 */
function mi_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $datei = mi_paths()['datadir'] . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh)) {
            $wort = $roh;
            return $wort;
        }
    }
    if (function_exists('random_bytes')) {
        $neu = bin2hex(random_bytes(24));
    } else {
        $neu = hash('sha256', uniqid((string) mt_rand(), true) . microtime(true));
        $neu = substr($neu, 0, 48);
    }
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0775, true);
    }
    /* Rechte VOR dem Inhalt - seit 4.5.10 wirklich (C9). Bis 4.5.9 stand
     * dieser Satz hier, und darunter kam chmod erst NACH file_put_contents
     * (Bericht code Befund 9). Jetzt ueber mi_datei_schreiben(). */
    $abgelegt = mi_datei_schreiben($datei, $neu, 0600);
    /* Laesst sich das Merkwort NICHT ablegen, gibt es nichts, woran ein
     * spaeterer Aufruf es wiedererkennen koennte: jeder Seitenaufbau
     * erzeugte bis 4.3.2 ein anderes, jeder POST scheiterte am Vergleich,
     * und die Meldung riet zum Neuladen - was nie half. Die wirkliche
     * Ursache (das Datenverzeichnis ist nicht beschreibbar) stand nirgends.
     *
     * Ein leerer Rueckgabewert ist der ehrliche Zustand. Der Wachposten
     * nennt ihn beim Namen, und die Oberflaeche weist beim Aufbau darauf
     * hin - siehe mi_wachposten() und index.php. */
    $wort = $abgelegt ? $neu : '';
    return $wort;
}

/**
 * Das Formularmerkmal.
 *
 * Fail closed: laesst sich kein Merkwort hinterlegen, gibt es nichts zu
 * vergleichen - dann liefert diese Funktion einen Leerstring, und der
 * Wachposten weist ab. hash_equals('', '') waere sonst wahr, und der Schutz
 * fiele offen aus.
 */
function mi_formtoken()
{
    $grund = mi_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

/** Das versteckte Feld fuer jedes Formular. */
function mi_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
         . mi_e(mi_formtoken()) . '">';
}

/**
 * Der Wachposten.
 *
 * Rueckgabe: '' wenn die Anfrage durchgelassen wird, sonst der Grund.
 *
 * Fail closed: ein leeres Merkmal wird NICHT gegen ein leeres geprueft -
 * hash_equals('', '') ist true, und damit haette jede Anfrage ohne Feld
 * bestanden. Das ist die teuerste Einzelzeile dieser Klasse.
 *
 * Das Merkmal wird aus $_POST und $_GET gelesen, nie aus $_REQUEST:
 * $_REQUEST enthaelt je nach variables_order auch Cookies.
 */
function mi_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = mi_formtoken();
    // Die Lage "wir koennen gar kein Merkmal hinterlegen" bekommt ihren
    // eigenen Satz. Sie sieht fuer den Anwender genauso aus wie eine
    // abgelaufene Sitzung, ist aber etwas ganz anderes - und Neuladen hilft
    // nie.
    if ($soll === '') {
        return sprintf(mi_t('UI.WACHE_UNABLEGBAR'), mi_e(mi_paths()['datadir']));
    }
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '') {
        return mi_t('UI.WACHE_FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        return mi_t('UI.WACHE_FALSCH');
    }
    return '';
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function mi_vorgaben()
{
    return array(
        'ansage_block'          => '',     // die Sprachansagen, ein Feld (base64), eigene Pruefung
        'MINISERVER'            => 'MINISERVER1',
        'UDP_PORT'              => '7013',
        'DEBUG'                 => '0',
        'LoxberryIP'            => '',
        'maxConnectionLifetime' => '90',
        'region'                => 'DE',
        'MideaUser'             => '',
        'MideaPassword'         => '',
        // Ab 4.3.0. Neue Schluessel stehen HINTEN - eine bestehende Datei
        // bekommt sie beim ersten Vervollstaendigen dazu, ohne dass sich die
        // Bedeutung der vorhandenen aendert.
        'mqtt_praefix'          => 'Midea2Lox',
        'abfragetakt'           => '0',
        'lox_timeout'           => '5',
        /* Ab 4.5.0: die Automatik. Auch diese Schluessel stehen HINTEN.
         *
         * auto_ein steht auf '0'. Eine Funktion, die von sich aus in ein
         * Klimageraet greift, wird nicht durch ein Update eingeschaltet -
         * der Anwender schaltet sie ein, nachdem er die Themen eingetragen
         * und im Reiter Test gesehen hat, dass wirklich etwas ankommt.
         *
         * auto_verschiebung steht in ZEHNTELKELVIN, damit die Datei ohne
         * Kommazahl auskommt: 20 sind 2,0 K. Eine Kommazahl in einer .cfg
         * ist je nach Gebietsschema mal '2.0' und mal '2,0' - das ist die
         * naechste stille Ueberraschung. */
        'auto_ein'              => '0',
        'auto_thema_regel'      => '',
        'auto_thema_pv'         => '',
        'auto_pv_ab'            => '1500',
        'auto_verschiebung'     => '20',
        'auto_soll_min'         => '16',
        'auto_soll_max'         => '30',
        'auto_max_alter'        => '900',
        'auto_sperrzeit'        => '120',
        'auto_takt'             => '300',
        'auto_turbo'            => '0',
        'auto_schalten'         => '0',
        'auto_geraete'          => '',
        /* Verbesserungsbau 30.09.2026 (c1): Fenster offen -> Klimageraet aus.
         * AB WERK AUS - eine Kopplung an ein anderes Plugin wird nicht durch
         * ein Update eingeschaltet. Frist in Sekunden (0-600). Zuordnung
         * "<Geraetenummer>:<name>+<name>,<Geraetenummer>:<name>"; <name> wie
         * im Haus-Thema haus/tuer/<name>/offen (Regeln/07). */
        'fenster_ein'           => '0',
        'fenster_frist'         => '60',
        'fenster_zuordnung'     => '',
        /* Gruppe 15 (4.5.18): Befehle ueber MQTT (zwei Haken) und die Anwesenheit (V1) - AB WERK AUS, neue
         * Schluessel HINTEN. Jeder neue Schluessel steht auch in config/midea2lox.cfg, und deren SHA256 in
         * MI_VORGABE_MIDEA aller drei Hakenskripte (M23). Verschiebung in Zehntelkelvin (30 = 3,0 K). */
        'befehle_mqtt_ein'        => '0',
        'befehle_eingreifend_ein' => '0',
        'anwesenheit_ein'         => '0',
        'anwesenheit_verhalten'   => 'verschieben',
        'anwesenheit_verschiebung' => '30',
        'anwesenheit_frist'       => '300',
        'anwesenheit_geraete'     => '',
    );
}

/**
 * fenster_zuordnung lesen (c1): array(Geraetenummer => array(Namen)) oder
 * null bei einem Formfehler. Dieselbe Regel wie fenster_zuordnung_lesen() im
 * Dienst: Nummer 10-19 Ziffern und jede nur einmal; Namen wie im Haus-Thema
 * (a-z, 0-9, _, 1-40 Zeichen), je Geraet ohne Doppel.
 */
function mi_fenster_zuordnung($roh)
{
    $aus = array();
    $t = trim((string) $roh);
    if ($t === '') {
        return $aus;
    }
    foreach (explode(',', $t) as $teil) {
        $p = explode(':', trim($teil), 2);
        if (count($p) !== 2) {
            return null;
        }
        $gid = trim($p[0]);
        if (!preg_match('/^[0-9]{10,19}\z/', $gid) || isset($aus[$gid])) {
            return null;
        }
        $namen = array_map('trim', explode('+', $p[1]));
        foreach ($namen as $n) {
            if (!preg_match('/^[a-z0-9_]{1,40}\z/', $n)) {
                return null;
            }
        }
        if (count(array_unique($namen)) !== count($namen)) {
            return null;
        }
        $aus[$gid] = $namen;
    }
    return $aus;
}

/**
 * Der Stand der Kopplung "Fenster offen -> Geraet aus" (c1), wie der Dienst
 * ihn in data/fenster.json ablegt: array(lage, daten|null, alter|null).
 * lage: aus, fehlt, unlesbar, alt (aelter als 180 s - der Dienst schreibt
 * bei jeder Aenderung und sonst einmal je Minute), ok.
 */
function mi_fenster_lage($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    if (mi_cfg($cfg, 'fenster_ein', '0') !== '1') {
        return array('aus', null, null);
    }
    $datei = mi_paths()['fenster'];
    clearstatcache(true, $datei);
    if (!is_readable($datei)) {
        return array('fehlt', null, null);
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['ts']) || !isset($d['fenster']) || !is_array($d['fenster'])) {
        return array('unlesbar', null, null);
    }
    $alter = time() - (int) $d['ts'];
    return array($alter > 180 ? 'alt' : 'ok', $d, $alter);
}

/**
 * Taugt der Wert ueberhaupt fuer eine Zeile dieser Datei?
 *
 * midea2lox.cfg ist zeilenorientiert: ein Zeilenumbruch im Wert erzeugt eine
 * zusaetzliche Zeile, eine eckige Klammer einen zusaetzlichen Abschnitt.
 * mi_config_write() entfernt zwar \r und \n - gemessen, das traegt -, aber
 * ein Feld statt einer Zeichenkette wurde bis 4.2.12 als "Array" in die
 * Datei geschrieben, und der Dienst starb daran beim naechsten Start.
 */
function mi_wert_taugt($v)
{
    if (is_array($v) || is_object($v) || is_bool($v) || is_null($v)) {
        return false;
    }
    $s = (string) $v;
    if (strlen($s) > 4096) {
        return false;
    }
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $s) !== 1;
}

/**
 * Traegt der Wert am Rand Leerraum - so, wie Pythons str.strip() ihn sieht?
 * (Verbesserungsbau 30.09.2026, b1)
 *
 * PHP trim() kennt nur " \t\n\r\0\x0B". configparser (discover.py) schneidet
 * mit str.strip() auch \f, U+001C-U+001F, U+0085, U+00A0, U+1680,
 * U+2000-U+200A, U+2028, U+2029, U+202F, U+205F und U+3000 ab. Mit trim()
 * allein ging ein Kennwort, das mit einem geschuetzten Leerzeichen endet,
 * durch und kam gekuerzt bei der Geraetesuche an.
 */
function mi_randleer($v)
{
    $s = (string) $v;
    if ($s === '') {
        return false;
    }
    if ($s !== trim($s) || strpbrk($s[0] . substr($s, -1), "\f") !== false) {
        return true;
    }
    $zeichen = '[\x{1C}-\x{1F}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}'
             . '\x{202F}\x{205F}\x{3000}]';
    $r = @preg_match('/^' . $zeichen . '|' . $zeichen . '$/u', $s);
    return $r === 1;
}

/**
 * Das Midea-Kennwort fuer midea2lox.cfg (b1). configparser schneidet Rand-
 * Leerraum immer ab - ein solches Kennwort wird deshalb in EIN Paar
 * Anfuehrungszeichen gesetzt. Jedes andere bleibt woertlich, ein bestehendes
 * also byte-gleich. Gegenstueck: mi_kennwort_lesen() und kennwort_lesen() in
 * data/discover.py.
 */
function mi_kennwort_kodieren($v)
{
    $s = (string) $v;
    return mi_randleer($s) ? '"' . $s . '"' : $s;
}

/**
 * Das gespeicherte Kennwort lesen (b1): in Anfuehrungszeichen UND innen am
 * Rand Leerraum -> das Innere; sonst woertlich. Ein Altbestand "geheim" mit
 * Anfuehrungszeichen als Teil des Kennworts bleibt, was er war.
 */
function mi_kennwort_lesen($s)
{
    $s = (string) $s;
    if (strlen($s) >= 3 && $s[0] === '"' && substr($s, -1) === '"'
        && mi_randleer(substr($s, 1, -1))) {
        return substr($s, 1, -1);
    }
    return $s;
}

/**
 * Ist der Wert fuer DIESE Einstellung zulaessig?
 *
 * Rueckgabe: '' wenn ja, sonst der fertige Beanstandungstext.
 *
 * Das ist die EINE Positivliste. Sie wird vom Formular, von der
 * zurueckgespielten Sicherung und von der Selbstpruefung benutzt - eine
 * zweite Wahrheit ueber zulaessige Werte gibt es nicht. Bis 4.2.12 standen
 * die Grenzen als Zahlen im Speicher-Handler, und der Rueckspielweg kannte
 * sie nicht: eine Sicherung mit UDP_PORT 99999999 wurde anstandslos
 * uebernommen.
 */
function mi_wert_pruefen($schluessel, $wert)
{
    if (!mi_wert_taugt($wert)) {
        return sprintf(mi_t('UI.PRUEF_UNTAUGLICH'), mi_e($schluessel));
    }
    $w = trim((string) $wert);
    switch ($schluessel) {
        case 'MINISERVER':
            if (!preg_match('/^MINISERVER\d{1,2}$/', $w)) {
                return mi_t('UI.PRUEF_MINISERVER');
            }
            /* Gegen die WIRKLICH vorhandenen Miniserver, wenn sie lesbar
             * sind. MINISERVER99 hat die richtige Gestalt, aber den
             * Abschnitt gibt es in der general.cfg nicht - der Dienst
             * beendet sich dann beim Start, und der Anwender sucht den
             * Grund in der Oberflaeche, die ihn hat durchgehen lassen.
             * Ist die Liste nicht lesbar, bleibt es bei der Gestalt: eine
             * leere Liste darf nicht ALLES abweisen. */
            $liste = mi_miniserver_liste();
            if ($liste && !isset($liste[(int) substr($w, 10)])) {
                return sprintf(mi_t('UI.PRUEF_MINISERVER_FEHLT'), mi_e($w));
            }
            return '';
        case 'UDP_PORT':
            /* Ohne fuehrende Nullen. "007013" ging bis 4.3.2 durch, wurde so
             * gespeichert und stand danach genau so in der Adresse
             * /dev/udp/<ip>/007013 der Loxone-Anleitung. Abweisen ist
             * richtig - zurechtbiegen waere das, was diese Liste
             * verhindern soll. */
            return (preg_match('/^[1-9][0-9]{0,4}$/', $w) && (int) $w <= 65535) ? ''
                 : mi_t('UI.PRUEF_UDP_PORT');
        case 'maxConnectionLifetime':
            return (ctype_digit($w) && (int) $w >= 10 && (int) $w <= 3600) ? ''
                 : mi_t('UI.PRUEF_LEBENSDAUER');
        case 'lox_timeout':
            return (ctype_digit($w) && (int) $w >= 1 && (int) $w <= 60) ? ''
                 : mi_t('UI.PRUEF_TIMEOUT');
        case 'abfragetakt':
            // 0 heisst aus. Untergrenze 30 s, damit niemand versehentlich
            // jede Sekunde an das Klimageraet klopft.
            if (!ctype_digit($w)) { return mi_t('UI.PRUEF_TAKT'); }
            $n = (int) $w;
            return ($n === 0 || ($n >= 30 && $n <= 86400)) ? ''
                 : mi_t('UI.PRUEF_TAKT');
        case 'DEBUG':
            return ($w === '0' || $w === '1') ? '' : mi_t('UI.PRUEF_DEBUG');
        case 'region':
            return array_key_exists($w, mi_regionen()) ? ''
                 : mi_t('UI.PRUEF_REGION');
        case 'mqtt_praefix':
            /* Ein MQTT-Thema. Kein #, kein +, kein fuehrender oder
             * abschliessender Schraegstrich - sonst abonniert der Anwender
             * ungewollt den halben Broker. */
            return preg_match('/^[A-Za-z0-9_.\-]{1,48}(\/[A-Za-z0-9_.\-]{1,48}){0,3}$/', $w)
                 ? '' : mi_t('UI.PRUEF_PRAEFIX');
        case 'LoxberryIP':
            return ($w === '' || filter_var($w, FILTER_VALIDATE_IP) !== false) ? ''
                 : mi_t('UI.PRUEF_IP');
        /* Die Automatik. Jeder Schluessel bekommt seine eigene Zeile - eine
         * Sammelregel "alles was mit auto_ anfaengt ist eine Zahl" waere
         * genau die Nachlaessigkeit, die diese Liste vermeiden soll. */
        case 'auto_ein':
        case 'auto_turbo':
        case 'auto_schalten':
            return ($w === '0' || $w === '1') ? '' : mi_t('UI.PRUEF_JANEIN');
        case 'auto_thema_regel':
        case 'auto_thema_pv':
            /* Ein Thema, das ABONNIERT wird - anders als das eigene Praefix
             * darf es tiefer verschachtelt sein, aber weiterhin keinen
             * Platzhalter tragen: wer "#" abonniert, bekommt den halben
             * Broker in die Regel. Leer heisst "diese Quelle nicht nutzen". */
            if ($w === '') { return ''; }
            return preg_match('/^[A-Za-z0-9_.\-]{1,48}(\/[A-Za-z0-9_.\-]{1,48}){0,7}$/', $w)
                 ? '' : mi_t('UI.PRUEF_ABOTHEMA');
        case 'auto_pv_ab':
            return (ctype_digit($w) && (int) $w >= 0 && (int) $w <= 100000) ? ''
                 : mi_t('UI.PRUEF_PV_AB');
        case 'auto_verschiebung':
            // In Zehntelkelvin: 0 bis 100 sind 0,0 bis 10,0 K.
            return (ctype_digit($w) && (int) $w >= 0 && (int) $w <= 100) ? ''
                 : mi_t('UI.PRUEF_VERSCHIEBUNG');
        case 'auto_soll_min':
        case 'auto_soll_max':
            return (ctype_digit($w) && (int) $w >= 5 && (int) $w <= 35) ? ''
                 : mi_t('UI.PRUEF_SOLLGRENZE');
        case 'auto_max_alter':
            return (ctype_digit($w) && (int) $w >= 60 && (int) $w <= 86400) ? ''
                 : mi_t('UI.PRUEF_MAX_ALTER');
        case 'auto_sperrzeit':
            return (ctype_digit($w) && (int) $w >= 0 && (int) $w <= 1440) ? ''
                 : mi_t('UI.PRUEF_SPERRZEIT');
        case 'auto_takt':
            return (ctype_digit($w) && (int) $w >= 60 && (int) $w <= 3600) ? ''
                 : mi_t('UI.PRUEF_AUTO_TAKT');
        case 'auto_geraete':
            // Leer heisst ALLE. Sonst Geraetenummern durch Komma getrennt -
            // dieselbe Grenze wie im Dienst.
            if ($w === '') { return ''; }
            foreach (explode(',', $w) as $teil) {
                if (!preg_match('/^\d{10,19}$/', trim($teil))) {
                    return sprintf(mi_t('UI.PRUEF_AUTO_GERAETE'), mi_e(trim($teil)));
                }
            }
            return '';
        /* c1: Fenster offen -> Klimageraet aus. */
        case 'fenster_ein':
            return ($w === '0' || $w === '1') ? '' : mi_t('UI.PRUEF_JANEIN');
        case 'fenster_frist':
            return (ctype_digit($w) && (int) $w <= 600) ? ''
                 : mi_t('UI.PRUEF_FENSTER_FRIST');
        case 'fenster_zuordnung':
            return mi_fenster_zuordnung($w) !== null ? ''
                 : mi_t('UI.PRUEF_FENSTER_ZUORDNUNG');
        /* Gruppe 15 (4.5.18): Haken der MQTT-Befehle und die Anwesenheit (V1). */
        case 'befehle_mqtt_ein':
        case 'befehle_eingreifend_ein':
        case 'anwesenheit_ein':
            return ($w === '0' || $w === '1') ? '' : mi_t('UI.PRUEF_JANEIN');
        case 'anwesenheit_verhalten':
            return in_array($w, array('verschieben', 'spar', 'aus'), true) ? ''
                 : mi_t('UI.PRUEF_ANW_VERHALTEN');
        case 'anwesenheit_verschiebung':
            return (ctype_digit($w) && (int) $w <= 100) ? '' : mi_t('UI.PRUEF_VERSCHIEBUNG');
        case 'anwesenheit_frist':
            return (ctype_digit($w) && (int) $w <= 3600) ? '' : mi_t('UI.PRUEF_ANW_FRIST');
        case 'anwesenheit_geraete':
            if ($w === '') { return ''; }
            foreach (explode(',', $w) as $teil) {
                if (!preg_match('/^\d{10,19}$/', trim($teil))) {
                    return sprintf(mi_t('UI.PRUEF_AUTO_GERAETE'), mi_e(trim($teil)));
                }
            }
            return '';
        case 'MideaUser':
            // Freitext; Leerraum am RAND kann die Datei nicht halten
            // (configparser schneidet ihn ab). Abweisen statt still kuerzen
            // (Regeln/05) - seit b1 mit dem Leerraumbegriff von Python.
            if (mi_randleer($wert)) {
                return sprintf(mi_t('UI.PRUEF_RANDLEER'), mi_e($schluessel));
            }
            return '';
        case 'MideaPassword':
            // Freitext. Ein Kennwort darf jedes druckbare Zeichen enthalten -
            // seit 4.5.10 (C3) auch ';' und Anfuehrungszeichen, die
            // mi_ini_lesen() woertlich liest wie der Dienst.
            // Seit b1 (Verbesserungsbau 30.09.2026) auch Leerzeichen am Rand:
            // mi_config_write() setzt ein solches Kennwort in
            // Anfuehrungszeichen, mi_kennwort_lesen() und discover.py nehmen
            // sie wieder weg. Abgewiesen wird nur die eine Form, die dieses
            // Format nicht halten kann: ohne Rand-Leerraum, aber selbst wie
            // ein kodierter Wert gebaut (Anfuehrungszeichen, dann Leerraum).
            if (!mi_randleer($wert) && mi_kennwort_lesen($wert) !== (string) $wert) {
                return sprintf(mi_t('UI.PRUEF_KENNWORT_ZITAT'), mi_e($schluessel));
            }
            return '';
    }
    return sprintf(mi_t('UI.PRUEF_UNBEKANNT'), mi_e($schluessel));
}

/**
 * Welche Schluessel fehlen in der DATEI?
 *
 * Gefragt wird die Datei, nicht das Ergebnis von mi_config_read() - das ist
 * durch mi_vorgaben() immer vollstaendig, und ein array_key_exists darauf
 * kann gar nichts finden. Beim ersten Anlauf stand hier genau dieser Fehler:
 * die Vervollstaendigung lief bei jedem Seitenaufruf, meldete nie etwas und
 * schrieb nie etwas. Aufgefallen ist es am Aktualisierungsfall im Pruefstand,
 * in dem die drei neuen Schluessel nach dem Rendern immer noch fehlten.
 *
 * Rueckgabe: Liste der Schluessel, die in der Datei nicht stehen. Eine leere
 * Liste heisst auch dann "nichts zu tun", wenn es die Datei gar nicht gibt -
 * dann legt der erste Speichervorgang sie ohnehin vollstaendig an.
 */
function mi_cfg_fehlende()
{
    $datei = mi_paths()['config'];
    if (!is_readable($datei)) {
        return array();
    }
    $roh = mi_ini_lesen($datei);
    if (!is_array($roh) || !isset($roh['default']) || !is_array($roh['default'])) {
        return array();
    }
    $fehlt = array();
    foreach (array_keys(mi_vorgaben()) as $k) {
        if (!isset($roh['default'][$k])) {
            $fehlt[] = $k;
        }
    }
    return $fehlt;
}

/**
 * midea2lox.cfg lesen wie Pythons configparser.RawConfigParser (ab 4.5.10, C3).
 *
 * Bis 4.5.9 las die Oberflaeche die Datei mit parse_ini_file(...,
 * INI_SCANNER_RAW). Das schneidet an ';' ab und nimmt umschliessende
 * Anfuehrungszeichen weg - der Dienst und discover.py (RawConfigParser) lesen
 * beides woertlich. Ein Midea-Kennwort "abc;def" wurde damit beim naechsten
 * Speichern IRGENDEINES Reiters still zu "abc" (gemessen unter 7.4, 8.4 und
 * 8.5, Bericht code Befund 3, Bericht Oberflaeche Befund 5).
 *
 * Jetzt dieselben Regeln wie configparser: Kommentar nur als GANZE Zeile
 * ('#' oder ';' am Anfang), Schluessel bis zum ersten '=' oder ':', Wert
 * ohne Rand-Leerzeichen, sonst woertlich. Was configparser abweisen wuerde -
 * eine Zeile ohne Trenner, ein Wert vor dem ersten Abschnitt, eine
 * eingerueckte Fortsetzungszeile, ein doppelter Schluessel oder Abschnitt -,
 * ergibt null: der Dienst startet mit einer solchen Datei nicht, und die
 * Oberflaeche sagt dann "kaputt" statt eines halben Standes.
 *
 * Rueckgabe: array(abschnitt => array(schluessel => wert)) oder null.
 */
function mi_ini_lesen($datei)
{
    $roh = @file_get_contents($datei);
    if ($roh === false) {
        return null;
    }
    if (strncmp($roh, "\xEF\xBB\xBF", 3) === 0) {
        $roh = substr($roh, 3);
    }
    $aus = array();
    $klein = array();
    $ab = null;
    foreach (preg_split('/\r\n|\n|\r/', $roh) as $zeile) {
        $t = trim($zeile);
        if ($t === '' || $t[0] === '#' || $t[0] === ';') {
            continue;
        }
        if ($zeile[0] === ' ' || $zeile[0] === "\t") {
            return null;
        }
        if (preg_match('/^\[([^\]]+)\]$/', $t, $m)) {
            if (isset($aus[$m[1]])) {
                return null;
            }
            $ab = $m[1];
            $aus[$ab] = array();
            $klein[$ab] = array();
            continue;
        }
        if ($ab === null) {
            return null;
        }
        $pos = false;
        foreach (array('=', ':') as $trenner) {
            $p = strpos($t, $trenner);
            if ($p !== false && ($pos === false || $p < $pos)) {
                $pos = $p;
            }
        }
        if ($pos === false) {
            return null;
        }
        $k = rtrim(substr($t, 0, $pos));
        if ($k === '' || isset($klein[$ab][strtolower($k)])) {
            return null;
        }
        $klein[$ab][strtolower($k)] = true;
        $aus[$ab][$k] = trim((string) substr($t, $pos + 1));
    }
    return $aus;
}

/** midea2lox.cfg lesen. Aufbau: INI mit dem Abschnitt [default]. */
function mi_config_read()
{
    $cfg = mi_vorgaben();
    $datei = mi_paths()['config'];
    if (is_readable($datei)) {
        $roh = mi_ini_lesen($datei);
        if (is_array($roh) && isset($roh['default']) && is_array($roh['default'])) {
            foreach ($cfg as $k => $v) {
                if (isset($roh['default'][$k])) {
                    $cfg[$k] = (string) $roh['default'][$k];
                }
            }
        }
    }
    // b1: das Quotierformat des Kennworts aufloesen (mi_kennwort_kodieren).
    $cfg['MideaPassword'] = mi_kennwort_lesen($cfg['MideaPassword']);
    return $cfg;
}

/**
 * Die Lage der Konfigurationsdatei - jeder Zustand, den der Code erzeugen
 * kann, bekommt seinen Satz.
 *
 * Rueckgabe: array(kuerzel, Text). Kuerzel: ok, fehlt, leer, kaputt,
 * unvollstaendig.
 */
function mi_cfg_lage()
{
    $datei = mi_paths()['config'];
    /* clearstatcache VOR dem Tor, mit dem zweiten Parameter auf genau diese
     * Datei.
     *
     * PHP haelt die Antworten von stat() in einem Zwischenspeicher, und
     * unter 7.4 - der Fassung, die LoxBerry 3.x bis 4.0 faehrt - sieht
     * filesize() danach den ALTEN Wert. Gemessen: eine 0-Byte-Datei, im
     * selben Prozess beschrieben, meldete unter 7.4 weiter 0 Byte, unter
     * 8.4 die neue Groesse.
     *
     * Heute wirkt das hier NICHT: vor dieser Funktion fasst nur
     * is_readable() die Datei an, und das fuellt den Zwischenspeicher
     * nicht - in beide Richtungen gemessen. Die Zeile steht trotzdem, denn
     * sie haengt allein an dieser Reihenfolge: wer irgendwo davor ein
     * is_file() oder filesize() auf dieselbe Datei setzt, bekaeme hier
     * stillschweigend "die Datei ist leer" fuer eine Datei, die gerade
     * geschrieben wurde. */
    clearstatcache(true, $datei);
    if (!is_file($datei)) {
        return array('fehlt', mi_t('UI.LAGE_FEHLT'));
    }
    if (filesize($datei) === 0) {
        return array('leer', mi_t('UI.LAGE_LEER'));
    }
    $roh = mi_ini_lesen($datei);
    if (!is_array($roh) || !isset($roh['default']) || !is_array($roh['default'])) {
        return array('kaputt', mi_t('UI.LAGE_KAPUTT'));
    }
    $fehlt = array();
    foreach (array_keys(mi_vorgaben()) as $k) {
        if (!isset($roh['default'][$k])) { $fehlt[] = $k; }
    }
    if ($fehlt) {
        return array('unvollstaendig',
            sprintf(mi_t('UI.LAGE_UNVOLLSTAENDIG'), mi_e(implode(', ', $fehlt))));
    }
    return array('ok', mi_t('UI.LAGE_OK'));
}

/**
 * Eine Datei unteilbar schreiben - Rechte VOR dem Inhalt (ab 4.5.10, C9).
 *
 * Bis 4.5.9 schrieben mi_config_write(), mi_merkwort(), die beiden
 * Geraetedateiwege und die Abo-Datei jeweils eigene Nebendateien "<ziel>.tmp"
 * mit der Umask des Webservers; chmod kam erst NACH dem Inhalt oder gar erst
 * nach dem Umbenennen. Unter "ulimit -f 1" (volle Karte) blieb
 * midea2lox.cfg.tmp mit 1024 Byte und Rechten 644 liegen - darin das
 * Midea-Kennwort (in WSL gemessen, Bericht code Befund 9; Regeln/03
 * "atomar schreiben").
 *
 * Jetzt: Nebendatei <ziel>.neu.<pid> exklusiv anlegen, Rechte setzen, DANN
 * schreiben, die geschriebene Laenge mit der Soll-Laenge vergleichen, erst
 * danach umbenennen. Bei jedem Fehlschlag wird die Nebendatei entfernt.
 */
function mi_datei_schreiben($ziel, $inhalt, $rechte = 0600)
{
    $inhalt = (string) $inhalt;
    if (!is_dir(dirname($ziel))) {
        @mkdir(dirname($ziel), 0775, true);
    }
    $tmp = $ziel . '.neu.' . getmypid();
    if (file_exists($tmp)) {
        @unlink($tmp);   // Rest eines abgebrochenen Laufs mit derselben PID
    }
    $fh = @fopen($tmp, 'x');
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, $rechte);
    $laenge = strlen($inhalt);
    $geschrieben = 0;
    while ($geschrieben < $laenge) {
        $n = @fwrite($fh, substr($inhalt, $geschrieben));
        if ($n === false || $n === 0) {
            break;
        }
        $geschrieben += $n;
    }
    $ok = ($geschrieben === $laenge) && @fflush($fh);
    @fclose($fh);
    if (!$ok || !@rename($tmp, $ziel)) {
        @unlink($tmp);
        return false;
    }
    @chmod($ziel, $rechte);
    return true;
}

/**
 * midea2lox.cfg schreiben.
 *
 * Der Python-Dienst liest dieselbe Datei mit configparser. Werte werden
 * deshalb ohne Anfuehrungszeichen geschrieben - genau so, wie die frueher
 * zustaendige Perl-Fassung (Config::Simple) es getan hat.
 *
 * Fail closed: faellt EIN Wert durch mi_wert_taugt(), wird gar nichts
 * geschrieben. "Den einen weglassen" hinterliesse eine Konfiguration, die es
 * nie gab.
 */
function mi_config_write($cfg)
{
    $voll = array_merge(mi_vorgaben(), $cfg);
    foreach ($voll as $k => $v) {
        if (!mi_wert_taugt($v)) {
            return false;
        }
    }
    // b1: ein Kennwort mit Rand-Leerraum in Anfuehrungszeichen; jedes andere
    // bleibt woertlich (byte-gleich wie bis 4.5.11).
    $voll['MideaPassword'] = mi_kennwort_kodieren($voll['MideaPassword']);
    $z = "[default]\n";
    foreach ($voll as $k => $v) {
        // Zeilenumbrueche wuerden die Datei zerlegen. mi_wert_taugt() weist
        // sie oben schon ab; diese Zeile bleibt als zweite Wache stehen.
        $v = str_replace(array("\r", "\n"), '', (string) $v);
        $z .= $k . '=' . $v . "\n";
    }
    // Seit 4.5.10 ueber mi_datei_schreiben() (C9): Rechte vor dem Inhalt,
    // Laenge verglichen, keine Nebendatei mit dem Kennwort bleibt liegen.
    return mi_datei_schreiben(mi_paths()['config'], $z, 0600);   // enthaelt das Midea-Passwort
}

/** Einen Wert lesen, mit Vorgabe. */
function mi_cfg($cfg, $schluessel, $vorgabe = '')
{
    return (isset($cfg[$schluessel]) && $cfg[$schluessel] !== '')
        ? $cfg[$schluessel] : $vorgabe;
}

/* ==================================================================
 * Geraete
 * ================================================================== */

/**
 * Geraeteliste aus devices.cfg.
 *
 * Aufbau: INI mit einem Abschnitt je Geraet, so wie discover.py sie
 * schreibt und midea2lox.py sie liest:
 *
 *     [Midea_123456789]
 *     type = AC
 *     id = 123456789
 *     ip = 192.168.1.50
 *     port = 6444
 *     bezeichnung = Wohnzimmer
 *     device min temperature = 16
 *
 * Nicht mit parse_ini_file lesen: die Schluessel enthalten Leerzeichen
 * ("device min temperature"), damit kommt PHPs INI-Leser nicht zurecht.
 *
 * Rueckgabe je Geraet: id, ip, port, typ, bezeichnung, token, key, name.
 * "name" ist bereits HTML-maskiert und wird deshalb roh ausgegeben.
 */
function mi_devices()
{
    $datei = mi_paths()['devices'];
    if (!is_readable($datei)) {
        return array();
    }
    $zeilen = @file($datei, FILE_IGNORE_NEW_LINES);
    if (!is_array($zeilen)) {
        return array();
    }
    $geraete = array();
    $akt = null;
    foreach ($zeilen as $z) {
        $z = trim($z);
        if ($z === '' || $z[0] === '#' || $z[0] === ';') {
            continue;
        }
        if ($z[0] === '[' && substr($z, -1) === ']') {
            if ($akt !== null) { $geraete[] = $akt; }
            $abschnitt = substr($z, 1, -1);
            $akt = array(
                // Der Abschnitt heisst Midea_<ID> - die ID steht ausserdem
                // als eigener Schluessel, aber nicht bei jedem Geraet.
                'id'   => (strpos($abschnitt, 'Midea_') === 0)
                          ? substr($abschnitt, 6) : $abschnitt,
                'ip'   => '',
                'port' => '',
                'typ'  => '',
                'bezeichnung' => '',
                'token' => '',
                'key'   => '',
            );
            continue;
        }
        if ($akt === null || strpos($z, '=') === false) {
            continue;
        }
        list($k, $v) = array_map('trim', explode('=', $z, 2));
        $k = strtolower($k);
        if ($k === 'ip')                  { $akt['ip']   = $v; }
        elseif ($k === 'port')            { $akt['port'] = $v; }
        elseif ($k === 'type')            { $akt['typ']  = $v; }
        elseif ($k === 'id' && $v !== '') { $akt['id']   = $v; }
        elseif ($k === 'bezeichnung')     { $akt['bezeichnung'] = $v; }
        elseif ($k === 'token')           { $akt['token'] = $v; }
        elseif ($k === 'key')             { $akt['key']   = $v; }
    }
    if ($akt !== null) { $geraete[] = $akt; }

    /* Ein Abschnitt ohne ID ist unbrauchbar: die ID ist die Adresse, mit der
     * Loxone das Geraet anspricht, und sie steht in jedem Thema und jedem
     * Bausteintitel. Bis 4.2.12 lieferte mi_beispiel_id() bei einem
     * Abschnitt "[Midea_]" einen Leerstring - gerendert wurden dann
     * Midea2Lox//power_state und Befehle wie ",True". mi_vorlage() hat den
     * Fall schon immer abgefangen, die Oberflaeche nicht. */
    $geraete = array_values(array_filter($geraete, function ($g) {
        return isset($g['id']) && trim((string) $g['id']) !== '';
    }));

    foreach ($geraete as $i => $g) {
        $geraete[$i]['name'] = mi_e(mi_geraetename($g));
    }
    return $geraete;
}

/**
 * Der anzuzeigende Name eines Geraets - ROH, ohne Maskierung.
 *
 * Eine eigene Bezeichnung schlaegt Typ und Nummer. Der Grund ist nicht
 * Bequemlichkeit: der Kommentar einer Loxone-Vorlage wird beim Import zum
 * ANZEIGENAMEN der Kachel, und "AC 123456789012" ist dort keine Hilfe.
 */
function mi_geraetename(array $g)
{
    $b = isset($g['bezeichnung']) ? trim((string) $g['bezeichnung']) : '';
    if ($b !== '') {
        return $b;
    }
    $typ = (isset($g['typ']) && $g['typ'] !== '') ? $g['typ'] : mi_t('UI.KLIMAGERAET');
    return $typ . ' ' . (isset($g['id']) ? $g['id'] : '');
}

/**
 * Die Bezeichnung eines Geraets in devices.cfg schreiben.
 *
 * Zeilenweise, damit die Schluessel mit Leerzeichen erhalten bleiben, die
 * discover.py schreibt ("device min temperature"). Unteilbar ueber eine
 * Nebendatei; Rechte VOR dem Inhalt, weil in der Datei Token und Schluessel
 * der Klimageraete stehen.
 */
function mi_devices_bezeichnung_schreiben(array $zuordnung)
{
    $datei = mi_paths()['devices'];
    if (!is_readable($datei)) {
        return false;
    }
    $roh = @file_get_contents($datei);
    if ($roh === false) {
        return false;
    }
    $zeilen = preg_split('/\R/', $roh);
    $aus = array();
    $id = null;
    $gesetzt = array();
    foreach ($zeilen as $z) {
        $t = trim($z);
        if ($t !== '' && $t[0] === '[' && substr($t, -1) === ']') {
            // Vor dem naechsten Abschnitt die Bezeichnung des vorigen
            // nachtragen, falls sie dort noch nicht stand.
            if ($id !== null && isset($zuordnung[$id]) && empty($gesetzt[$id])) {
                $aus[] = 'bezeichnung = ' . $zuordnung[$id];
                $gesetzt[$id] = true;
            }
            $ab = substr($t, 1, -1);
            $id = (strpos($ab, 'Midea_') === 0) ? substr($ab, 6) : $ab;
            $aus[] = $z;
            continue;
        }
        if ($id !== null && strpos($t, '=') !== false) {
            list($k) = array_map('trim', explode('=', $t, 2));
            if (strtolower($k) === 'bezeichnung') {
                if (isset($zuordnung[$id])) {
                    $aus[] = 'bezeichnung = ' . $zuordnung[$id];
                    $gesetzt[$id] = true;
                    continue;
                }
            }
        }
        $aus[] = $z;
    }
    if ($id !== null && isset($zuordnung[$id]) && empty($gesetzt[$id])) {
        $aus[] = 'bezeichnung = ' . $zuordnung[$id];
    }
    $inhalt = rtrim(implode("\n", $aus), "\n") . "\n";
    // Seit 4.5.10 ueber mi_datei_schreiben() (C9).
    return mi_datei_schreiben($datei, $inhalt, 0600);
}

/** Die ID fuer die Beispiele in den Befehlstabellen. */
function mi_beispiel_id()
{
    $d = mi_devices();
    // Zwoelf Ziffern. Die bisherige Beispielnummer hatte neun und lag damit
    // UNTER der Grenze des Dienstes (10 bis 19) - wer das Muster aus dem
    // Reiter Loxone abschrieb, bekam eine Nummer, die der Dienst nie als
    // Geraetenummer erkannt haette.
    return $d ? $d[0]['id'] : '123456789012';
}

/* ==================================================================
 * Dienst
 * ================================================================== */

/**
 * Laeuft der Dienst? Liefert die PID oder null.
 *
 * Gelesen wird die PID-Datei, die daemon/daemon anlegt.
 *
 * Bis 4.0.0 stand hier "ps -C midea2lox.py". Das sucht nach dem
 * PROZESSNAMEN - und der ist bei einem Skript mit Shebang-Zeile der
 * Interpreter (python3), nicht der Dateiname. Nachgemessen liefert der
 * Aufruf nie einen Treffer: die Oberflaeche zeigte den Dienst also
 * dauerhaft als gestoppt an, auch waehrend er lief.
 *
 * Bis 4.5.5 stand hier die Gegenprobe "einer der ersten drei Eintraege von
 * /proc/<pid>/cmdline heisst midea2lox.py". Das ist argumentweise, aber
 * nicht pfadgenau. Gemessen 17.09.2026 in WSL mit der Nummer eines
 * FREMDEN Prozesses in der PID-Datei: "tail -f ./midea2lox.py" im
 * Datenordner, "python3 -c '...' ./midea2lox.py", ein gleichnamiges
 * Programm im Nachbarordner - alle drei wurden als laufender Dienst
 * gemeldet. Seit 4.5.6 gilt dieselbe Bedingung wie in daemon/daemon
 * (eigener_dienst): argv[0] ist ein python-Interpreter, argv[1] ist,
 * gegen den Arbeitsordner des Prozesses aufgeloest, genau
 * data/plugins/<ordner>/midea2lox.py.
 *
 * Der Benutzer des Prozesses wird NICHT geprueft. Die Nummer stammt aus
 * der eigenen PID-Datei im eigenen Datenordner; eine Benutzerpruefung
 * waere hier keine Erkennung, sondern eine zusaetzliche Bedingung, unter
 * der die Oberflaeche einen laufenden Dienst als gestoppt anzeigt.
 */
function mi_dienst_pid()
{
    $pfade = mi_paths();
    $datei = $pfade['datadir'] . '/dienst.pid';
    $roh = is_readable($datei) ? trim((string) @file_get_contents($datei)) : '';
    if ($roh === '' || !ctype_digit($roh)) {
        return null;
    }
    $pid = (int) $roh;
    if ($pid < 2) {
        return null;
    }
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false || $cmd === '') {
        return null;
    }
    $teile = explode("\0", $cmd);
    if (count($teile) < 2) {
        return null;
    }
    if (!preg_match('#^python(3(\.[0-9]+)?)?$#', basename($teile[0]))) {
        return null;
    }
    $a1 = $teile[1];
    if (basename($a1) !== 'midea2lox.py') {
        return null;
    }
    $programm = $pfade['dienst'];
    if (substr($a1, 0, 1) === '/') {
        return ($a1 === $programm) ? (string) $pid : null;
    }
    /* Relativer Pfad - so startet daemon/daemon den Dienst ("./midea2lox.py"
     * im Datenordner). Eindeutig wird er erst mit dem Arbeitsordner. Steht
     * dort " (deleted)", hat ein Upgrade den Datenordner unter dem
     * laufenden Prozess neu angelegt: derselbe Pfad, derselbe Dienst.
     *
     * Ist der Arbeitsordner nicht lesbar - /proc/<pid>/cwd gibt ihn nur dem
     * Eigentuemer des Prozesses und root -, bleibt die Bindung ueber die
     * eigene PID-Datei; dann wird nicht weiter eingeschraenkt, statt einen
     * laufenden Dienst als gestoppt zu melden. */
    $wd = @readlink('/proc/' . $pid . '/cwd');
    if ($wd === false || $wd === '') {
        return (string) $pid;
    }
    $wd = preg_replace('/ \(deleted\)$/', '', $wd);
    $ziel = str_replace('/./', '/', $wd . '/' . $a1);
    return ($ziel === $programm) ? (string) $pid : null;
}

/**
 * Liegt eine gueltige Marke einer laufenden Aktualisierung?
 *
 * Dieselbe Frist und dieselbe Bauart wie in daemon/daemon (marke_gilt):
 * nur eine Marke, die hoechstens 3600 Sekunden alt ist, zaehlt; aeltere,
 * unlesbare und mehr als 300 s in der Zukunft liegende gelten nicht. Rein
 * lesend.
 */
function mi_upgrade_alter()
{
    $datei = mi_paths()['marke'];
    if (!is_file($datei)) {
        return null;
    }
    $inhalt = @file_get_contents($datei);
    if ($inhalt === false) {
        return null;
    }
    $inhalt = trim($inhalt);
    // preg_match statt ctype_digit: ctype ist eine Erweiterung, die nicht
    // garantiert geladen ist (Regeln/02), und der Inhalt wird VOR jeder
    // Rechnung als Zahl geprueft (AUFTRAG_gemeinsam, Marke).
    if (!preg_match('/^[0-9]{1,12}$/', $inhalt)) {
        return null;
    }
    return time() - (int) $inhalt;
}

/* Bis 300 s "aus der Zukunft" gilt die Marke noch - dieselbe Grenze wie
 * marke_gilt() in daemon/daemon (seit 4.5.9; Muster 8 der Nachlese, Govee
 * 0.9.20). Bis 4.5.8 galt jede Sekunde Zukunft als "keine Aktualisierung"
 * (in WSL gemessen, Pruefung-Midea2Lox-4.5.9, Fall M3). */
function mi_upgrade_laeuft()
{
    $alter = mi_upgrade_alter();
    return ($alter !== null && $alter >= -300 && $alter < 3600);
}

function mi_dienst($was)
{
    if (!in_array($was, array('start', 'stop', 'restart'), true)) {
        return 'unbekannt';
    }
    $d = mi_paths()['daemon'];
    if (!is_executable($d)) {
        return 'fehlt';
    }
    /* WAEHREND EINER AKTUALISIERUNG WIRD NICHT GESTARTET - UND NICHTS
     * BEHAUPTET.
     *
     * daemon/daemon ueberspringt den Start seit 4.5.7, solange die Marke
     * aus preupgrade.sh gilt, und endet dabei mit 0 (ein uebersprungener
     * Start ist kein Fehlschlag). Ohne diese Abfrage bekaeme die
     * Oberflaeche also eine 0 und meldete "Der Dienst wurde neu
     * gestartet", obwohl nichts gestartet wurde - eine Behauptung ohne
     * Wirkung (CLAUDE.md Abschnitt 2). "stop" bleibt erlaubt: anhalten
     * geht immer. */
    if ($was !== 'stop' && mi_upgrade_laeuft()) {
        return 'aktualisierung';
    }
    /* Den Rueckgabewert HOLEN und ansehen.
     *
     * Bis 4.3.2 stand hier ein exec() ohne $output und ohne $return_var,
     * und die Funktion lieferte danach immer ''. Die Oberflaeche schloss
     * daraus auf "Der Dienst wurde neu gestartet" - eine Behauptung, kein
     * Befund.
     *
     * BERICHTIGT AM 17.09.2026: hier stand, daemon/daemon ende bei einem
     * Fehlschlag mit 1, und genau diese Auskunft sei weggeworfen worden.
     * Der erste Halbsatz war falsch. daemon/daemon prueft zwar seit 4.3.0
     * die Wirkung (es wartet zwei Sekunden und sieht nach, ob der Prozess
     * noch lebt), warf den Rueckgabewert von start_dienst aber selbst weg:
     * am Dateiende stand ein nacktes "exit 0". Gemessen 17.09.2026 in WSL
     * mit einem Programm, das sofort aufgibt: Ausgabe "FEHLER: Midea2Lox
     * wurde gestartet, lief aber nach zwei Sekunden nicht mehr",
     * Rueckgabewert 0 - und diese Funktion lieferte '' und die Oberflaeche
     * "Der Dienst wurde neu gestartet". Seit 4.5.6 folgt der
     * Rueckgabewert des Startskripts der Wirkung, und dieselbe Messung
     * ergibt hier 'fehlgeschlagen'.
     *
     * Die Ausgabe wird mit aufgefangen, damit sie nicht in die Seite
     * laeuft; gebraucht wird sie nicht. */
    $aus = array();
    $code = 0;
    /* MI_START_TROTZ_WILLE=1: daemon/daemon startet seit 4.5.7 nicht mehr,
     * wenn der Merker soll_laufen fehlt - sonst lief ein bewusst
     * angehaltener Dienst nach dem naechsten Systemstart wieder (gemessen
     * 18.09.2026, Bestand-2026-09-18/klasse-G, Spielart G1). Hier ist der
     * Merker gerade nicht der Massstab, sondern der Knopfdruck: bis hierher
     * kommt nur ein angemeldeter Mensch, der "Dienst starten" oder
     * "Dienst neu starten" gedrueckt oder Einstellungen gespeichert hat.
     * Ohne diese Ausnahme waere der Knopf "Dienst starten" nach einem
     * "Dienst anhalten" wirkungslos - der Merker ist dann fort. Die
     * Ausnahme gilt nur fuer diesen einen Aufruf.
     *
     * Die Zuweisung vor dem Befehl geht durch /bin/sh, das exec() ohnehin
     * benutzt; "stop" bekommt sie nicht, dort gibt es nichts zu starten. */
    $vorsatz = ($was === 'stop') ? '' : 'MI_START_TROTZ_WILLE=1 ';
    @exec($vorsatz . escapeshellarg($d) . ' ' . escapeshellarg($was) . ' 2>&1', $aus, $code);
    return ((int) $code === 0) ? '' : 'fehlgeschlagen';
}

/**
 * Das Lebenszeichen des Dienstes.
 *
 * Der Dienst schreibt data/plugins/<Ordner>/lebenszeichen.json bei jedem
 * Herzschlag. Ein Prozess kann dastehen und nichts tun - die Prozessnummer
 * beantwortet nur die erste von drei Fragen.
 *
 * Rueckgabe: array(alter_in_sekunden|null, zaehler|null, ok|null, roh).
 */
function mi_lebenszeichen()
{
    $datei = mi_paths()['leben'];
    if (!is_readable($datei)) {
        return array(null, null, null, array());
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['ts'])) {
        return array(null, null, null, array());
    }
    $alter = time() - (int) $d['ts'];
    return array($alter,
        isset($d['zaehler']) ? (int) $d['zaehler'] : null,
        isset($d['ok']) ? (int) $d['ok'] : null,
        $d);
}

/**
 * Auf ein Kind warten, ohne dass proc_close() haengen kann; Rueckgabe sein Rueckgabewert, 124 nach Ablauf der Frist.
 * Hoechstens $warten Sekunden ungestoert, dann SIGTERM, hoechstens $frist Sekunden, dann SIGKILL, erst dann proc_close(). Bis 4.5.18 stand hier proc_close()
 * ohne Frist: am Geraet hing mosquitto_sub am 01.11.2026 nach SIGTERM 5 Stunden fest, und der Aufrufer stand mit. proc_get_status() liefert den Rueckgabewert
 * nur beim ersten Aufruf nach dem Ende, proc_close() danach -1 (PHP unter 8.3), darum kommt er von hier und nicht von proc_close().
 */
function mi_proc_abwarten($pr, $warten, $frist = 2.0)
{
    $stufe = 0;
    $bis = microtime(true) + max(0.0, (float) $warten);
    $schlaf = 2000;
    while (true) {
        $s = @proc_get_status($pr);
        if (!is_array($s) || empty($s['running'])) {
            $rc = is_array($s) && isset($s['exitcode']) ? (int) $s['exitcode'] : -1;
            if (is_array($s) && !empty($s['signaled'])) { $rc = 128 + (int) $s['termsig']; }
            @proc_close($pr);
            return $stufe > 0 ? 124 : $rc;
        }
        if (microtime(true) >= $bis) {
            if ($stufe === 1) {
                @proc_terminate($pr, 9);
                @proc_close($pr);
                return 124;
            }
            @proc_terminate($pr, 15);
            $stufe = 1;
            $bis = microtime(true) + (float) $frist;
        }
        usleep($schlaf);
        $schlaf = min(50000, $schlaf * 2);
    }
}

/** Etwas in der virtuellen Python-Umgebung ausfuehren. */
function mi_python($argumente, $frist = 180)
{
    $venv = mi_paths()['venv'];
    if (!is_executable($venv)) {
        return array(1, sprintf(mi_t('UI.VENV_FEHLT'), $venv));
    }
    /*
     * proc_open mit einem Argumentfeld statt exec() mit einer Zeichenkette.
     *
     * Eine Einschleusung war ueber den alten Weg NICHT moeglich - jedes
     * Argument ging durch escapeshellarg. Nachgestellt mit "; touch ...",
     * "$(touch ...)" und "a && touch ...": keiner der drei Versuche hat
     * etwas ausgefuehrt, alle kamen als woertliches Argument beim Programm
     * an.
     *
     * Umgestellt wird trotzdem, aus zwei sachlichen Gruenden:
     *   - Ohne Zeichenkette gibt es gar keine Shell mehr, die etwas
     *     auslegen koennte. Was nicht da ist, kann auch nicht falsch
     *     abgesichert werden.
     *   - escapeshellarg VERWIRFT Bytes, die in der eingestellten Locale
     *     kein gueltiges Zeichen ergeben. Fuer Pfade mit Umlauten ist das
     *     eine stille Falle.
     *
     * Das Argumentfeld von proc_open gibt es seit PHP 7.4 - also auf jedem
     * LoxBerry. Fuer aeltere Fassungen bleibt der alte Weg als Rueckfall.
     */
    $argv = array_merge(array($venv), array_map('strval', (array) $argumente));
    $beschreibung = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $rohr = array();
    $prozess = (PHP_VERSION_ID >= 70400)
        ? @proc_open($argv, $beschreibung, $rohr)
        : false;

    if (!is_resource($prozess)) {
        $befehl = escapeshellarg($venv);
        foreach ($argv as $i => $a) {
            if ($i === 0) { continue; }
            $befehl .= ' ' . escapeshellarg($a);
        }
        $aus = array(); $code = 0;
        @exec($befehl . ' 2>&1', $aus, $code);
        return array($code, implode("\n", $aus));
    }

    /* Bis 4.5.18 nacheinander und ohne Frist gelesen: ein haengendes Python hielt die Seite fest, und mehr als ein Rohr voll auf stderr blockierte das Lesen von stdout. */
    stream_set_blocking($rohr[1], false);
    stream_set_blocking($rohr[2], false);
    $aus = array(1 => '', 2 => '');
    $offen = array(1 => $rohr[1], 2 => $rohr[2]);
    $bis = microtime(true) + (float) $frist;
    while ($offen && microtime(true) < $bis) {
        $lesen = array_values($offen);
        $w = null;
        $e = null;
        if (@stream_select($lesen, $w, $e, 0, 200000) === false) { break; }
        foreach ($offen as $n => $h) {
            $t = fread($h, 65536);
            if ($t !== false && $t !== '' && strlen($aus[$n]) < 1048576) { $aus[$n] .= $t; }
            if (feof($h)) { fclose($h); unset($offen[$n]); }
        }
    }
    foreach ($offen as $h) { fclose($h); }
    $code = mi_proc_abwarten($prozess, max(0.0, $bis - microtime(true)));
    return array($code, rtrim($aus[1] . $aus[2], "\n"));
}

/* Die drei Fassungen werden je Seitenaufruf hoechstens EINMAL ermittelt
 * (ab 4.5.10, O9): der Reiter Test fragte sie in der Selbstpruefung und im
 * Knopf "Umgebung" erneut. */
function mi_msmart_version()
{
    static $w = null;
    if ($w === null) {
        list($code, $aus) = mi_python(array('-c', 'import msmart; print(msmart.__version__)'));
        $w = ($code === 0) ? trim($aus) : '';
    }
    return $w;
}

function mi_python_version()
{
    static $w = null;
    if ($w === null) {
        list($code, $aus) = mi_python(
            array('-c', "import sys; print('%d.%d.%d' % sys.version_info[:3])"));
        $w = ($code === 0) ? trim($aus) : '';
    }
    return $w;
}

/** Die Fassung von paho-mqtt in der Umgebung - '' heisst nicht ermittelbar. */
function mi_paho_version()
{
    static $w = null;
    if ($w === null) {
        list($code, $aus) = mi_python(
            array('-c', 'import paho.mqtt as p; print(getattr(p, "__version__", ""))'));
        $w = ($code === 0) ? trim($aus) : '';
    }
    return $w;
}

/**
 * Die Fassungen von msmart-ng, Python und paho - frisch gemessen oder aus
 * dem Zwischenspeicher (ab 4.5.10, O9).
 *
 * Bis 4.5.9 startete JEDER Seitenaufruf die venv-Python fuenfmal, zweimal
 * davon mit "import msmart" - auch auf den Reitern, die nichts davon zeigen
 * (in WSL gemessen, Bericht Oberflaeche Befund 11: 1,5 s je Seite mit einer
 * Attrappe von 0,3 s je Start). Jetzt misst nur der Reiter Test ($frisch)
 * und legt das Ergebnis ab; der Reiter Einstellungen zeigt den abgelegten
 * Stand, solange die Umgebung dieselbe ist (Zeitstempel von pyvenv.cfg und
 * site-packages - ein neues venv oder ein pip-Lauf aendert sie). Sonst steht
 * dort "unbekannt" und der Hinweis auf den Reiter Test.
 *
 * Rueckgabe: array('msmart', 'python', 'paho', 'zwischenspeicher' => bool).
 */
function mi_fassungen($frisch = false)
{
    $p = mi_paths();
    $kennung = '';
    if ($p['venv'] !== '' && is_file($p['venv'])) {
        $wurzel = dirname(dirname($p['venv']));
        $sp = glob($wurzel . '/lib/python3*/site-packages');
        $kennung = (string) @filemtime($wurzel . '/pyvenv.cfg') . ':'
                 . (($sp && isset($sp[0])) ? (string) @filemtime($sp[0]) : '');
    }
    $leer = array('msmart' => '', 'python' => '', 'paho' => '', 'zwischenspeicher' => false);
    if (!$frisch) {
        clearstatcache(true, $p['fassungen']);
        $d = is_readable($p['fassungen'])
           ? json_decode((string) @file_get_contents($p['fassungen']), true) : null;
        if ($kennung === '' || !is_array($d) || !isset($d['kennung']) || $d['kennung'] !== $kennung) {
            return $leer;
        }
        $aus = array('zwischenspeicher' => true);
        foreach (array('msmart', 'python', 'paho') as $k) {
            $aus[$k] = (isset($d[$k]) && is_string($d[$k])) ? $d[$k] : '';
        }
        return $aus;
    }
    $aus = array('msmart' => mi_msmart_version(), 'python' => mi_python_version(),
                 'paho' => mi_paho_version());
    if ($kennung !== '') {
        $js = json_encode($aus + array('kennung' => $kennung, 'zeit' => time()));
        if ($js !== false) {
            mi_datei_schreiben($p['fassungen'], $js, 0644);
        }
    }
    $aus['zwischenspeicher'] = false;
    return $aus;
}

/* ==================================================================
 * MQTT
 * ================================================================== */

/**
 * Einstellungen des MQTT-Gateways.
 *
 * Der Gateway ist seit LoxBerry 3 Bestandteil des Systems
 * (webfrontend/htmlauth/system/mqtt.cgi) und kein nachzuinstallierendes
 * Plugin. Der Abschnitt "Mqtt" steht deshalb immer in der general.json -
 * ab Werk mit Brokerhost localhost, Port 1883, Uselocalbroker 1 und
 * Gatewayautostart 1. Ein Test auf "Brokerhost vorhanden" beantwortet also
 * nicht die Frage "ist ein Gateway da", sondern nur "ist die Konfiguration
 * lesbar". Ob der Gateway mitlaeuft, sagt Gatewayautostart.
 */
function mi_mqtt_config()
{
    $datei = mi_paths()['general'];
    if (!is_readable($datei)) {
        return null;
    }
    $alles = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($alles)) {
        return null;
    }
    $m = isset($alles['Mqtt']) ? $alles['Mqtt']
       : (isset($alles['mqtt']) ? $alles['mqtt'] : null);
    if (!is_array($m)) {
        return null;
    }
    $hole = function ($gross, $klein, $vorgabe) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        if (isset($m[$klein])) { return $m[$klein]; }
        return $vorgabe;
    };
    return array(
        'host'      => (string) $hole('Brokerhost', 'brokerhost', 'localhost'),
        'port'      => (int) $hole('Brokerport', 'brokerport', 1883),
        'user'      => (string) $hole('Brokeruser', 'brokeruser', ''),
        'local'     => (int) $hole('Uselocalbroker', 'uselocalbroker', 1) ? true : false,
        'autostart' => (int) $hole('Gatewayautostart', 'gatewayautostart', 1) ? true : false,
    );
}

/**
 * Das Themenpraefix.
 *
 * Bis 4.2.12 stand hier fest 'Midea2Lox', und im Python-Teil stand dieselbe
 * Zeichenkette noch einmal an vier Stellen. Wer zwei LoxBerry auf denselben
 * Broker legt, konnte sie nicht auseinanderhalten. Jetzt kommt der Wert aus
 * der Konfiguration - und der Dienst liest DIESELBE Datei, es gibt also nur
 * eine Quelle.
 */
function mi_mqtt_topic($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $p = trim((string) mi_cfg($cfg, 'mqtt_praefix', 'Midea2Lox'), '/ ');
    return $p !== '' ? $p : 'Midea2Lox';
}

/**
 * Name, unter dem das MQTT-Gateway ein Thema in Loxone fuehrt: Praefix_Rest, jeder Schraegstrich und jedes % zu _
 * (am 18.10.2026 am Geraet gemessen, /dev/shm/mqttgateway_topics.json: Midea2Lox/status/dienst ->
 * Midea2Lox_status_dienst; Regeln/07 "Umbenennungsregel des MQTT-Gateways"). Dieselbe Funktion fuer die Titel der
 * Eingangsvorlage (mi_vorlage()), Schritt 3 und die Spalte Name der Musterseiten im Reiter "Einbindung in Loxone".
 *
 * Pflegewelle 22 (4.5.17, Muster ap_gateway_name() aus APC-UPS 1.2.21): bis 4.5.16 ersetzte mi_vorlage() den
 * Schraegstrich nur im Thema, nicht im Praefix. Bei einem Praefix mit Ebenen (Haus/Klima, von mi_wert_pruefen()
 * erlaubt) hiess der Eingang der Vorlage Haus/Klima_<Nummer>_power_state, das Gateway lieferte an
 * Haus_Klima_<Nummer>_power_state - der Eingang der Vorlage bekam keinen Wert. Beim Werkspraefix Midea2Lox ist das
 * Ergebnis dasselbe wie vorher. Das MQTT-Thema selbst (Abo <Praefix>/#) bleibt mit Schraegstrich.
 */
function mi_gateway_name($topic, $rest)
{
    return str_replace(array('/', '%'), '_', $topic . '_' . $rest);
}

/**
 * Die je Geraet veroeffentlichten Werte - ALLE 28.
 *
 * Bis 4.2.12 nannte diese Liste 7 Werte, waehrend der Dienst 28 sendete. Der
 * Reiter MQTT ist die Anleitung: 21 Themen - darunter alle drei
 * Energiewerte - standen nirgends, wer sie haben wollte, musste den
 * Python-Quelltext lesen.
 *
 * Die Reihenfolge ist die des Sendecodes in data/midea2lox.py. Die
 * Selbstpruefung im Reiter Test zaehlt beide gegeneinander; laufen sie
 * auseinander, faellt es dort auf und nicht dem Anwender.
 *
 * Je Eintrag:
 *   einheit  Anzeige in der Tabelle
 *   text     Sprachschluessel der Bedeutung
 *   gruppe   grund | komfort | energie
 *   vorlage  null = kommt NICHT in die Loxone-Vorlage (Textwert),
 *            sonst array(Signed, MinVal, MaxVal, Unit)
 */
function mi_werte()
{
    $g = '&mdash;';
    $c = '&deg;C';
    /* Gruppe 15 (4.5.18, F-E5): die Spalte vorlage traegt den Fehlwert im Bereich - -1 fuer Zustaende, Feuchte und
     * Energie, -999 fuer Temperaturen (mi_fehlwert, mi_mqtt.FEHLWERT im Dienst). Sie speist die Namenstabelle; die
     * Eingangsvorlage (VirtualInHttp-Kunstgriff) ist entfallen. online bleibt 0/1 - es kommt immer. */
    return array(
        // ---- Grundwerte ----
        'power_state'            => array($g,      'WERT.POWER_STATE',        'grund',   array('true',  '-1',   '1',    '<v.0>')),
        'online'                 => array($g,      'WERT.ONLINE',             'grund',   array('false', '0',    '1',    '<v.0>')),
        'indoor_temperature'     => array($c,      'WERT.INDOOR_TEMPERATURE', 'grund',   array('true',  '-999', '80',   '<v.1> °C')),
        'outdoor_temperature'    => array($c,      'WERT.OUTDOOR_TEMPERATURE','grund',   array('true',  '-999', '80',   '<v.1> °C')),
        'target_temperature'     => array($c,      'WERT.TARGET_TEMPERATURE', 'grund',   array('true',  '-999', '40',   '<v.1> °C')),
        'operational_mode'       => array('Text',  'WERT.OPERATIONAL_MODE',   'grund',   null),
        'fan_speed'              => array('Text',  'WERT.FAN_SPEED',          'grund',   null),
        'swing_mode'             => array('Text',  'WERT.SWING_MODE',         'grund',   null),
        // ---- Komfort ----
        'audible_feedback'       => array($g,      'WERT.AUDIBLE_FEEDBACK',   'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'eco_mode'               => array($g,      'WERT.ECO_MODE',           'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'turbo_mode'             => array($g,      'WERT.TURBO_MODE',         'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'display_on'             => array($g,      'WERT.DISPLAY_ON',         'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'sleep_mode'             => array($g,      'WERT.SLEEP_MODE',         'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'follow_me'              => array($g,      'WERT.FOLLOW_ME',          'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'freeze_protection_mode' => array($g,      'WERT.FREEZE_PROTECTION',  'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'purifier'               => array($g,      'WERT.PURIFIER',           'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'self_clean_active'      => array($g,      'WERT.SELF_CLEAN',         'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'filter_alert'           => array($g,      'WERT.FILTER_ALERT',       'komfort', array('true',  '-1',   '1',    '<v.0>')),
        'target_humidity'        => array('%',     'WERT.TARGET_HUMIDITY',    'komfort', array('true',  '-1',   '100',  '<v.0> %')),
        'indoor_humidity'        => array('%',     'WERT.INDOOR_HUMIDITY',    'komfort', array('true',  '-1',   '100',  '<v.0> %')),
        'horizontal_swing_angle' => array('Text',  'WERT.H_SWING_ANGLE',      'komfort', null),
        'vertical_swing_angle'   => array('Text',  'WERT.V_SWING_ANGLE',      'komfort', null),
        'rate_select'            => array('Text',  'WERT.RATE_SELECT',        'komfort', null),
        'breeze_mode'            => array('Text',  'WERT.BREEZE_MODE',        'komfort', null),
        'ieco'                   => array($g,      'WERT.IECO',               'komfort', array('true',  '-1',   '1',    '<v.0>')),
        // ---- Energie ----
        'total_energy_usage'     => array('kWh',   'WERT.TOTAL_ENERGY',       'energie', array('true',  '-1',   '100000', '<v.2> kWh')),
        'current_energy_usage'   => array('kWh',   'WERT.CURRENT_ENERGY',     'energie', array('true',  '-1',   '100000', '<v.2> kWh')),
        'real_time_power_usage'  => array('W',     'WERT.REAL_TIME_POWER',    'energie', array('true',  '-1',   '20000',  '<v.0> W')),
    );
}

/** Die vier Themen des Lebenszeichens - eine Quelle fuer Tabelle und Vorlage. */
function mi_status_werte()
{
    return array(
        // Signed und MinVal -1: der Wert kennt drei Zustaende, und -1
        // ("noch nichts gemessen") muss durch den virtuellen Eingang
        // hindurchpassen. Mit MinVal 0 wuerde Loxone ihn auf 0 kappen -
        // und aus "noch nichts gemessen" wuerde "gescheitert".
        'status/ok'      => array('&mdash;', 'WERT.ST_OK',      array('true',  '-1', '1',       '<v.0>')),
        'status/ts'      => array('s',       'WERT.ST_TS',      array('false', '0', '4000000000', '<v.0>')),
        'status/zaehler' => array('&mdash;', 'WERT.ST_ZAEHLER', array('false', '0', '999',      '<v.0>')),
        'status/dienst'  => array('&mdash;', 'WERT.ST_DIENST',  array('false', '0', '1',        '<v.0>')),
        // Gruppe 15 (4.5.18): ob MQTT-Befehle angenommen werden (1/0/-1), mit dem Herzschlag, nie retained.
        'status/befehle' => array('&mdash;', 'WERT.ST_BEFEHLE', array('true',  '-1', '1',       '<v.0>')),
        // M4 (ab 4.5.10): der Letzte Wille - connected bei jedem Verbinden,
        // disconnected vom Broker, beide retained (das Paar aus Regeln/07).
        // Bis 4.5.9 stand das Thema in keiner Tabelle, obwohl die
        // Deinstallation es eigens abraeumt (Bericht mqtt M4). Ein Text,
        // deshalb ohne Vorlagenangabe.
        'connection/status' => array('Text', 'WERT.ST_VERBINDUNG', null),
    );
}

/**
 * Die vier Themen der Automatik - eine Quelle fuer Tabelle und Vorlage.
 *
 * 'automatik/grund' ist ABSICHTLICH ein Text und bekommt deshalb keine
 * Vorlagenangabe: ein virtueller Eingang traegt eine Zahl, keinen Satz.
 * Dieselbe Behandlung wie operational_mode und fan_speed.
 */
function mi_automatik_werte()
{
    return array(
        'automatik/aktiv'    => array('&mdash;', 'WERT.AU_AKTIV',
                                      array('false', '0', '1', '<v.0>')),
        'automatik/gesperrt' => array('s',       'WERT.AU_GESPERRT',
                                      array('false', '0', '86400', '<v.0>')),
        'automatik/geraete'  => array('&mdash;', 'WERT.AU_GERAETE',
                                      array('false', '0', '99', '<v.0>')),
        'automatik/grund'    => array('Text',    'WERT.AU_GRUND', null),
        // Gruppe 15 (4.5.18, V1): der Abwesenheitsbetrieb - 1/0/-1, mit dem Herzschlag, nie retained.
        'anwesenheit/aktiv'  => array('&mdash;', 'WERT.ANW_AKTIV',
                                      array('true', '-1', '1', '<v.0>')),
    );
}

/**
 * Themen, die zurueckbehalten werden - dieselbe Liste wie im Dienst.
 *
 * Seit 4.5.9 eine POSITIVLISTE: alles, was hier nicht steht, geht fluechtig
 * hinaus. Bis 4.5.8 stand hier die Liste der Themen OHNE Retain, und ein
 * neues Thema wurde still zurueckbehalten; online eines Geraets und die vier
 * Themen der Automatik gingen retained, obwohl sie Aussagen des Dienstes
 * sind bzw. eine Restzeit tragen (Regeln/07 Abschnitt 3; in WSL am
 * empfangenen Paket gemessen, Pruefung-Midea2Lox-4.5.9, R5, R8-R13).
 * Retained sind nur Geraetezustaende und der Letzte Wille connection/status.
 * Der Dienst fuehrt dieselbe Liste als MIT_RETAIN in data/mi_mqtt.py; dass
 * beide gleich bleiben, prueft mi_retain_probe() nach.
 */
function mi_mit_retain()
{
    return array(
        'connection/status',
        'power_state', 'audible_feedback', 'target_temperature',
        'operational_mode', 'fan_speed', 'swing_mode', 'eco_mode', 'turbo_mode',
        'display_on', 'target_humidity', 'filter_alert',
        'horizontal_swing_angle', 'vertical_swing_angle', 'freeze_protection_mode',
        'sleep_mode', 'follow_me', 'purifier', 'self_clean_active',
        'rate_select', 'breeze_mode', 'ieco',
    );
}

/** Wird dieses Thema zurueckbehalten? Mit Geraetenummer davor am hinteren Teil. */
function mi_retain($thema)
{
    $t = trim((string) $thema, '/');
    $mit = mi_mit_retain();
    if (in_array($t, $mit, true)) { return true; }
    if (preg_match('#^[0-9]{10,19}/([a-z_]+)$#', $t, $m)) {
        return in_array($m[1], $mit, true);
    }
    return false;
}

/**
 * Fuehren Oberflaeche und Dienst dieselbe Retain-Liste?
 *
 * Liefert (ok, Zahl im Dienst, was auseinanderlaeuft). Der Dienst ist die
 * massgebliche Seite - er sendet. Findet die Probe im Dienst gar keine
 * Liste, sagt sie das, statt Gleichheit zu behaupten.
 */
function mi_retain_probe()
{
    $datei = mi_paths()['mqtt_py'];
    if (!is_readable($datei)) {
        return array(null, 0, array());
    }
    $q = (string) @file_get_contents($datei);
    if (!preg_match('/MIT_RETAIN\s*=\s*\((.*?)\)/s', $q, $m)) {
        return array(null, 0, array());
    }
    preg_match_all("/'([A-Za-z0-9_\/]+)'/", $m[1], $t);
    $dienst = array_values(array_unique($t[1]));
    $hier = mi_mit_retain();
    $ab = array_merge(array_diff($dienst, $hier), array_diff($hier, $dienst));
    return array(count($dienst) > 0 && !$ab, count($dienst), array_values($ab));
}

/* ==================================================================
 * Miniserver
 * ================================================================== */

function mi_miniserver_liste()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_miniservers')) {
        $ms = LBSystem::get_miniservers();
        if (is_array($ms)) {
            return $ms;
        }
    }
    return array();
}

function mi_localip()
{
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'get_localip')) {
        $ip = LBSystem::get_localip();
        if ($ip) { return $ip; }
    }
    $ip = gethostbyname(gethostname());
    return $ip ? $ip : '192.168.x.y';
}

/**
 * Die Regionen des Midea-Kontos - mit der Zuordnung auf msmart-ng.
 *
 * GEMESSEN am Quelltext von msmart-ng (Zweig main, abgerufen 27.08.2026):
 * msmart kennt genau DREI Wolkenregionen, nicht elf.
 *
 *     msmart/cloud.py
 *       CLOUD_CREDENTIALS = { "DE": (...), "KR": (...), "US": (...) }
 *       if account and password:      self._account = ...
 *       elif account or password:     raise ValueError("Account and password must be specified.")
 *       else:
 *           try:    self._account, self._password = self.CLOUD_CREDENTIALS[region]
 *           except KeyError: raise ValueError(f"Unknown cloud region '{region}'.")
 *     msmart/const.py
 *       DEFAULT_CLOUD_REGION = "US"
 *
 * Daraus folgt zweierlei, und beides war bis 4.2.12 falsch:
 *
 *  1. Die Oberflaeche bot elf Regionen an. Neun davon haetten, sobald der
 *     Wert wirklich durchgereicht wird, ein ValueError ausgeloest. Wer die
 *     Felder einfach "anschliesst", macht damit die Geraetesuche kaputt,
 *     die heute wenigstens laeuft. Deshalb steht die Zuordnung HIER, und
 *     nicht der Laendercode geht an msmart, sondern der Serverbereich.
 *  2. Ohne Regionsangabe benutzt msmart die Vorgabe "US" - fuer ein
 *     europaeisches Konto der falsche Server. Das ist ein moeglicher Grund
 *     dafuer, dass eine Suche fuer V3-Geraete keine Token liefert.
 *
 * China fehlt bewusst: fuer diesen Bereich hat msmart-ng keine Zugangsdaten.
 * Ein Eintrag, der nur ein ValueError erzeugen kann, ist kein Angebot. Eine
 * bestehende Konfiguration mit region=CN wird deshalb beanstandet und nicht
 * still auf etwas anderes gebogen - siehe mi_region_msmart().
 */
function mi_regionen()
{
    /* Die Namen sind seit 4.5.10 Sprachschluessel (O11): bis 4.5.9 standen
     * sie deutsch im PHP, und die englische Oberflaeche zeigte
     * "Deutschland - server DE" (Bericht Oberflaeche Befund 13). */
    return array(
        'DE' => array('REGION.DE', 'DE'),
        'AT' => array('REGION.AT', 'DE'),
        'CH' => array('REGION.CH', 'DE'),
        'NL' => array('REGION.NL', 'DE'),
        'IT' => array('REGION.IT', 'DE'),
        'ES' => array('REGION.ES', 'DE'),
        'FR' => array('REGION.FR', 'DE'),
        'PL' => array('REGION.PL', 'DE'),
        'GB' => array('REGION.GB', 'DE'),
        'US' => array('REGION.US', 'US'),
        'KR' => array('REGION.KR', 'KR'),
    );
}

/**
 * Der Serverbereich, den msmart-ng fuer diese Region braucht.
 * Leerstring heisst: nicht zuzuordnen - dann wird nichts uebergeben und die
 * Oberflaeche sagt es. Raten waere hier dasselbe wie eine Registeradresse
 * zu raten.
 */
function mi_region_msmart($region)
{
    $r = mi_regionen();
    return isset($r[$region]) ? $r[$region][1] : '';
}

function mi_log_tail($max = 200)
{
    $datei = mi_paths()['log'];
    if (!is_readable($datei)) {
        return array();
    }
    /*
     * Nur das Ende der Datei lesen, nicht die ganze.
     *
     * Bis 4.0.0 wurde sie mit file() vollstaendig in den Speicher geholt,
     * umgedreht und dann abgeschnitten. Gemessen an einer 522-kB-Datei
     * (dieselbe Groesse, bei der der Dienst frueher sein Protokoll geleert
     * hat), 200 Zeilen Ausgabe:
     *
     *     file() + array_reverse : 0,8 ms, Spitzenspeicher 1436 KB
     *     exec("tail -n 200")    : 1,7 ms,        Speicher   34 KB
     *     fseek vom Ende         : 0,3 ms,        Speicher   34 KB
     *
     * Der oft empfohlene Weg ueber das Programm "tail" spart zwar den
     * Speicher, ist aber wegen des zusaetzlichen Prozesses LANGSAMER als
     * das, was er ersetzen soll. Rueckwaerts lesen ist bei beidem besser -
     * und kommt ohne fremdes Programm aus.
     *
     * Die Ausgabe ist Zeile fuer Zeile dieselbe wie vorher; nachgeprueft.
     */
    $fp = @fopen($datei, 'rb');
    if (!$fp) {
        return array();
    }
    $block = 8192;
    fseek($fp, 0, SEEK_END);
    $rest = ftell($fp);
    $puffer = '';
    // Ein Block mehr als noetig, damit die oberste Zeile nicht angeschnitten
    // in der Ausgabe landet.
    while ($rest > 0 && substr_count($puffer, "\n") <= $max) {
        $lese = (int) min($block, $rest);
        $rest -= $lese;
        fseek($fp, $rest, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
    }
    fclose($fp);
    $zeilen = preg_split('/\R/', $puffer, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($zeilen)) {
        return array();
    }
    return array_slice(array_reverse($zeilen), 0, $max);
}

/* ==================================================================
 * Loxone-Vorlagen
 * ================================================================== */

/* Gruppe 15 (4.5.18, Entwurf 2.6, E3/M15): die Vorlage der Gateway-Eingaenge (mi_vorlage(), VirtualInHttp mit
 * Dummy-Adresse http://localhost, der Heimkino-Kunstgriff vom 12.08.2026) ist entfallen - die Bauform ist nach
 * R2_078 verboten. An ihre Stelle tritt die Namenstabelle (mi_mqtt_namen): einfache Virtuelle Eingaenge, Name
 * wie das Gateway ihn vergibt. */

/**
 * Die Befehle, die Loxone an das Plugin schicken kann.
 *
 * EINE Quelle: die Oberflaeche baut ihre Befehlstabelle daraus, die Vorlage
 * ebenso. Bis 4.2.12 standen sechs Beispiele im Reiter und ueber zwanzig
 * Befehle im Python-Teil - wer mehr wollte, musste raten.
 *
 * Je Eintrag: Sprachschluessel der Beschriftung, Befehlstext OHNE Geraete-ID,
 * und ob es ein Ein/Aus-Paar ist (dann steht der zweite Text fuer CmdOff).
 */
function mi_befehle()
{
    /* Welle Bild 19 (4.5.15, Entwurf Welle 19 md E5-E7): vierte Spalte = fester deutscher Titel des
     * Vorlagenbefehls (Sprachregel 13.10.2026: Titel einer erzeugten Vorlage bleiben in jeder Sprache
     * deutsch, wie im LoxBerry-Plugins Musterprojekt; bis 4.5.14 kam der Titel aus mi_t() und war unter
     * englischer Oberflaeche englisch). Die Beschriftung im Reiter bleibt der Sprachschluessel.
     * Solltemperatur seit 4.5.15 'temp.<v.1>' statt '<v>': der Dienst nimmt nur ein zweistelliges ganzes
     * Wort oder 'temp.<Zahl>' - '22.5' oder '8' verwarf er. Neu 'humidity.<v.0>' (der Dienst kennt
     * humidity.<0-100> seit langem, die Vorlage nicht). Analog ist jeder Befehl mit '<v'. Umlaute als
     * UTF-8-Bytes: diese Datei bleibt in ihrem Code ASCII.
     * Gruppe 15 (4.5.18, E4/F-E8): die acht rate_select-Befehle, die der Dienst seit langem kennt - nur Geraete,
     * die sie melden (supported_rate_selects); andere uebergehen den Befehl mit Protokollzeile. Diese Liste ist
     * auch die Positivliste, gegen die mi_mqtt_zuordnung_probe() die MQTT-Befehle haelt. */
    return array(
        array('BEF.EIN_AUS',     'power.True',                          'power.False',   "Ein- und ausschalten"),
        array('BEF.MODE_AUTO',   'ac.operational_mode_enum.auto',       null,            "Betriebsart Automatik"),
        array('BEF.MODE_COOL',   'ac.operational_mode_enum.cool',       null,            "Betriebsart K\xc3\xbchlen"),
        array('BEF.MODE_HEAT',   'ac.operational_mode_enum.heat',       null,            "Betriebsart Heizen"),
        array('BEF.MODE_DRY',    'ac.operational_mode_enum.dry',        null,            "Betriebsart Entfeuchten"),
        array('BEF.MODE_FAN',    'ac.operational_mode_enum.fan_only',   null,            "Betriebsart nur L\xc3\xbcften"),
        array('BEF.FAN_AUTO',    'ac.fan_speed_enum.Auto',              null,            "L\xc3\xbcfterstufe Automatik"),
        array('BEF.FAN_SILENT',  'ac.fan_speed_enum.Silent',            null,            "L\xc3\xbcfterstufe leise"),
        array('BEF.FAN_LOW',     'ac.fan_speed_enum.Low',               null,            "L\xc3\xbcfterstufe niedrig"),
        array('BEF.FAN_MEDIUM',  'ac.fan_speed_enum.Medium',            null,            "L\xc3\xbcfterstufe mittel"),
        array('BEF.FAN_HIGH',    'ac.fan_speed_enum.High',              null,            "L\xc3\xbcfterstufe hoch"),
        array('BEF.FAN_FULL',    'ac.fan_speed_enum.Full',              null,            "L\xc3\xbcfterstufe h\xc3\xb6chste"),
        array('BEF.SWING_OFF',   'ac.swing_mode_enum.Off',              null,            "Schwenken aus"),
        array('BEF.SWING_VERT',  'ac.swing_mode_enum.Vertical',         null,            "Schwenken senkrecht"),
        array('BEF.SWING_HORZ',  'ac.swing_mode_enum.Horizontal',       null,            "Schwenken waagerecht"),
        array('BEF.SWING_BOTH',  'ac.swing_mode_enum.Both',             null,            "Schwenken in beide Richtungen"),
        array('BEF.ECO',         'eco.True',                            'eco.False',     "Sparbetrieb"),
        array('BEF.TURBO',       'turbo.True',                          'turbo.False',   "Schnellbetrieb"),
        array('BEF.SLEEP',       'sleep.True',                          'sleep.False',   "Nachtbetrieb"),
        array('BEF.FOLLOW',      'follow.True',                         'follow.False',  "Regelung nach Fernbedienung"),
        array('BEF.FREEZE',      'freeze.True',                         'freeze.False',  "Frostschutz"),
        array('BEF.PURIFIER',    'purifier.True',                       'purifier.False', "Luftreinigung"),
        array('BEF.TONE',        'tone.True',                           'tone.False',    "Quittungston"),
        array('BEF.DISPLAY',     'toggle_Display',                      null,            "Anzeige am Ger\xc3\xa4t umschalten"),
        array('BEF.SELFCLEAN',   'toggle_self_clean',                   null,            "Selbstreinigung starten"),
        array('BEF.STATUS',      'status',                              null,            "Zustand abfragen"),
        array('BEF.TEMP',        'temp.<v.1>',                          null,            "Solltemperatur stufenlos"),
        array('BEF.HUM',         'humidity.<v.0>',                      null,            "Sollfeuchte stufenlos"),
        array('BEF.RATE_OFF',    'rate_select.OFF',                     null,            "Leistungsbegrenzung aus"),
        array('BEF.RATE_50',     'rate_select.GEAR_50',                 null,            "Leistungsbegrenzung 50 Prozent"),
        array('BEF.RATE_75',     'rate_select.GEAR_75',                 null,            "Leistungsbegrenzung 75 Prozent"),
        array('BEF.LEVEL_1',     'rate_select.LEVEL_1',                 null,            "Leistungsstufe 1"),
        array('BEF.LEVEL_2',     'rate_select.LEVEL_2',                 null,            "Leistungsstufe 2"),
        array('BEF.LEVEL_3',     'rate_select.LEVEL_3',                 null,            "Leistungsstufe 3"),
        array('BEF.LEVEL_4',     'rate_select.LEVEL_4',                 null,            "Leistungsstufe 4"),
        array('BEF.LEVEL_5',     'rate_select.LEVEL_5',                 null,            "Leistungsstufe 5"),
    );
}

/**
 * Vorlage fuer den virtuellen Ausgang samt Befehlen.
 *
 * Zwei Dinge, die hier anders sind als beim Eingang und die man leicht
 * verwechselt:
 *   - Analog="false" gehoert an VirtualOutCmd, nicht an VirtualInHttpCmd.
 *   - Die Reihenfolge der Attribute ist CmdOn, CmdOnMethod, CmdOff,
 *     CmdOffMethod - so steht es im Original-Export von Loxone Config.
 *
 * Bausteintitel muessen ueber ALLE Importdateien hinweg eindeutig sein,
 * sonst legt ein zweiter Import Dubletten an. Deshalb traegt jeder Befehl
 * die Geraete-ID im Titel.
 */
function mi_vorlage_ausgang($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $geraete = mi_devices();
    $ip   = mi_cfg($cfg, 'LoxberryIP', mi_localip());
    $port = mi_cfg($cfg, 'UDP_PORT', '7013');
    $crlf = "\r\n";
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    // Welle Bild 19 (4.5.15, E7): Kommentar aus der Sprachdatei, ohne Datum (wie mi_vorlage()).
    $o .= '<VirtualOut HintText="" Title="Midea2Lox Befehle" Comment="'
        . $x(mi_t('UI.VQ_KOMMENTAR')) . '" Address="'
        . $x('/dev/udp/' . $ip . '/' . $port) . '" CmdInit="" CloseAfterSend="false" CmdSeparator="">' . $crlf;
    /* templateType 3 = virtueller Ausgang. Bis 4.5.12 stand hier 1 (der Typ
     * des UDP-Eingangs), abgeschrieben aus derselben Vorlage wie AWM-Abfuhr
     * (x4_werkzeug/BERICHT.md, Hinweis h13). */
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($geraete as $d) {
        if (!isset($d['id']) || $d['id'] === '') { continue; }
        $name = mi_geraetename($d);
        foreach (mi_befehle() as $b) {
            // Welle Bild 19 (E7): fester deutscher Titel aus mi_befehle(), nicht mi_t($b[0]).
            $titel = 'Midea2Lox ' . $d['id'] . ' ' . $b[3];
            /* LEERZEICHEN, nicht Komma.
             *
             * Am laufenden Dienst gemessen (31.08.2026): der Empfaenger
             * zerlegt das Datagramm mit split(' ') und sucht die
             * Geraetenummer argumentweise ueber isdigit(). Ein Paket
             * "<ID>,power.True" ist damit EIN Argument, das keine reine
             * Ziffernfolge ist - der Dienst antwortet mit
             * "missing device_id" und schaltet nichts. Nirgends im Dienst
             * wird an einem Komma zerlegt.
             *
             * Bis 4.3.2 schrieb diese Vorlage das Komma, und damit war
             * JEDER der 27 Befehle je Geraet wirkungslos - waehrend der
             * Knopf "Senden" im Reiter Test, der das Leerzeichen benutzt,
             * einwandfrei arbeitete. Genau die Zusage "was hier
             * funktioniert, funktioniert dort" traf also nicht zu. */
            $ein  = $d['id'] . ' ' . $b[1];
            $aus  = ($b[2] !== null) ? ($d['id'] . ' ' . $b[2]) : '';
            $o .= "\t" . '<VirtualOutCmd Title="' . $x($titel) . '" ';
            $o .= 'Comment="' . $x($name) . '" ';
            $o .= 'CmdOn="' . $x($ein) . '" CmdOnMethod="UDP" ';
            $o .= 'CmdOff="' . $x($aus) . '" CmdOffMethod="UDP" ';
            $o .= 'Analog="' . (strpos($b[1], '<v') !== false ? 'true' : 'false') . '" ';
            $o .= 'Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VO_midea2lox.xml', $o);
}

/**
 * Die Fassung des LoxBerry-MQTT-Gateways - 0 heisst "nicht feststellbar".
 *
 * Sie steht als Mqtt.Gatewayversion in config/system/general.json (ab Werk
 * 1) und entscheidet, was der Anwender eintragen muss: unter V1 jedes Thema
 * von Hand auf der Abo-Seite, ab V2 erscheint die Themengruppe von selbst in
 * den Subscriptions.
 *
 * Die Datei wird hier eigens gelesen, obwohl andere Stellen sie auch lesen.
 * Das ist Absicht: dieser Baustein passt damit in jedes Plugin, unabhaengig
 * davon, wie es seinen MQTT-Zustand ermittelt - und er geht nicht kaputt,
 * wenn jemand jene Funktion umbaut.
 *
 * Seit 4.3.0 faellt die Ordnersuche auf mi_paths() zurueck. Vorher haing sie
 * allein an getenv('LBHOMEDIR'); ohne die Variable meldete sie 0 ("nicht
 * feststellbar"), obwohl der Ordner auffindbar war. Im Webserver ist sie
 * gesetzt - beim Pruefen vor der Installation nicht.
 */
function mi_gateway_fassung()
{
    $home = getenv('LBHOMEDIR');
    if (!$home && defined('LBHOMEDIR')) {
        $home = LBHOMEDIR;
    }
    if (!$home || !is_dir($home)) {
        $home = mi_paths()['home'];
    }
    if (!$home || !is_dir($home)) {
        return 0;
    }
    $d = @json_decode((string) @file_get_contents(
        $home . '/config/system/general.json'), true);
    if (!is_array($d)) {
        return 0;
    }
    foreach (array('Mqtt', 'mqtt') as $ab) {
        if (!isset($d[$ab]) || !is_array($d[$ab])) {
            continue;
        }
        foreach (array('Gatewayversion', 'gatewayversion') as $sl) {
            if (isset($d[$ab][$sl]) && (string) $d[$ab][$sl] !== '') {
                return (int) $d[$ab][$sl];
            }
        }
    }
    return 0;
}

/**
 * Der Hinweis zum MQTT-Abo - in der Fassung, die zum GATEWAY passt.
 *
 * Bis 4.1.0 stand an der Ausgabestelle unbedingt "Ohne diesen Eintrag
 * kommt am Miniserver nichts an". Das gilt fuer Gateway V1; ab V2 schickte
 * der Satz jeden Anwender zu einem Eingabeplatz, den es nicht mehr gibt.
 *
 * Drei Ausgaenge: ist die Fassung nicht feststellbar, werden BEIDE Faelle
 * genannt statt einer behauptet.
 *
 * Seit 4.3.0 kommt ein Satz dazu, der bis dahin nur in der README stand und
 * dem Reiter widersprach: das Plugin liefert das Abo in
 * config/mqtt_subscriptions.cfg bereits mit.
 */
function mi_abo_text()
{
    $f = mi_gateway_fassung();
    /* Bis 4.3.1 stand hier unbedingt "Das Plugin bringt den Eintrag mit - in
     * aller Regel ist hier also nichts von Hand zu tun". Der zweite Halbsatz
     * ist am Geraet widerlegt (29.08.2026, Gateway V1): das Gateway liest die
     * mitgelieferte Datei im Betrieb nicht. Der Satz sagt jetzt, was gemessen
     * ist - und die Selbstpruefung im Reiter Test sagt fuer DIESE Anlage, ob
     * das Thema abonniert ist. */
    $mit = ' ' . mi_t('UI.ABO_MITGELIEFERT');
    if ($f <= 0) {
        return mi_t('UI.ABO_UNBEKANNT') . $mit;
    }
    $gemessen = ' <span class="sm-mono">'
              . sprintf(mi_t('UI.ABO_GEMESSEN'), $f) . '</span>';
    // Ausgeschrieben, damit die Hauspruefer beide Schluessel sehen.
    if ($f >= 2) {
        return mi_t('UI.ABO_V2') . $gemessen . $mit;
    }
    return mi_t('UI.OHNE_DIESEN_EINTRAG_KOMMT_AM') . $gemessen . $mit;
}

/**
 * Steht unser Themenzweig wirklich in den Abonnements des Gateways?
 *
 * DAS IST DIE FRAGE, DIE ZAEHLT - und sie ist am Geraet zu beantworten,
 * nicht aus einer README. Gelesen wird config/system/subscriptions.json,
 * also das, was das Gateway wirklich abonniert hat.
 *
 * Rueckgabe: array(ja|nein|unlesbar, Liste der passenden Eintraege,
 *                  Zahl aller Abonnements).
 * Die Zahl gehoert dazu: 0 Abonnements heisst "nichts gemessen", nicht
 * "nichts gefunden".
 */
function mi_abo_eingetragen($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    /* Die erste LESBARE der beiden Listen. clearstatcache vorher, weil das
     * Gateway sie im Betrieb neu schreibt. */
    $p = mi_paths();
    $datei = '';
    foreach (array($p['abos'], $p['abos_alt']) as $kandidat) {
        clearstatcache(true, $kandidat);
        if (is_readable($kandidat)) { $datei = $kandidat; break; }
    }
    if ($datei === '') {
        return array('unlesbar', array(), 0);
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d)) {
        return array('unlesbar', array(), 0);
    }
    $liste = array();
    foreach (array('Subscriptions', 'subscriptions') as $s) {
        if (isset($d[$s]) && is_array($d[$s])) { $liste = $d[$s]; break; }
    }
    $praefix = mi_mqtt_topic($cfg);
    $treffer = array();
    foreach ($liste as $e) {
        $id = '';
        if (is_array($e)) {
            foreach (array('Id', 'id', 'topic', 'Topic') as $k) {
                if (isset($e[$k]) && is_string($e[$k])) { $id = $e[$k]; break; }
            }
        } elseif (is_string($e)) {
            $id = $e;
        }
        if ($id === '') { continue; }
        /* Ein blankes "#" ist das Abonnement auf ALLES und deckt unsere
         * Themen damit ebenfalls ab. Bis 4.3.2 fiel es durch, und die Zeile
         * forderte den Anwender auf, etwas von Hand einzutragen, das schon
         * da war. */
        if ($id === '#' || $id === $praefix . '/#' || $id === $praefix . '/+'
            || strpos($id, $praefix . '/') === 0) {
            $treffer[] = $id;
        }
    }
    if ($treffer) {
        return array('ja', $treffer, count($liste));
    }
    /* NICHT in der Liste des Anwenders - das heisst aber nicht "nicht
     * abonniert".
     *
     * Das Gateway liest `config/plugins/<Ordner>/mqtt_subscriptions.cfg`
     * und haengt deren Zeilen an seine Abo-Liste. Belegt im Quelltext
     * (`sbin/mqttgateway.pl`: get_plugins -> watch -> read_file ->
     * push @subscriptions -> subscribe) und am laufenden Gateway durch
     * die Rechnung "Before 53 / Afterwards 52": 52 Abos des Anwenders plus
     * die eine Zeile aus unserer Datei, eine Dopplung.
     *
     * Bis 4.5.3 stand hier das Gegenteil - die Messung vom 29.08.2026 hatte
     * in `subscriptions.json` nachgesehen, wo Plugin-Abos NIE landen: das
     * Gateway haelt sie nur im Arbeitsspeicher. Ein Blick an die falsche
     * Stelle, und "nicht gefunden" wurde als "wird nicht gelesen" gelesen. */
    list($dateilage, , $dateisoll) = mi_abo_datei($cfg);
    if ($dateilage === 'ok') {
        return array('mitgeliefert', array($dateisoll), count($liste));
    }
    return array('nein', array(), count($liste));
}

/**
 * Steht das eigene Abo wirklich in mqtt_subscriptions.cfg?
 *
 * OFFENER PUNKT, ausdruecklich gekennzeichnet: dass LoxBerry diese Datei
 * ueberhaupt liest, steht in der README dieses Plugins und ist HIER NICHT
 * GEMESSEN. Im ganzen Arbeitsordner liefern nur zwei Plugins die Datei aus,
 * in den Regeldateien kommt sie nicht vor, und die SDK-Attrappe kennt sie
 * nicht. Die Messung, die es beantwortet, ist billig und steht in der
 * Selbstpruefung als eigene Zeile: Datei anlegen, Gateway neu starten, in
 * den Subscriptions nachsehen.
 *
 * Diese Funktion beantwortet deshalb nur, was sie beantworten KANN: ob die
 * Datei da ist und ob ihr Inhalt zum eingestellten Praefix passt.
 * Rueckgabe: array(ok|fehlt|abweichend, Ist-Inhalt, Soll-Inhalt).
 */
function mi_abo_datei($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $soll = mi_mqtt_topic($cfg) . '/#';
    $datei = mi_paths()['abo'];
    if (!is_file($datei)) {
        return array('fehlt', '', $soll);
    }
    $ist = trim((string) @file_get_contents($datei));
    return array($ist === $soll ? 'ok' : 'abweichend', $ist, $soll);
}

/**
 * mqtt_subscriptions.cfg auf das eingestellte Praefix bringen.
 *
 * KEIN Kommentar in die Datei: '#' ist im MQTT-Thema der Platzhalter fuer
 * "alles darunter". Eine Zeile, die mit '#' beginnt, waere kein Kommentar,
 * sondern ein Abonnement auf saemtliche Themen des Brokers.
 */
function mi_abo_datei_schreiben($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $datei = mi_paths()['abo'];
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0775, true);
    }
    // Seit 4.5.10 ueber mi_datei_schreiben() (C9); kein Geheimnis, deshalb 0644.
    return mi_datei_schreiben($datei, mi_mqtt_topic($cfg) . '/#', 0644);
}

/* ==================================================================
 * Selbstpruefung: was die Prueflkette nicht sieht, zaehlt der Reiter Test
 * ================================================================== */

/**
 * Passen Reiterleiste, Flaechen und Positivliste zusammen?
 *
 * Drei Stellen in derselben Datei, die auseinanderlaufen koennen. Fehlt ein
 * Name in der Positivliste, ist der Reiter sichtbar und anklickbar - aber
 * nach jedem Absenden springt die Seite auf Einstellungen zurueck.
 *
 * Gemeldet wird auch die Zahl der angesehenen Stellen. Eine Null ist kein
 * "in Ordnung", sondern der Hinweis, dass nichts gemessen wurde.
 */
function mi_smactive_probe()
{
    $datei = mi_paths()['index'];
    if (!is_readable($datei)) {
        return array(false, 0, 0, 0, 0);
    }
    $q = (string) @file_get_contents($datei);
    // Die Positivliste steht ausgeschrieben in EINER Zeile.
    $liste = array();
    if (preg_match('#/\^tab-\(([a-z|]+)\)\$/#', $q, $m)) {
        $liste = explode('|', $m[1]);
    }
    preg_match_all('/href="index\.php\?form=([a-z]+)"/', $q, $mv);
    preg_match_all('/id="tab-([a-z]+)"/', $q, $mf);
    $verweise = array_values(array_unique($mv[1]));
    $flaechen = array_values(array_unique($mf[1]));
    sort($liste); sort($verweise); sort($flaechen);
    $ok = $liste && $liste === $verweise && $liste === $flaechen;
    return array($ok, count($liste), count($verweise), count($flaechen), strlen($q));
}

/**
 * Tragen alle Formulare das Merkmal?
 *
 * Ein Formular vergisst man. Gezaehlt wird im eigenen Quelltext, nicht im
 * gerenderten HTML - so faellt auch ein Formular auf, das gerade hinter
 * einer Bedingung steht und deshalb nicht gerendert wird.
 */
function mi_formularprobe()
{
    $datei = mi_paths()['index'];
    if (!is_readable($datei)) {
        return array(false, 0, 0);
    }
    $q = (string) @file_get_contents($datei);
    $formulare = preg_match_all('/<form\b[^>]*method=["\']post["\']/i', $q);
    /* Gezaehlt wird die AUSGABE des Merkmals (O6, ab 4.5.10). Bis 4.5.9 zaehlte
     * das Muster jedes "mi_fmt()" - und das Formular "Automatik" schrieb sein
     * Feld von Hand: 16 Formulare, 15 Treffer, ein Kreuz bei jedem Aufruf,
     * obwohl gerendert 16 von 16 das Merkmal trugen (Bericht Oberflaeche
     * Befund 8). Jetzt benutzt jedes Formular mi_fmt(). */
    $merkmale  = preg_match_all('/echo\s+mi_fmt\(\)\s*;/', $q);
    return array($formulare > 0 && $merkmale >= $formulare, $formulare, $merkmale);
}

/**
 * Stimmt die Themenliste mit dem Sendecode ueberein?
 *
 * Die Tabelle im Reiter MQTT ist die Anleitung. Bis 4.2.12 nannte sie 7
 * Werte, waehrend der Dienst 28 sendete - gemessen. Diese Zeile haelt beide
 * Zahlen gegeneinander und nennt die fehlenden beim Namen.
 */
function mi_themen_probe()
{
    $datei = mi_paths()['dienst'];
    if (!is_readable($datei)) {
        return array(null, 0, count(mi_werte()) + count(mi_status_werte())
                     + count(mi_automatik_werte()), array());
    }
    $q = (string) @file_get_contents($datei);
    $leben = mi_paths()['leben_py'];
    if (is_readable($leben)) {
        $q .= "\n" . (string) @file_get_contents($leben);
    }

    /* Das Suchmuster gehoert zur AUFRUFFORM des Dienstes, nicht zu der, die
     * er einmal hatte.
     *
     * Der erste Anlauf suchte noch die Form von 4.2.12 - eine Zeichenkette
     * mit "%s/<name>,". Der Dienst baut seine Werte seit 4.3.0 einzeln
     * auf, das Muster fand nichts, und die Zeile meldete 0 gesendete Themen
     * gegen 28 genannte: ein Kreuz, das dauerhaft rot steht und nichts
     * bedeutet. Aufgefallen ist es erst, als der Pfad im Pruefstand
     * ueberhaupt betreten wurde - vorher stand die Zeile auf "nicht
     * messbar" und sah damit harmlos aus.
     *
     * Geeicht in drei Richtungen: unveraendert gruen, ein Wert aus der
     * Tabelle genommen rot, ein Thema im Dienst umbenannt rot. */
    preg_match_all("/\\bnimm\\(\\s*'([a-z0-9_]+)'/", $q, $m);
    $gesendet = array_values(array_unique($m[1]));
    // Der fuehrende Schraegstrich gehoert ins Muster: lebenszeichen.py setzt
    // sein Thema als praefix . '/status/dienst' zusammen. Ein Muster, das das
    // Anfuehrungszeichen unmittelbar vor "status" erwartet, findet es nicht -
    // und meldete status/dienst dauerhaft als fehlend, obwohl es gesendet wird.
    preg_match_all("/['\\/](status\\/[a-z]+)'/", $q, $ms);
    $gesendet = array_merge($gesendet, array_values(array_unique($ms[1])));

    /* Die Themen der Automatik. Der Dienst baut sie als Paare auf
     * ('automatik/aktiv', ...) - das Muster oben findet sie nicht, und ohne
     * diese Zeile stuenden sie dauerhaft als "genannt, aber nicht gesendet"
     * in der Zeile. Geeicht in beide Richtungen. */
    preg_match_all("/'(automatik\\/[a-z]+)'/", $q, $ma);
    // Gruppe 15 (4.5.18, V1): anwesenheit/aktiv kommt als Paar wie die Automatik.
    preg_match_all("/'(anwesenheit\\/[a-z]+)'/", $q, $mw);
    $ma[1] = array_merge($ma[1], $mw[1]);
    $gesendet = array_merge($gesendet, array_values(array_unique($ma[1])));

    /* M4 (ab 4.5.10): das Thema, das der Dienst woertlich an das Praefix
     * haengt (MQTT_PRAEFIX + '/connection/status', Letzter Wille). Bis 4.5.9
     * trafen die Muster oben es nicht, und die Zeile meldete Gleichstand,
     * waehrend es in keiner Tabelle stand. Geeicht: Zeile aus
     * mi_status_werte() genommen -> rot. */
    preg_match_all("/MQTT_PRAEFIX\\s*\\+\\s*'\\/([a-z_]+\\/[a-z_]+)'/", $q, $mc);
    $gesendet = array_merge($gesendet, array_values(array_unique($mc[1])));

    $genannt = array_merge(array_keys(mi_werte()), array_keys(mi_status_werte()),
                           array_keys(mi_automatik_werte()));
    $fehlt = array_values(array_diff($gesendet, $genannt));
    $zuviel = array_values(array_diff($genannt, $gesendet));
    return array(count($gesendet) > 0 && !$fehlt && !$zuviel,
                 count($gesendet), count($genannt),
                 array_merge($fehlt, $zuviel));
}

/**
 * Sind die Sprachschluessel der Tabellen vorhanden?
 *
 * Das ist die Blindstelle von sprachschluessel_pruefen.py, ausdruecklich:
 * das Werkzeug findet nur Aufrufe, in denen der Schluessel WOERTLICH in der
 * Klammer steht. Die Schluessel aus mi_werte(), mi_status_werte() und
 * mi_befehle() gehen ueber eine Variable dorthin und werden nie gesehen.
 *
 * (Das Beispiel dazu steht hier bewusst NICHT in der Aufrufform. Ein
 * Erklaertext, der das gesuchte Muster woertlich zeigt, wird vom eigenen
 * Sucher getroffen und meldet einen Schluessel, den es nie gab - genau so
 * ist es beim ersten Lauf dieser Pruefung passiert.)
 *
 * Gemessen wird an der Wirkung: mi_t() liefert bei einem unbekannten
 * Schluessel den Schluessel selbst zurueck. Steht der Rueckgabewert also
 * gleich dem Schluessel, fehlt der Text.
 */
function mi_sprache_probe()
{
    $fehlt = array();
    $anzahl = 0;
    foreach (mi_werte() as $w) {
        $anzahl++;
        if (mi_t($w[1]) === $w[1]) { $fehlt[] = $w[1]; }
    }
    foreach (array_merge(mi_status_werte(), mi_automatik_werte()) as $w) {
        $anzahl++;
        if (mi_t($w[1]) === $w[1]) { $fehlt[] = $w[1]; }
    }
    foreach (mi_befehle() as $b) {
        $anzahl++;
        if (mi_t($b[0]) === $b[0]) { $fehlt[] = $b[0]; }
    }
    foreach (mi_regionen() as $r) {
        // Seit 4.5.10 stehen die Laendernamen in der Sprachdatei (O11).
        $anzahl++;
        if (mi_t($r[0]) === $r[0]) { $fehlt[] = $r[0]; }
    }
    return array(!$fehlt, $anzahl, $fehlt);
}

/** Sind die erzeugbaren Loxone-Vorlagen wohlgeformt? */
function mi_vorlagen_probe($cfg = null)
{
    /* Gruppe 15 (4.5.18): die Eingangsvorlage (mi_vorlage, VirtualInHttp-Kunstgriff) ist entfallen; geprueft werden
     * die UDP-Vorlage und die Vorlage MQTT-Befehle - wohlgeformt, und kein Titel der MQTT-Vorlage gleicht einem
     * Gateway-Namen (sonst eine Schleife, Wiki widget_mqtt), alle Befehle beginnen mit publish <praefix>/befehl/. */
    $anzahl = 0;
    $p = mi_mqtt_topic($cfg);
    foreach (array('mi_vorlage_ausgang', 'mi_vorlage_mqtt_out') as $f) {
        if (!function_exists($f)) { continue; }
        list($name, $inhalt) = $f($cfg);
        $anzahl++;
        $d = new DOMDocument();
        $alt = libxml_use_internal_errors(true);
        $ok = $d->loadXML($inhalt);
        libxml_clear_errors();
        libxml_use_internal_errors($alt);
        if (!$ok) {
            return array(false, $anzahl, $name);
        }
        if ($f === 'mi_vorlage_mqtt_out') {
            foreach ($d->getElementsByTagName('VirtualOutCmd') as $c) {
                $t = $c->getAttribute('Title');
                if (strpos($t, ': ') === false || strpos($t, $p . '_') === 0
                    || strpos($c->getAttribute('CmdOn'), 'publish ' . $p . '/befehl/') !== 0) {
                    return array(false, $anzahl, $name . ' (' . $t . ')');
                }
            }
        }
    }
    return array($anzahl > 0, $anzahl, '');
}

/* ==================================================================
 * Einstellungen sichern und zurueckspielen
 * ================================================================== */

/**
 * Die Sicherungsdatei bauen - VOLLSTAENDIG.
 *
 * DER AKTIONSTOKEN GEHOERT IN DIE DATEI. Bei diesem Plugin ist das nicht das
 * Midea-Kennwort, sondern das Paar aus token (128 Zeichen) und key (64) je
 * Geraet in devices.cfg: nur damit kommt das Plugin ohne die Wolke an ein
 * V3-Geraet. Bis 4.2.12 enthielt die Sicherung nur midea2lox.cfg - wer sie
 * auf einem zweiten LoxBerry einspielte, hatte alle Felder richtig und
 * musste trotzdem neu suchen lassen. Die Datei war damit fuer ihren
 * eigentlichen Zweck, den Umzug, wertlos.
 *
 * Der FORMULARTOKEN gehoert ausdruecklich NICHT hinein: der lebt eine
 * Sitzung und schuetzt gegen fremde Absender. Wer beide verwechselt, macht
 * aus der Umzugshilfe ein Leck.
 *
 * Der lesbare Kopf traegt Unterstrichnamen; mi_sicherung_lesen() UEBERGEHT
 * sie, statt sie zu beanstanden - sonst lehnte das Plugin die Datei ab, die
 * dieselbe Bibliothek zwei Zeilen vorher erzeugt hat.
 */
/**
 * Der Zustand der Automatik, so wie der Dienst ihn zuletzt abgelegt hat.
 *
 * Liefert (lage, feld, alter):
 *   'aus'      die Automatik ist nicht eingeschaltet
 *   'fehlt'    eingeschaltet, aber es gibt noch keine Standdatei -
 *              der Dienst hat seit dem Einschalten keinen Durchgang gehabt
 *   'unlesbar' Datei da, aber kein brauchbares JSON
 *   'alt'      Datei da, aber aelter als drei Takte
 *   'ok'       frisch
 *
 * Die Faelle sind ABSICHTLICH unterscheidbar: "noch nie gelaufen" ist ein
 * anderer Zustand als "laeuft nicht mehr", und der Anwender muss anderes
 * tun.
 */
function mi_automatik_lage($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    if (mi_cfg($cfg, 'auto_ein', '0') !== '1') {
        return array('aus', array(), null);
    }
    $datei = mi_paths()['automatik'];
    clearstatcache(true, $datei);
    if (!is_readable($datei)) {
        return array('fehlt', array(), null);
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['ts'])) {
        return array('unlesbar', array(), null);
    }
    $alter = time() - (int) $d['ts'];
    $takt = (int) mi_cfg($cfg, 'auto_takt', '300');
    if ($takt < 60) { $takt = 300; }
    return array($alter > 3 * $takt ? 'alt' : 'ok', $d, $alter);
}

/** Die Sprachansagen aus dem Feld der Hauptkonfiguration: was fehlt, kommt aus den Vorgaben (ab Werk EIN). */
function mi_ansage_block_lesen()
{
    $roh = (function () { $c = mi_config_read(); return isset($c['ansage_block']) ? (string) $c['ansage_block'] : ''; })();
    $b = $roh !== '' ? json_decode((string) base64_decode($roh), true) : null;
    return (is_array($b) ? $b : array()) + mi_ansage_vorgaben();
}

/** Zurueck in das Feld der Hauptkonfiguration. */
function mi_ansage_block_schreiben(array $cfg)
{
    $x = array();
    foreach (mi_ansage_schluessel() as $k) { if (array_key_exists($k, $cfg)) { $x[$k] = $cfg[$k]; } }
    $b = base64_encode((string) json_encode($x));
    $c = mi_config_read(); $c['ansage_block'] = $b; return mi_config_write($c);
}

/** Die Schluessel der Sprachansagen in der Hauptkonfiguration. */
function mi_ansage_schluessel()
{
    return array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft', 'ansage_aus_datei');
}

/** Die Vorgaben: nie gesetzt = ab Werk EIN. Ein gespeicherter Block bleibt, auch 'aus'. */
function mi_ansage_vorgaben()
{
    return array('tts' => ansage_werk_block(), 'ansage_regeln' => ansage_regeln_vorgaben(), 'ansage_haken' => array('ausfall' => 1, 'fehler' => 1), 'ansage_token_herkunft' => array(), 'ansage_aus_datei' => 0);
}

/** Wertpruefung fuer die Sprachansagen: null = nicht meine Schluessel, sonst tauglich ja/nein. */
function mi_ansage_wert($k, $w)
{
    if (!in_array((string) $k, mi_ansage_schluessel(), true)) { return null; }
    $g = '';
    switch ((string) $k) {
        case 'tts':
            return is_array($w) && ansage_wert_pruefen($w, $g, mi_ansage_modi()) !== null;
        case 'ansage_regeln':
            return is_array($w) && ansage_regeln_wert($w, $g) !== null;
        case 'ansage_haken':
            if (!is_array($w)) { return false; }
            foreach ($w as $a => $v) {
                if (!array_key_exists($a, array('ausfall' => 1, 'fehler' => 1)) || !in_array($v, array(0, 1, '0', '1'), true)) { return false; }
            }
            return true;
        case 'ansage_token_herkunft':
            return is_array($w);
        case 'ansage_aus_datei':
            return in_array($w, array(0, 1, '0', '1'), true);
    }
    return false;
}

/** Fuer die Sicherung: nie ein Sprechtoken, nie der Herkunftsvermerk. Ausgabeart, Ziele, Regeln und Haken reisen mit. */
function mi_ansage_fuer_sicherung(array $c)
{
    unset($c['ansage_token_herkunft'], $c['ansage_aus_datei']);
    if (isset($c['tts']) && is_array($c['tts'])) { $c['tts'] = ansage_sicherung_bereinigen($c['tts']); }
    return $c;
}

/**
 * Vor dem Zurueckspielen. Eine Datei mit Sprechtoken wird abgewiesen (der Block wird unbrauchbar gemacht, die Wertpruefung nennt ihn). Die geltenden Token und der Vermerk bleiben.
 * Fehlt ein Schluessel (Sicherung einer aelteren Fassung), gilt der geltende Wert: Ein bewusstes AUS geht beim Zurueckspielen nicht verloren.
 */
function mi_ansage_sicherung_vor($roh)
{
    $d = json_decode((string) $roh, true);
    if (!is_array($d)) { return $roh; }
    $inhalt = false;
    foreach (array_keys($d) as $k) { if ((string) $k !== '' && ((string) $k)[0] !== '_') { $inhalt = true; } }
    if (!$inhalt) { return $roh; }
    $j = mi_ansage_cfg();
    unset($d['ansage_token_herkunft'], $d['ansage_aus_datei']);
    $jt = isset($j['tts']) && is_array($j['tts']) ? $j['tts'] : array();
    if (array_key_exists('tts', $d)) {
        $g = '';
        $p = is_array($d['tts']) ? ansage_wert_pruefen($d['tts'], $g, mi_ansage_modi()) : null;
        if (ansage_sicherung_mangel($d['tts']) || $p === null) {
            $d['tts'] = 'ABGEWIESEN';
        } else {
            list($tv) = ansage_vervollstaendigen($p, 'aus');
            $d['tts'] = ansage_sicherung_tokens_behalten($tv, $jt);
        }
    } else {
        $d['tts'] = mi_tts($j);
    }
    foreach (array_diff(mi_ansage_schluessel(), array('tts', 'ansage_token_herkunft', 'ansage_aus_datei')) as $k) {
        if (!array_key_exists($k, $d) && array_key_exists($k, $j)) { $d[$k] = $j[$k]; }
    }
    $d['ansage_token_herkunft'] = isset($j['ansage_token_herkunft']) && is_array($j['ansage_token_herkunft']) ? $j['ansage_token_herkunft'] : array();
    $d['ansage_aus_datei'] = 1;
    return (string) json_encode($d);
}

/**
 * Die Hauptkonfiguration. Liegt eine ansage.json aus einer Probe vom 01.11., wird sie EINMAL uebernommen (Vermerk ansage_aus_datei) und bleibt liegen.
 */
function mi_ansage_cfg()
{
    $c = mi_ansage_block_lesen();
    if (empty($c['ansage_aus_datei'])) {
        $f = rtrim(dirname(mi_paths()['config']), '/') . '/ansage.json';
        if (is_file($f)) {
            $d = json_decode((string) @file_get_contents($f), true);
            if (is_array($d)) {
                foreach (array('tts', 'ansage_regeln', 'ansage_haken', 'ansage_token_herkunft') as $k) {
                    if (array_key_exists($k, $d) && mi_ansage_wert($k, $d[$k]) === true) { $c[$k] = $d[$k]; }
                }
            }
            $c['ansage_aus_datei'] = 1;
            mi_ansage_cfg_schreiben($c);
        }
    }
    return $c;
}

function mi_ansage_cfg_schreiben(array $cfg)
{
    return mi_ansage_block_schreiben($cfg);
}

/** Die Ausgabearten dieser Linie. */
function mi_ansage_modi()
{
    return array_values(array_diff(ansage_modi(), array('audioserver')));
}

/** Der Block tts: gespeichert bleibt, auch 'aus'; ungueltig gilt als 'aus'. */
function mi_tts($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    return ansage_block_oder_aus(isset($cfg['tts']) ? $cfg['tts'] : ansage_werk_block(), mi_ansage_modi());
}

/** Der Kontext des Moduls. */
function mi_ansage_k()
{
    $d = mi_paths()['datadir'];
    return array(
        'port' => ansage_webport(ansage_lbhome() . '/config/system/general.json'),
        'home' => ansage_lbhome(),
        'kopf' => array('User-Agent: LoxBerry Midea2Lox'),
        'ordner' => @is_dir($d) ? $d : '',
        't' => function ($s) { return mi_t($s); },
        'werk' => ansage_werk_modus(),
    );
}

/** Die Anlaesse: Name => Beschriftung. */
function mi_ansage_anlaesse()
{
    return array('ausfall' => mi_t('ANSAGEPLUGIN.L_AUSFALL'), 'fehler' => mi_t('ANSAGEPLUGIN.L_FEHLER'));
}

/** Ist der Haken dieses Anlasses gesetzt? Fehlt er, gilt die Vorgabe der Linie. */
function mi_ansage_anlass_an($a, $cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    $h = (isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken'])) ? $cfg['ansage_haken'] : array();
    $v = array('ausfall' => 1, 'fehler' => 1);
    return !empty(array_key_exists($a, $h) ? $h[$a] : (isset($v[$a]) ? $v[$a] : 0));
}

/** Die Eingaben nach einer Beanstandung bis zur naechsten Seite aufheben, nie ein Sprechtoken. */
function mi_ansage_eingaben($setzen = null)
{
    $f = mi_paths()['datadir'] . '/ansage_eingaben.json';
    if (is_array($setzen)) {
        if (!is_dir(dirname($f))) { @mkdir(dirname($f), 0775, true); }
        @file_put_contents($f, (string) json_encode($setzen));
        @chmod($f, 0600);
        return $setzen;
    }
    $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    if (is_array($d) && $d) { @file_put_contents($f, '{}'); return $d; }
    return array();
}

/** Das Formular lesen und speichern: array(gelungen, Fehlertexte, Hinweise). */
function mi_ansage_post(array $post)
{
    $cfg = mi_ansage_cfg();
    $k = mi_ansage_k();
    $m = array();
    $b = array();
    $h = array();
    $neu = ansage_formular_lesen($post, mi_tts($cfg), $m, $b, array('modi' => mi_ansage_modi(), 'ziele' => true, 'token_vor_ort' => true, 'aus_gewinnt' => true), $k, $h);
    list($r, $hk) = ansage_regeln_formular($post, mi_ansage_regeln($cfg), mi_ansage_anlaesse(), $m, $b, $k);
    if ($m) {
        $t = array();
        foreach ($m as $x) { $t[] = $x['text']; }
        $e = array('_bean' => array_values($b));
        foreach ($post as $n => $w) {
            if (strncmp((string) $n, 'tts_', 4) === 0 || strncmp((string) $n, 'ansage_', 7) === 0) {
                if ((string) $n !== 'tts_alexa_token' && (string) $n !== 'tts_google_token') { $e[$n] = $w; }
            }
        }
        mi_ansage_eingaben($e);
        return array(false, $t, array());
    }
    $cfg['tts'] = $neu;
    // 4.5.21: ein eingetipptes oder geloeschtes Sprechtoken ist nicht mehr vom Nachbarn; bis 4.5.20 ueberschrieb es der naechste Abgleich.
    $cfg['ansage_token_herkunft'] = ansage_token_herkunft_formular($post, isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array());
    $cfg['ansage_regeln'] = $r;
    $cfg['ansage_haken'] = $hk;
    $t = array();
    foreach ($h as $x) { $t[] = $x['text']; }
    return array(mi_ansage_cfg_schreiben($cfg), array(), $t);
}

/** Der Abschnitt im Reiter Einstellungen, eigenes Formular. $felder: die versteckten Felder (Formularmerkmal, Reiter). Nach einer Beanstandung traegt er das Eingetippte. */
function mi_ansage_form_html($felder)
{
    $cfg = mi_ansage_cfg();
    $k = mi_ansage_k();
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $ein = mi_ansage_eingaben();
    $bean = isset($ein['_bean']) && is_array($ein['_bean']) ? $ein['_bean'] : array();
    $h = array(
        'w' => function ($n, $g) use ($ein) { return (isset($ein[$n]) && is_string($ein[$n])) ? $ein[$n] : $g; },
        'm' => function ($n) use ($bean) { return in_array($n, $bean, true) ? ' class="sm-beanstandet"' : ''; },
        'c' => function ($n, $g) use ($ein) { return $ein ? !empty($ein[$n]) : (bool) $g; },
    );
    $o = array();
    $o[] = '<h2>' . $e(mi_t('ANSAGEPLUGIN.H')) . '</h2>';
    $o[] = '<form action="index.php" method="post" autocomplete="off">' . $felder;
    $o[] = ansage_formular_html(mi_tts($cfg), $h + array('modi' => mi_ansage_modi(), 'ziele' => true, 'ziele_post' => isset($ein['tts_ziel_art']) ? $ein : null), $k);
    $o[] = ansage_regeln_html(mi_ansage_regeln($cfg), isset($cfg['ansage_haken']) && is_array($cfg['ansage_haken']) ? $cfg['ansage_haken'] : array('ausfall' => 1, 'fehler' => 1), mi_ansage_anlaesse(), $h + array('token_vor_ort' => true), $k);
    $o[] = '<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_speichern" value="1">' . $e(mi_t('ANSAGEPLUGIN.K_SPEICHERN')) . '</button>';
    $o[] = '<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="ansage_test" value="1">' . $e(mi_t('ANSAGEPLUGIN.K_TEST')) . '</button></div>';
    $o[] = '</form>';
    $o[] = mi_ansage_lage_html($cfg);
    return implode("\n", $o) . "\n";
}

/** Die Testansage (der Knopf spricht immer). Rueckgabe: array(gesendet, Grund). */
function mi_ansage_test()
{
    $k = mi_ansage_k();
    mi_ansage_token_pflegen();
    $r = ansage_testansage(mi_ansage_vor_ort(), $k);
    mi_ansage_zeile('Testansage: ' . ansage_kurz($r));
    return array($r['stand'] === 1, $r['stand'] === 1 ? '' : ansage_kennung_text($r['kennung'], $k));
}

/* ---------------- Sprachansagen: Regeln aus dem Stamm (4.5.19, Welle S7, Stamm sprachausgabe.php 1.4.0) ----------------
 *
 * Nachtruhe, Sperre und Schalter "Ansagen aus" stehen im Stamm. Ab Werk EIN: ansage_werk_block() in den Vorgaben, also nur fuer einen
 * nie gespeicherten Block; ein gespeicherter bleibt, auch 'aus'. Ein Minutenlauf und ein Dienst sprechen nie selbst: Sie legen den Anlass
 * in <Daten>/ansage_warte.json, ein abgekoppelter Sender (php -r, eigene Sperre) spricht und wartet auf die Lautsprecher.
 */

/** So alt darf ein wartender Anlass werden (nach einem Update oder Hang wird nichts Aufgestautes angesagt). */
if (!defined('MI_ANSAGE_HOECHSTALTER')) { define('MI_ANSAGE_HOECHSTALTER', 600); }

/** Die Regeln: gespeichert und gueltig, sonst aus einer alten Einstellung dieser Linie, sonst die Vorgabe (mit Nachtruhe). */
function mi_ansage_regeln($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    $g = '';
    if (!empty($cfg['ansage_regeln'])) {
        $r = ansage_regeln_wert($cfg['ansage_regeln'], $g);
        return $r === null ? ansage_regeln_vorgaben() : $r;
    }
    $r = ansage_regeln_vorgaben();
    $p = ansage_regeln_wert($r, $g);
    return $p === null ? ansage_regeln_vorgaben() : $p;
}

/** Die Warteliste unter einer Dateisperre aendern: $fn(Liste) -> array(neue Liste, Ergebnis). */
function mi_ansage_warte($fn)
{
    $d = mi_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $f = $d . '/ansage_warte.json';
    $h = @fopen($f, 'c+');
    if ($h === false) { return null; }
    if (!flock($h, LOCK_EX)) { fclose($h); return null; }
    $alt = json_decode((string) stream_get_contents($h), true);
    list($neu, $erg) = call_user_func($fn, is_array($alt) ? $alt : array());
    rewind($h);
    ftruncate($h, 0);
    fwrite($h, (string) json_encode($neu));
    fflush($h);
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($f, 0600);
    return $erg;
}

/**
 * Anlaesse hinlegen und den Sender starten; wartet auf nichts. $l: Eintraege fuer ansage_regel_sprechen() (schluessel, text, dringend,
 * sperre_min, nur_nach). Die Schale schliesst vor dem exec jede Deskriptor-Nummer ueber 2, sonst erbte der Sender die Sperren des Rufers.
 */
function mi_ansage_legen(array $l, $jetzt = null)
{
    if (!$l) { return 'nichts'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    mi_ansage_warte(function ($alt) use ($l, $jetzt) {
        foreach ($l as $e) { $alt[] = $e + array('zeit' => $jetzt); }
        return array(array_slice($alt, -50), null);
    });
    $php = (defined('PHP_BINARY') && PHP_BINARY !== '' && is_executable(PHP_BINARY) && stripos(basename(PHP_BINARY), 'php') === 0 && stripos(basename(PHP_BINARY), 'fpm') === false) ? PHP_BINARY : 'php';
    $befehl = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; '
            . 'exec ' . escapeshellarg($php) . ' -r ' . escapeshellarg('chdir(dirname($argv[1])); $_SERVER["SCRIPT_FILENAME"] = $_SERVER["PHP_SELF"] = $argv[1]; require $argv[1]; mi_ansage_senden();') . ' -- ' . escapeshellarg(__FILE__);
    $aus = array();
    $rc = 1;
    @exec('bash -c ' . escapeshellarg($befehl) . ' </dev/null >/dev/null 2>&1 &', $aus, $rc);
    return $rc === 0 ? 'gestartet' : 'kein_start';
}

/** Das Sprechtoken aus Alexa-NG und Chromecast4lox uebernehmen (Stamm 1.3.1). Schreibt NUR bei einer Aenderung, nennt nie das Token. */
function mi_ansage_token_pflegen($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    list($neu, $her2, $g) = ansage_token_abgleich(mi_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), mi_ansage_k());
    if (!$g) { return false; }
    $cfg = mi_ansage_cfg();
    $cfg['tts'] = $neu;
    $cfg['ansage_token_herkunft'] = $her2;
    if (!mi_ansage_cfg_schreiben($cfg)) { return false; }
    mi_ansage_zeile('Ansage: Sprechtoken uebernommen (' . implode(', ', $g) . ').');
    return true;
}

/** Der Block fuer Testansage und Pruefzeile: uebernommene Token aufgeloest. Nur im Speicher. */
function mi_ansage_vor_ort($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    return ansage_token_fuer_lauf(mi_tts($cfg), isset($cfg['ansage_token_herkunft']) ? $cfg['ansage_token_herkunft'] : array(), mi_ansage_k());
}

/** Der Block fuer die Pruefzeile: nur die eingerichteten Ziele; ist keines eingerichtet, sagt die Zeile "aus" statt eines Kreuzes. */
function mi_ansage_pruefblock($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    $t = mi_tts($cfg);
    if ($t['mode'] === 'aus') { return $t; }
    list($b) = ansage_ziele_bereit(mi_ansage_vor_ort($cfg), mi_ansage_k());
    if ($b === null) { $t['mode'] = 'aus'; return $t; }
    return $b;
}

/** Die Lage im Reiter Test: Ziele, Nachtruhe, Schalter. Nur im geoeffneten Reiter (der Schalter fragt den Broker). */
function mi_ansage_lage_html($cfg = null)
{
    if ($cfg === null) { $cfg = mi_ansage_cfg(); }
    $k = mi_ansage_k();
    $o = array();
    foreach (ansage_regel_lage(mi_tts($cfg), mi_ansage_regeln($cfg), $k, true) as $z) {
        $o[] = '<div class="sm-hilfe">' . htmlspecialchars((string) $z[1], ENT_QUOTES, 'UTF-8') . '</div>';
    }
    return implode("\n", $o) . "\n";
}

/** Die Felder der Regeln fuer die Einmalmeldung (X-2). */
function mi_ansage_regel_felder(array $anlaesse = array())
{
    return ansage_regeln_x2_felder($anlaesse);
}

/**
 * Im Vorgang sprechen, wo der Rufer das Ergebnis braucht (Antwort an Loxone, Befehl): Regeln aus dem Stamm mit "dringend, ohne Sperre" - es zaehlt nur der Schalter.
 * Schalter gesetzt: stand -1, Kennung SCHALTER. Nichts bereit oder Original-Audioserver: wie bisher ansage_sprechen(), damit die Antwort sagt, was fehlt.
 */
function mi_ansage_direkt($text, array $e = array(), $tts = null)
{
    $conf = mi_ansage_cfg();
    $k = mi_ansage_k();
    $t = $tts === null ? mi_tts($conf) : $tts;
    $a = ansage_regel_sprechen(array($e + array('schluessel' => 'direkt', 'text' => (string) $text, 'dringend' => true, 'sperre_min' => 0)), $t, mi_ansage_regeln($conf), $k);
    if ($a['r'] !== null) { return $a['r']; }
    if ($a['regel'] === 'SCHALTER') {
        return array('stand' => -1, 'kennung' => 'SCHALTER', 'art' => isset($t['mode']) ? (string) $t['mode'] : '-', 'http' => 0, 'zeile' => '', 'zeichen' => 0);
    }
    return ansage_sprechen((string) $text, $t, $k);
}

/**
 * Der Sender: eigene Sperre, nimmt die Warteliste und spricht nach den Regeln. "aus" und "kein Ziel eingerichtet" hinterlassen keine Zeile;
 * ein gescheitertes Ziel genau eine je Aenderung (ab Werk EIN darf kein Protokoll vollaufen). Text und Token stehen nie im Protokoll.
 */
function mi_ansage_senden($jetzt = null)
{
    $d = mi_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $fh = @fopen($d . '/ansage_senden.lock', 'c');
    if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) { return 'laeuft'; }
    $erg = 'nichts';
    $l = mi_ansage_warte(function ($alt) { return array(array(), $alt); });
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $frisch = array();
    foreach (is_array($l) ? $l : array() as $e) {
        if (is_array($e) && isset($e['zeit']) && $jetzt - (int) $e['zeit'] <= MI_ANSAGE_HOECHSTALTER) { $frisch[] = $e; }
    }
    if ($frisch) {
        $conf = mi_ansage_cfg();
        mi_ansage_token_pflegen($conf);
        $conf = mi_ansage_cfg();
        $a = ansage_regel_sprechen($frisch, mi_tts($conf), mi_ansage_regeln($conf), mi_ansage_k());
        $erg = $a['regel'] !== '' ? $a['regel'] : 'gesprochen';
        if ($a['r'] !== null) {
            $merk = $d . '/ansage_lage.txt';
            $lage = $a['r']['stand'] === 1 ? 'gesprochen' : ansage_kurz($a['r']);
            if ($a['r']['stand'] === 1 || (string) @file_get_contents($merk) !== $lage) { mi_ansage_zeile('Ansage: ' . ansage_regel_kurz($a)); }
            @file_put_contents($merk, $lage);
        } elseif ($a['regel'] !== 'AUS' && $a['regel'] !== 'KEIN_ZIEL') {
            mi_ansage_zeile('Ansage entfaellt: ' . ansage_regel_kurz($a));
        }
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $erg;
}

/**
 * Die Flanke eines Anlasses (4.5.19). Merker <Daten>/ansage_flanke.json, unter Dateisperre; er ueberlebt einen Neustart des Dienstes.
 * Der erste Lauf schweigt: Was schon gestoert war, als die Linie zum ersten Mal hinsah, sagt sie nicht an und meldet auch keine Rueckkehr.
 * Nur der Eintritt spricht, nach $frist Sekunden Dauer; die Rueckkehr nur, wenn der Eintritt gesprochen hat. Ein abgewaehlter Haken laeuft mit: Einschalten sagt nichts Altes an.
 * $name: der Name, den der Nutzer vergeben hat, nur im Satz, nie im Protokoll; Kennungen, Adressen und Seriennummern gehoeren nicht hierher.
 * Rueckgabe: nichts, erster_lauf, aus, oder das Ergebnis von <px>_ansage_legen().
 */
function mi_ansage_flanke($anlass, $gestoert, $name = '', $frist = 0, $jetzt = null)
{
    $l = array('ausfall' => array('ausfall', 'SATZ_AUSFALL', 'SATZ_WIEDER', 0), 'fehler' => array('fehler', 'SATZ_FEHLER', 'SATZ_BEHOBEN', 0));
    if (!isset($l[$anlass])) { return 'unbekannt'; }
    $jetzt = $jetzt === null ? time() : (int) $jetzt;
    $name = trim((string) preg_replace('/[^\p{L}\p{N} .,:!?\-]/u', ' ', (string) $name));
    $sch = $anlass . ($name !== '' ? '|' . substr(md5($name), 0, 8) : '');
    $d = mi_paths()['datadir'];
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    $h = @fopen($d . '/ansage_flanke.json', 'c+');
    if ($h === false) { return 'nichts'; }
    if (!flock($h, LOCK_EX)) { fclose($h); return 'nichts'; }
    $m = json_decode((string) stream_get_contents($h), true);
    $m = is_array($m) ? $m : array();
    $e = (isset($m[$sch]) && is_array($m[$sch]) && isset($m[$sch]['z'], $m[$sch]['s'], $m[$sch]['g'])) ? $m[$sch] : null;
    $neu = $gestoert ? 1 : 0;
    $tat = 'nichts';
    if ($e === null) {
        $e = array('z' => $neu, 's' => $jetzt, 'g' => $neu ? 2 : 0);
        $tat = 'erster_lauf';
    } elseif ($neu === 1) {
        if ((int) $e['z'] === 0) { $e = array('z' => 1, 's' => $jetzt, 'g' => 0); }
        if ((int) $e['g'] === 0 && $jetzt - (int) $e['s'] >= (int) $frist) {
            if (mi_ansage_anlass_an($l[$anlass][0])) { $e['g'] = 1; $tat = 'stoerung'; } else { $e['g'] = 2; $tat = 'aus'; }
        }
    } elseif ((int) $e['z'] === 1) {
        if ((int) $e['g'] === 1) { $tat = $l[$anlass][2] !== '' ? 'wieder' : 'nichts'; }
        $e = array('z' => 0, 's' => $jetzt, 'g' => 0);
    }
    if (!isset($m[$sch]) || $m[$sch] !== $e) {     // nur bei einer Aenderung schreiben: Dienste rufen das im Takt
        $m[$sch] = $e;
        rewind($h);
        ftruncate($h, 0);
        fwrite($h, (string) json_encode($m));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);
    @chmod($d . '/ansage_flanke.json', 0600);
    if ($tat !== 'stoerung' && $tat !== 'wieder') { return $tat; }
    $satz = mi_t('ANSAGEPLUGIN.' . ($tat === 'stoerung' ? $l[$anlass][1] : $l[$anlass][2]));
    if (strpos($satz, '%s') !== false) { $satz = sprintf($satz, $name); }
    $ein = array('schluessel' => $tat === 'stoerung' ? $sch : 'wieder|' . $sch, 'text' => $satz);
    if ($tat === 'wieder') { $ein['nur_nach'] = $sch; }
    if ($tat === 'stoerung' && $l[$anlass][3]) { $ein['dringend'] = true; }
    mi_ansage_token_pflegen();
    return mi_ansage_legen(array($ein));
}

/** Fuer die Sicherung: die Hauptkonfiguration ohne das Feld, dazu die Felder der Sprachansagen ohne Sprechtoken und Vermerk. */
function mi_ansage_sicherung_felder(array $c)
{
    unset($c['ansage_block']);
    return mi_ansage_fuer_sicherung(mi_ansage_cfg()) + $c;
}

/** Eine Protokollzeile der Sprachansagen. */
function mi_ansage_zeile($t)
{
    $f = (string) mi_paths()['log'];
    if ($f !== '' && is_dir(dirname($f))) { @file_put_contents($f, date('Y-m-d H:i:s') . ' Oberflaeche: ' . $t . "\n", FILE_APPEND | LOCK_EX); }
}

function mi_sicherung_bauen($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $voll = array_merge(mi_vorgaben(), $cfg);
    $geraete = array();
    foreach (mi_devices() as $d) {
        $geraete[] = array(
            'id'          => (string) $d['id'],
            'typ'         => (string) $d['typ'],
            'ip'          => (string) $d['ip'],
            'port'        => (string) $d['port'],
            'bezeichnung' => (string) $d['bezeichnung'],
            'token'       => (string) $d['token'],
            'key'         => (string) $d['key'],
        );
    }
    $fassung = '';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
        /* Ueber den Ordnernamen fragen (Regeln/03): ohne Argument haengt die
         * Antwort am ersten eingebundenen Skript - am Geraet gemessen
         * 17.09.2026: aus einem fremden Einstieg (php -r) NULL, mit dem
         * Ordnernamen die installierte Fassung. Installiert liegt diese Datei
         * unter webfrontend/html(auth)/plugins/<ordner>/. */
        $fassung = (string) LBSystem::pluginversion(basename(__DIR__));
    }
    return array(
        '_hinweis'      => mi_t('UI.SICH_KOPF'),
        '_plugin'       => 'Midea2Lox',
        '_fassung'      => $fassung,
        '_stand'        => date('Y-m-d H:i:s'),
        'einstellungen' => mi_ansage_sicherung_felder($voll),     // nie ein Sprechtoken
        'geraete'       => $geraete,
    );
}

/** Sieht der Wert wie ein Geraetetoken aus? 128 Hexziffern, nichts sonst. */
function mi_ist_token($v)
{
    return is_string($v) && preg_match('/^[0-9A-Fa-f]{128}$/', $v) === 1;
}

/** Sieht der Wert wie ein Geraeteschluessel aus? 64 Hexziffern. */
function mi_ist_key($v)
{
    return is_string($v) && preg_match('/^[0-9A-Fa-f]{64}$/', $v) === 1;
}

/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Die sieben Punkte aus REGELN_2, und der wichtigste ist der dritte: eine
 * halb gueltige Datei ueberschreibt GAR NICHTS. Wer eine Sicherung
 * zurueckspielt, will entweder den ganzen Stand oder gar keinen - eine zur
 * Haelfte uebernommene Konfiguration ist schlimmer als die alte, und man
 * sieht es ihr nicht an.
 *
 * Geprueft wird seit 4.3.0 auch JEDER WERT, nicht nur der Schluessel. Bis
 * dahin ging eine Sicherung mit UDP_PORT 99999999 anstandslos durch, und ein
 * Feld statt einer Zeichenkette landete als "UDP_PORT=Array" in der Datei -
 * der Dienst startete danach nicht mehr.
 *
 * Die alte, flache Form (nur Einstellungen, ohne Kopf) wird weiterhin
 * angenommen: eine Sicherung aus 4.2.x soll sich einspielen lassen.
 *
 * Rueckgabe: array(array(einstellungen, geraete)|null, Beanstandungen[],
 *                  Zahl uebernommener Werte, Zahl uebernommener Geraete).
 */
function mi_sicherung_lesen($roh, &$namen = null)
{
    $mangel = array();
    // X-3 (Verbesserungsbau 30.09.2026): die Namen der beanstandeten
    // Schluessel, nie ihre Werte.
    $namen = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(mi_t('UI.SICH_KEIN_JSON')), 0, 0);
    }

    // Form erkennen: neu (mit Abschnitt "einstellungen") oder alt (flach).
    if (array_key_exists('einstellungen', $daten)) {
        if (!is_array($daten['einstellungen'])) {
            return array(null, array(mi_t('UI.SICH_KEIN_JSON')), 0, 0);
        }
        $roh_einst = $daten['einstellungen'];
        $roh_ger = isset($daten['geraete']) ? $daten['geraete'] : array();
        if (!is_array($roh_ger)) {
            $mangel[] = mi_t('UI.SICH_GERAETE_FORM');
            $roh_ger = array();
        }
        // Ein fremdes Plugin, das zufaellig "einstellungen" schreibt, wird
        // am Namen erkannt - nicht durchgelassen und nicht stillschweigend
        // uebernommen.
        if (isset($daten['_plugin']) && is_string($daten['_plugin'])
            && $daten['_plugin'] !== '' && $daten['_plugin'] !== 'Midea2Lox') {
            $mangel[] = sprintf(mi_t('UI.SICH_FREMDES_PLUGIN'),
                                 mi_e((string) $daten['_plugin']));
        }
    } else {
        $roh_einst = $daten;
        $roh_ger = array();
    }
    /* Die Sprachansagen: Token und Vermerk bleiben die geltenden, das Feld selbst kommt nie aus einer Datei. */
    $mi_e2 = json_decode(mi_ansage_sicherung_vor((string) json_encode($roh_einst)), true);
    if (is_array($mi_e2)) { $roh_einst = $mi_e2; }
    $mi_ab = array();

    /* Die Grundlage ist die AKTUELLE Konfiguration, nicht die Werksvorgabe.
     *
     * Bis 4.3.2 stand hier mi_vorgaben(). Eine Sicherung, die einen
     * Schluessel nicht enthaelt - jede flache Sicherung aus 4.2.x tut das,
     * und die soll ausdruecklich weiter angenommen werden -, setzte diesen
     * Wert damit stillschweigend auf die Werksvorgabe zurueck. Gemessen mit
     * {"einstellungen":{"UDP_PORT":"7014"}}: angenommen, und danach war das
     * hinterlegte Midea-Kennwort leer, ohne dass ein Wort darueber fiel.
     *
     * Zurueckgespielt wird jetzt, was in der Datei steht; was nicht darin
     * steht, bleibt unveraendert. WELCHE Schluessel unberuehrt blieben,
     * gibt diese Funktion als fuenften Rueckgabewert heraus, und die
     * Oberflaeche sagt es hinterher - unveraendert lassen ist richtig,
     * unveraendert lassen OHNE es zu sagen waere die naechste stille
     * Ueberraschung. */
    $neu = mi_config_read();
    $bekannt = array_keys(mi_vorgaben());
    $gesehen = array();
    $anzahl = 0;
    foreach ($roh_einst as $k => $w) {
        if ((string) $k === 'ansage_block') { continue; }     // das Feld mit den Sprechtoken kommt nie aus einer Datei
        $mi_aw = mi_ansage_wert($k, $w);
        if ($mi_aw !== null) {
            if ($mi_aw) { $mi_ab[$k] = $w; } else { $mangel[] = mi_t('ANSAGEPLUGIN.M_FEHL'); $namen[] = (string) $k; }
            continue;
        }
        // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(mi_t('UI.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            $namen[] = (string) $k;
            continue;
        }
        $fehler = mi_wert_pruefen($k, $w);
        if ($fehler !== '') {
            $mangel[] = mi_e($k) . ': ' . $fehler;
            $namen[] = (string) $k;
            continue;
        }
        // b1: das Kennwort woertlich - Leerraum am Rand gehoert seit dem
        // Verbesserungsbau 30.09.2026 dazu (mi_kennwort_kodieren).
        $neu[$k] = ($k === 'MideaPassword') ? (string) $w : trim((string) $w);
        $gesehen[] = $k;
        $anzahl++;
    }
    $unberuehrt = array_values(array_diff($bekannt, $gesehen));
    if ($anzahl === 0) {
        $mangel[] = mi_t('UI.SICH_LEER');
    }

    // Geraete - je Feld eine Positivliste. Ein Token, das nicht wie eines
    // aussieht, ist eine Beanstandung: er geht spaeter an das Geraet, und
    // eine geratene Zeichenkette dort ist dasselbe wie eine geratene
    // Registeradresse.
    $geraete = array();
    $ids_gesehen = array();
    $mi_gzahl = count($mangel);
    $mi_gnr = 0;
    foreach ($roh_ger as $i => $g) {
        if (count($mangel) > $mi_gzahl) {
            $namen[] = 'geraete[' . $mi_gnr . ']';
        }
        $mi_gzahl = count($mangel);
        $nr = (int) $i + 1;
        $mi_gnr = $nr;
        if (!is_array($g)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_FORM'), $nr);
            continue;
        }
        /* C8 (ab 4.5.10): JEDES Feld muss eine Zeichenkette sein (Nummer und
         * Port duerfen auch eine ganze Zahl sein), BEVOR es umgewandelt wird.
         * Bis 4.5.9 machte (string) aus einer Liste "Array": Bezeichnung und
         * Typ wurden als "Array" uebernommen, samt PHP-Meldung "Array to
         * string conversion" (gemessen unter 7.4, 8.4 und 8.5, Bericht code
         * Befund 8; Fehlerklasse 10 des Auftrags). */
        $mi_falsch = '';
        foreach (array('id', 'typ', 'ip', 'port', 'bezeichnung', 'token', 'key') as $mi_feld) {
            if (!isset($g[$mi_feld]) || is_string($g[$mi_feld])) {
                continue;
            }
            if (($mi_feld === 'id' || $mi_feld === 'port') && is_int($g[$mi_feld])) {
                continue;
            }
            $mi_falsch = $mi_feld;
            break;
        }
        if ($mi_falsch !== '') {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_FELDTYP'), $nr, mi_e($mi_falsch));
            continue;
        }
        $id = isset($g['id']) ? trim((string) $g['id']) : '';
        // 10 bis 19 Ziffern - dieselbe Grenze wie im Dienst, siehe
        // index.php bei den Bezeichnungen.
        if (!preg_match('/^\d{10,19}$/', $id)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_ID'), $nr);
            continue;
        }
        /* C7 (ab 4.5.10): eine Geraetenummer nur einmal. Bis 4.5.9 schrieb
         * mi_devices_write() sie zweimal als [Midea_<id>] - Pythons
         * configparser wirft darauf DuplicateSectionError, und danach waren
         * ALLE Geraete stumm, waehrend die Oberflaeche beide anzeigte
         * (gemessen, Bericht code Befund 7). Die ganze Datei gilt dann nicht. */
        if (isset($ids_gesehen[$id])) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_DOPPELT'), $nr, mi_e($id), $ids_gesehen[$id]);
            continue;
        }
        $ids_gesehen[$id] = $nr;
        $ip = isset($g['ip']) ? trim((string) $g['ip']) : '';
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_IP'), $nr);
            continue;
        }
        $port = isset($g['port']) ? trim((string) $g['port']) : '6444';
        if ($port === '') { $port = '6444'; }
        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_PORT'), $nr);
            continue;
        }
        $token = isset($g['token']) ? trim((string) $g['token']) : '';
        $key   = isset($g['key'])   ? trim((string) $g['key'])   : '';
        if ($token !== '' && !mi_ist_token($token)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_TOKEN'), $nr);
            continue;
        }
        if ($key !== '' && !mi_ist_key($key)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_KEY'), $nr);
            continue;
        }
        // Ein Geraet mit nur einer Haelfte des Paares kann sich nicht
        // anmelden. Melden statt zurechtbiegen.
        if (($token === '') !== ($key === '')) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_PAAR'), $nr);
            continue;
        }
        $bez = isset($g['bezeichnung']) ? trim((string) $g['bezeichnung']) : '';
        if (!mi_wert_taugt($bez) || strlen($bez) > 120) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_BEZ'), $nr);
            continue;
        }
        $typ = isset($g['typ']) ? trim((string) $g['typ']) : '';
        if (!preg_match('/^[A-Za-z0-9 _.\-]{0,40}$/', $typ)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_TYP'), $nr);
            continue;
        }
        $geraete[] = array('id' => $id, 'typ' => $typ, 'ip' => $ip,
                           'port' => $port, 'bezeichnung' => $bez,
                           'token' => $token, 'key' => $key);
    }
    if (count($mangel) > $mi_gzahl) {
        $namen[] = 'geraete[' . $mi_gnr . ']';
    }

    if ($mangel) {
        return array(null, $mangel, $anzahl, count($geraete), $unberuehrt);
    }
    // Fuenftes Element: die Schluessel, die in der Datei NICHT standen und
    // deshalb unveraendert geblieben sind. Aeltere Aufrufer, die nur vier
    // Elemente auslesen, stoert das nicht - list() nimmt weniger entgegen,
    // als das Feld hergibt.
    if ($mi_ab) { $neu['ansage_block'] = base64_encode((string) json_encode($mi_ab)); }     // die Felder der Sprachansagen zurueck in das eine Feld
    return array(array($neu, $geraete), array(), $anzahl, count($geraete), $unberuehrt);
}

/**
 * X-3 (Verbesserungsbau 30.09.2026): Welche gespeicherten Werte bestuenden
 * das eigene Zurueckspielen nicht? Die Sicherung wird gebaut und durch
 * mi_sicherung_lesen() geschickt - dieselbe Pruefung wie beim Zurueckspielen.
 * Rueckgabe: Liste der NAMEN (Schluessel bzw. geraete[<Nr>]), nie der Werte;
 * leer = die Sicherung liesse sich zurueckspielen; '?' = abgewiesen ohne
 * einen benennbaren Schluessel.
 * Der Name traegt bewusst kein "sicherung": Werkzeuge/sicherung_pruefen.py
 * nimmt die erste Funktion *_sicherung* fuer die Ausfuhr (so in Heimkino
 * gemessen, Scheinbefund).
 */
function mi_rueckspiel_altwerte($sicherung = null)
{
    $s = is_array($sicherung) ? $sicherung : mi_sicherung_bauen();
    $js = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                          | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($js === false) {
        // Nicht kodierbar: der Knopf meldet das selbst (UI.SICH_DL_FEHLER).
        return array();
    }
    $namen = array();
    $erg = mi_sicherung_lesen($js, $namen);
    if ($erg[0] !== null) {
        return array();
    }
    return $namen ? array_values(array_unique($namen)) : array('?');
}

/**
 * devices.cfg aus einer zurueckgespielten Sicherung schreiben.
 *
 * Ersetzt, haengt nicht an: eine Sicherung ist ein Stand, kein Zusatz. Wird
 * keine einzige Geraetezeile mitgebracht, bleibt die vorhandene Datei
 * unangetastet - eine Sicherung aus 4.2.x kennt den Abschnitt gar nicht, und
 * die vorhandenen Geraete deswegen zu loeschen waere das Gegenteil von
 * hilfreich.
 *
 * Rechte VOR dem Inhalt: in der Datei stehen Token und Schluessel.
 */
function mi_devices_write(array $geraete)
{
    if (!$geraete) {
        return true;
    }
    $datei = mi_paths()['devices'];
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0775, true);
    }
    $z = "# Gefundene Klimageraete. Wird von \"Geraete suchen\" (discover.py) gefuellt\n"
       . "# und vom Dienst midea2lox.py gelesen.\n"
       . "#\n"
       . "# Diese Fassung stammt aus einer zurueckgespielten Sicherung vom "
       . date('d.m.Y H:i') . ".\n\n";
    foreach ($geraete as $g) {
        $z .= '[Midea_' . $g['id'] . "]\n";
        if ($g['typ'] !== '')         { $z .= 'type = ' . $g['typ'] . "\n"; }
        $z .= 'id = ' . $g['id'] . "\n";
        if ($g['ip'] !== '')          { $z .= 'ip = ' . $g['ip'] . "\n"; }
        $z .= 'port = ' . $g['port'] . "\n";
        if ($g['bezeichnung'] !== '') { $z .= 'bezeichnung = ' . $g['bezeichnung'] . "\n"; }
        if ($g['token'] !== '')       { $z .= 'token = ' . $g['token'] . "\n"; }
        if ($g['key'] !== '')         { $z .= 'key = ' . $g['key'] . "\n"; }
        $z .= "\n";
    }
    // Seit 4.5.10 ueber mi_datei_schreiben() (C9).
    return mi_datei_schreiben($datei, $z, 0600);
}

/* ==================================================================
 * Einmalmeldung nach der Umleitung (ab 4.5.10, O1)
 *
 * Jeder POST endet seit 4.5.10 mit einer Umleitung 303 auf
 * index.php?form=<reiter> (Regeln/04). Bis 4.5.9 wurde die Seite unmittelbar
 * nach dem POST gerendert, und F5 wiederholte Speichern, Dienst-Neustart,
 * Anhalten, Zurueckspielen, Geraetesuche und "Befehl senden" (gemessen:
 * zweimal HTTP 200, zweimal geschrieben - Bericht Oberflaeche Befund 1).
 * Das Ergebnis reist in data/plugins/<ordner>/einmalmeldung.json (0600),
 * gelesen und geloescht NUR beim GET, verworfen nach 120 s (Regeln/04,
 * Nachtrag Raumklima 0.11.8). Keine Zugangsdaten: Geraetetoken und
 * -schluessel (128 bzw. 64 Hexziffern), wie sie die Geraetesuche ausgeben
 * kann, werden vor dem Ablegen unkenntlich gemacht.
 * ================================================================== */
function mi_einmal_ablegen(array $daten)
{
    $daten['zeit'] = time();
    array_walk_recursive($daten, function (&$w) {
        if (is_string($w)) {
            $w = preg_replace('/(?<![0-9A-Fa-f])(?:[0-9A-Fa-f]{64}){1,2}(?![0-9A-Fa-f])/', '***', $w);
        }
    });
    $js = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_INVALID_UTF8_SUBSTITUTE);
    return $js !== false && mi_datei_schreiben(mi_paths()['einmal'], $js, 0600);
}

function mi_einmal_abholen()
{
    $f = mi_paths()['einmal'];
    clearstatcache(true, $f);
    if (!is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    return $d;
}

/* ==================================================================
 * X-2 (Verbesserungsbau 30.09.2026): nach einer Beanstandung stehen die
 * eingetippten Werte wieder im Formular (Regeln/04).
 *
 * Seit der Umleitung nach jedem POST (O1) zeigte der GET die GESPEICHERTEN
 * Werte; wer drei Felder richtig und eines falsch eingab, tippte alle vier
 * neu. Jetzt reisen die Eingaben des einen beanstandeten Formulars mit der
 * Einmalmeldung (0600, Datenordner, 120 s, beim GET gelesen und geloescht).
 * Nie Geheimnisse: das Midea-Kennwort steht in keiner Feldliste unten.
 * ================================================================== */
function mi_x2_formulare()
{
    return array(
        'settings'  => array('MINISERVER', 'UDP_PORT', 'lox_timeout', 'MideaUser', 'region',
                             'abfragetakt', 'maxConnectionLifetime', 'DEBUG'),
        'mqtt'      => array('mqtt_praefix', 'befehle_mqtt_ein', 'befehle_eingreifend_ein'),
        'automatik' => array('auto_ein', 'auto_thema_regel', 'auto_thema_pv', 'auto_pv_ab',
                             'auto_verschiebung', 'auto_soll_min', 'auto_soll_max',
                             'auto_turbo', 'auto_schalten', 'auto_sperrzeit',
                             'auto_max_alter', 'auto_takt', 'auto_geraete',
                             'fenster_ein', 'fenster_frist', 'fenster_zuordnung',
                             'anwesenheit_ein', 'anwesenheit_verhalten', 'anwesenheit_verschiebung',
                             'anwesenheit_frist', 'anwesenheit_geraete'),
        // Die Bezeichnungen reisen unter "bezeichnung" (je Geraetenummer).
        'geraete'   => array(),
    );
}

/** Die Eingaben des beanstandeten Formulars aus $_POST - nur Zeichenketten. */
function mi_x2_eingaben($form, array $falsch)
{
    $liste = mi_x2_formulare();
    if (!isset($liste[$form])) {
        return null;
    }
    $werte = array();
    foreach ($liste[$form] as $k) {
        if (isset($_POST[$k]) && is_string($_POST[$k]) && mi_wert_taugt($_POST[$k])) {
            $werte[$k] = $_POST[$k];
        }
    }
    $bez = array();
    if ($form === 'geraete' && isset($_POST['bezeichnung']) && is_array($_POST['bezeichnung'])) {
        foreach ($_POST['bezeichnung'] as $id => $w) {
            if (is_string($w) && preg_match('/^\d{10,19}\z/', (string) $id) && mi_wert_taugt($w)) {
                $bez[(string) $id] = $w;
            }
        }
    }
    return array('form' => $form, 'werte' => $werte, 'bezeichnung' => $bez,
                 'falsch' => array_values(array_unique(array_filter($falsch, 'is_string'))));
}

/** Die mitgereisten Eingaben pruefen, bevor sie ein Formular fuellen. */
function mi_x2_pruefen($roh)
{
    $liste = mi_x2_formulare();
    if (!is_array($roh) || !isset($roh['form']) || !is_string($roh['form'])
        || !isset($liste[$roh['form']])) {
        return null;
    }
    $aus = array('form' => $roh['form'], 'werte' => array(), 'bezeichnung' => array(),
                 'falsch' => array());
    foreach ((isset($roh['werte']) && is_array($roh['werte'])) ? $roh['werte'] : array() as $k => $w) {
        if (in_array($k, $liste[$roh['form']], true) && is_string($w)) {
            $aus['werte'][$k] = $w;
        }
    }
    foreach ((isset($roh['bezeichnung']) && is_array($roh['bezeichnung'])) ? $roh['bezeichnung'] : array() as $k => $w) {
        if (preg_match('/^\d{10,19}\z/', (string) $k) && is_string($w)) {
            $aus['bezeichnung'][(string) $k] = $w;
        }
    }
    foreach ((isset($roh['falsch']) && is_array($roh['falsch'])) ? $roh['falsch'] : array() as $f) {
        if (is_string($f) && preg_match('/^[A-Za-z_]{1,40}(\[\d{1,19}\])?\z/', $f)) {
            $aus['falsch'][] = $f;
        }
    }
    return $aus;
}

/** Die Konfiguration fuer DIESES Formular mit den Eingaben ueberlagern. */
function mi_x2_ueberlagern($cfg, $x2, $form)
{
    if (is_array($x2) && $x2['form'] === $form) {
        foreach ($x2['werte'] as $k => $w) {
            $cfg[$k] = $w;
        }
    }
    return $cfg;
}

/** Die mitgereiste Bezeichnung eines Geraets - sonst die gespeicherte. */
function mi_x2_bezeichnung($x2, array $d)
{
    $id = (string) $d['id'];
    if (is_array($x2) && $x2['form'] === 'geraete' && isset($x2['bezeichnung'][$id])) {
        return $x2['bezeichnung'][$id];
    }
    return (string) $d['bezeichnung'];
}

/**
 * Die beanstandeten Felder markieren und sagen, dass das Formular die
 * Eingabe zeigt. Ueber Attributwaehler - die Feldnamen stammen aus der
 * festen Liste oben bzw. sind "bezeichnung[<Ziffern>]" (mi_x2_pruefen).
 */
function mi_x2_markierung($x2, $form, $reiter)
{
    if (!is_array($x2) || $x2['form'] !== $form) {
        return '';
    }
    $aus = '<div class="sm-alert sm-info">' . mi_t('UI.X2_EINGABEN') . '</div>' . "\n";
    if ($x2['falsch']) {
        $w = array();
        foreach ($x2['falsch'] as $f) {
            $w[] = '#' . $reiter . ' [name="' . $f . '"]';
        }
        $aus .= '<style>' . implode(', ', $w)
              . ' { outline: 2px solid #c62828; background: #fdecea; }</style>' . "\n";
        /* B-Nachzug 01.10.2026 (Nr. 16/19): dazu Klasse und aria-invalid,
         * damit auch ein Bildschirmleser das Feld als beanstandet nennt.
         * Die Waehler sind dieselben wie oben (Namen aus mi_x2_pruefen). */
        $aus .= '<script>document.addEventListener("DOMContentLoaded", function () {'
              . ' var l = document.querySelectorAll('
              . json_encode(implode(', ', $w), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
              . '); for (var i = 0; i < l.length; i++) {'
              . ' l[i].classList.add("sm-beanstandet"); l[i].setAttribute("aria-invalid", "true"); }'
              . ' });</script>' . "\n";
    }
    return $aus;
}

/* ==================================================================
 * Die Praefixe, unter denen die Linie gesendet hat (ab 4.5.10, M1)
 *
 * Aendert der Anwender das Themenpraefix (Reiter MQTT oder Zurueckspielen),
 * wird das bisherige hier vermerkt. Der Dienst raeumt nach dem Verbinden
 * jedes gemerkte Praefix ausser seinem eigenen am Broker ab - ueber TCP, mit
 * Nachlesen (data/mi_mqtt.py, praefixe_abraeumen) - und die Deinstallation
 * alle. Dieselbe Datei und dasselbe Format wie im Dienst: eine Zeile je
 * Praefix, neben dem Konfigordner.
 * ================================================================== */
function mi_praefix_merken($praefix)
{
    $datei = mi_paths()['praefixe'];
    $liste = array();
    if (is_readable($datei)) {
        foreach (preg_split('/\R/', (string) @file_get_contents($datei)) as $z) {
            $z = trim($z, " \t/");
            if ($z !== '' && mi_wert_pruefen('mqtt_praefix', $z) === ''
                && !in_array($z, $liste, true)) {
                $liste[] = $z;
            }
        }
    }
    if (in_array($praefix, $liste, true)) {
        return true;
    }
    $liste[] = $praefix;
    return mi_datei_schreiben($datei, implode("\n", $liste) . "\n", 0644);
}

/* ==================================================================
 * Gruppe 15 (4.5.18): Fehlwerte, Namenstabelle, MQTT-Befehle, Anwesenheit
 * (Entwurf Gruppe 15 Abschnitte 2.3-2.5, 3.2-3.8, 7)
 * ================================================================== */

/** Der Fehlwert eines Geraetethemas - dieselbe Regel wie mi_mqtt.fehlwert_fuer() im Dienst. */
function mi_fehlwert($name)
{
    if (in_array($name, array('target_temperature', 'indoor_temperature', 'outdoor_temperature'), true)) {
        return '-999';
    }
    if (in_array($name, array('operational_mode', 'fan_speed', 'swing_mode', 'horizontal_swing_angle',
                              'vertical_swing_angle', 'rate_select', 'breeze_mode'), true)) {
        return '-';
    }
    return '-1';
}

/**
 * Fuehren Dienst (mi_mqtt.FEHLWERT) und Oberflaeche (mi_fehlwert) dieselben Fehlwerte? Und traegt die
 * Spalte MinVal von mi_werte() jeden Zahlen-Fehlwert? Bauart mi_retain_probe(): die Python-Tabelle wird bis
 * zur ersten schliessenden Klammer gelesen. Rueckgabe array(ok|null, Zahl der Themen, Abweichungen).
 */
function mi_fehlwert_probe()
{
    $datei = mi_paths()['mqtt_py'];
    if (!is_readable($datei)) {
        return array(null, 0, array());
    }
    $q = (string) @file_get_contents($datei);
    if (!preg_match('/FEHLWERT\s*=\s*\((.*?)\)/s', $q, $m)) {
        return array(null, 0, array());
    }
    preg_match_all("/'([a-z_]+)=(-999|-1|-)'/", $m[1], $t, PREG_SET_ORDER);
    $py = array();
    foreach ($t as $z) { $py[$z[1]] = $z[2]; }
    $ab = array();
    $n = 0;
    foreach (mi_werte() as $name => $w) {
        $n++;
        $dienst = isset($py[$name]) ? $py[$name] : '-1';
        if ($dienst !== mi_fehlwert($name)) {
            $ab[] = $name . ' (' . $dienst . '/' . mi_fehlwert($name) . ')';
            continue;
        }
        if ($name !== 'online' && $w[3] !== null && $dienst !== '-'
            && (float) $w[3][1] > (float) $dienst) {
            $ab[] = $name . ' MinVal ' . $w[3][1];
        }
    }
    foreach (array_keys($py) as $k) {
        if (!array_key_exists($k, mi_werte())) { $ab[] = $k . ' (nur im Dienst)'; }
    }
    return array($n > 0 && count($py) > 0 && !$ab, $n, $ab);
}

/**
 * Die Tabelle der MQTT-Befehle aus dem Dienst (data/mi_mqtt.py, MQTT_BEFEHLE) - die EINE Quelle; die
 * Oberflaeche liest sie zeilenweise (Bauart mi_retain_probe). Rueckgabe array(name => array('art', 'werte'
 * => Liste, 'karte' => array(Wert => UDP-Wort), 'eingreifend' => bool)) oder null, wenn nicht lesbar.
 */
function mi_mqtt_befehle_python()
{
    static $tab = false;
    if ($tab !== false) {
        return $tab;
    }
    $tab = null;
    $datei = mi_paths()['mqtt_py'];
    if (!is_readable($datei)) {
        return $tab;
    }
    $q = (string) @file_get_contents($datei);
    if (!preg_match('/MQTT_BEFEHLE\s*=\s*\((.*?)\n\)/s', $q, $m)) {
        return $tab;
    }
    preg_match_all("/^\s*'([^']+)',\s*$/m", $m[1], $z);
    $aus = array();
    foreach ($z[1] as $zeile) {
        $f = explode('|', $zeile);
        if (count($f) !== 5) { continue; }
        $karte = array();
        foreach (explode(' ', $f[3]) as $paar) {
            $p = explode('=', $paar, 2);
            if (count($p) === 2) { $karte[$p[0]] = $p[1]; }
        }
        $aus[$f[0]] = array('art' => $f[1], 'werte' => explode(',', $f[2]), 'karte' => $karte,
                            'eingreifend' => $f[4] === '1');
    }
    $tab = $aus ? $aus : null;
    return $tab;
}

/** Den festen deutschen Titel zu einem UDP-Wort aus mi_befehle() - fuer Schalter das Paar EIN/AUS. */
function mi_mqtt_titel_zu($ein, $aus = null)
{
    foreach (mi_befehle() as $b) {
        $vergleich = $b[1];
        if (strpos($ein, '<v>') !== false) {
            // temp.<v> gegen temp.<v.1>: gleich bis zum Platzhalter; "stufenlos" gehoert zur UDP-Vorlage.
            $a = substr($ein, 0, strpos($ein, '<'));
            if (strpos($vergleich, '<v') !== false && substr($vergleich, 0, strpos($vergleich, '<')) === $a) {
                return array(str_replace(' stufenlos', '', $b[3]), $b[0]);
            }
            continue;
        }
        if ($vergleich === $ein && ($aus === null || $b[2] === $aus)) {
            return array($b[3], $b[0]);
        }
    }
    return array('', '');
}

/**
 * Die Befehle fuer Loxone (Tabelle Schritt 4 und Vorlage MQTT-Befehle): je Schalter EIN Befehl mit EIN/AUS,
 * je Auswahlwert ein Befehl, Puls einer, Zahl analog. Titel fest deutsch aus mi_befehle() (Sprachregel
 * 13.10.2026), im Musterprojekt "Midea2Lox: <Titel>". Je Zeile: name, art, ein, aus (oder ''), titel,
 * text (Sprachschluessel), eingreifend, udp (UDP-Wort bzw. Paar).
 */
function mi_mqtt_befehle()
{
    $py = mi_mqtt_befehle_python();
    if (!is_array($py)) {
        return array();
    }
    $aus = array();
    foreach ($py as $name => $b) {
        if ($b['art'] === 'schalter') {
            list($t, $k) = mi_mqtt_titel_zu($b['karte']['1'], $b['karte']['0']);
            $aus[] = array('name' => $name, 'art' => 'schalter', 'ein' => '1', 'aus' => '0', 'titel' => $t,
                           'text' => $k, 'eingreifend' => $b['eingreifend'],
                           'udp' => $b['karte']['1'] . ' / ' . $b['karte']['0']);
        } elseif ($b['art'] === 'auswahl') {
            foreach ($b['werte'] as $w) {
                $u = isset($b['karte'][$w]) ? $b['karte'][$w] : '';
                list($t, $k) = mi_mqtt_titel_zu($u);
                $aus[] = array('name' => $name, 'art' => 'auswahl', 'ein' => $w, 'aus' => '', 'titel' => $t,
                               'text' => $k, 'eingreifend' => $b['eingreifend'], 'udp' => $u);
            }
        } elseif ($b['art'] === 'puls') {
            list($t, $k) = mi_mqtt_titel_zu($b['karte']['1']);
            $aus[] = array('name' => $name, 'art' => 'puls', 'ein' => '1', 'aus' => '', 'titel' => $t,
                           'text' => $k, 'eingreifend' => $b['eingreifend'], 'udp' => $b['karte']['1']);
        } else {
            $u = isset($b['karte']['<v>']) ? $b['karte']['<v>'] : '';
            list($t, $k) = mi_mqtt_titel_zu($u);
            $aus[] = array('name' => $name, 'art' => $b['art'],
                           'ein' => $b['art'] === 'zahl' ? '<v.1>' : '<v.0>', 'aus' => '', 'titel' => $t,
                           'text' => $k, 'eingreifend' => $b['eingreifend'], 'udp' => $u);
        }
    }
    return $aus;
}

/**
 * Pruefzeile "Zuordnung MQTT = UDP" (Entwurf 3.6): jede Abbildung der Python-Tabelle steht in der
 * Positivliste von mi_befehle() und bekommt einen Titel; jeder Befehl von mi_befehle() ist ueber MQTT
 * erreichbar. Rueckgabe array(ok|null, Zahl der MQTT-Befehle, Abweichungen).
 */
function mi_mqtt_zuordnung_probe()
{
    $py = mi_mqtt_befehle_python();
    if (!is_array($py)) {
        return array(null, 0, array());
    }
    $ab = array();
    $erreicht = array();
    foreach (mi_mqtt_befehle() as $z) {
        if ($z['titel'] === '') { $ab[] = $z['name'] . ' ' . $z['ein'] . ' (' . $z['udp'] . ')'; }
        foreach (explode(' / ', $z['udp']) as $u) { $erreicht[] = $u; }
    }
    foreach (mi_befehle() as $b) {
        foreach (array($b[1], $b[2]) as $u) {
            if ($u === null) { continue; }
            $u2 = preg_replace('/<v\.[0-9]>/', '<v>', $u);
            if (!in_array($u2, $erreicht, true)) { $ab[] = $u . ' (nicht ueber MQTT)'; }
        }
    }
    return array(!$ab, count($py), array_values(array_unique($ab)));
}

/** Der UDP-Eingang des MQTT-Gateways (general.json Mqtt.Udpinport), sonst 11884. */
function mi_udp_eingang()
{
    $h = mi_paths()['home'];
    $g = $h !== '' ? @json_decode((string) @file_get_contents($h . '/config/system/general.json'), true) : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : array();
    $port = isset($m['Udpinport']) && is_numeric($m['Udpinport']) ? (int) $m['Udpinport'] : 0;
    return ($port >= 1 && $port <= 65535) ? $port : 11884;
}

/**
 * Vorlage "MQTT-Befehle" (Entwurf 3.8): Virtueller Ausgang "Midea2Lox MQTT-Befehle" an
 * /dev/udp/<LoxBerry>/<UDP-Eingang des Gateways>, je Geraet und Befehl ein VirtualOutCmd mit
 * "publish <praefix>/befehl/<id>/<name> <wert>" (nie retain). Titel "Midea2Lox <id>: <Titel>" - je Geraet
 * eindeutig; kein Titel gleicht einem Gateway-Namen (Doppelpunkt und Leerzeichen). Rueckgabe
 * array(Dateiname, Inhalt).
 */
function mi_vorlage_mqtt_out($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $p = mi_mqtt_topic($cfg);
    $ip = mi_cfg($cfg, 'LoxberryIP', mi_localip());
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut HintText="" Title="Midea2Lox MQTT-Befehle" Comment="' . $x(mi_t('UI.VQ_MQTT_KOMMENTAR'))
        . '" Address="' . $x('/dev/udp/' . $ip . '/' . mi_udp_eingang())
        . '" CmdInit="" CloseAfterSend="true" CmdSep=";">' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    $geraete = mi_devices();
    foreach ($geraete as $d) {
        if (!isset($d['id']) || $d['id'] === '') { continue; }
        foreach (mi_mqtt_befehle() as $b) {
            $thema = $p . '/befehl/' . $d['id'] . '/' . $b['name'];
            $an = 'publish ' . $thema . ' ' . $b['ein'];
            $ab = $b['aus'] !== '' ? 'publish ' . $thema . ' ' . $b['aus'] : '';
            $titel = 'Midea2Lox ' . $d['id'] . ': ' . $b['titel'];
            $analog = ($b['art'] === 'zahl' || $b['art'] === 'ganz') ? 'true' : 'false';
            $o .= "\t" . '<VirtualOutCmd Title="' . $x($titel) . '" Comment="' . $x($b['titel'])
                . '" CmdOnMethod="GET" CmdOffMethod="GET" CmdOn="' . $x($an) . '" CmdOnHTTP="" CmdOnPost="" '
                . 'CmdOff="' . $x($ab) . '" CmdOffHTTP="" CmdOffPost="" CmdAnswer="" Analog="' . $analog
                . '" Repeat="0" RepeatRate="0" HintText=""/>' . $crlf;
        }
    }
    $o .= '</VirtualOut>' . $crlf;
    return array('VQ_midea2lox_mqtt.xml', $o);
}

/**
 * Die V2-Hakenliste des Musterprojekts (Entwurf 2.4, F-E7 mit F-E14: 30 Haken). Schluessel ist das Thema
 * mit <id> fuer die Geraetenummer; Wert true = Noncached.
 */
function mi_v2_haken()
{
    return array(
        'status/zaehler' => false, 'status/ok' => true, '<id>/online' => true, '<id>/power_state' => false,
        '<id>/indoor_temperature' => false, '<id>/outdoor_temperature' => false,
        '<id>/target_temperature' => false, '<id>/indoor_humidity' => false, 'automatik/aktiv' => false,
        'automatik/gesperrt' => false, 'automatik/geraete' => false, 'automatik/grund' => false,
        'anwesenheit/aktiv' => false,
        '<id>/target_humidity' => false, '<id>/operational_mode' => false, '<id>/fan_speed' => false,
        '<id>/swing_mode' => false,
        '<id>/eco_mode' => false, '<id>/turbo_mode' => false, '<id>/sleep_mode' => false,
        '<id>/freeze_protection_mode' => false, '<id>/purifier' => false, '<id>/follow_me' => false,
        '<id>/display_on' => false, '<id>/self_clean_active' => false, '<id>/filter_alert' => false,
        '<id>/real_time_power_usage' => false, '<id>/total_energy_usage' => false,
        '<id>/current_energy_usage' => false, '<id>/rate_select' => false,
    );
}

/**
 * Die Namenstabelle (Entwurf 2.4): je Thema der Name, den das MQTT-Gateway am Miniserver vergibt
 * (mi_gateway_name - EINE Stelle), Zahl/Text, retained, V2-Kennzeichen, Fehlwert, Bedeutung. Aus DERSELBEN
 * Quelle wie die Thementabellen (mi_werte, mi_status_werte, mi_automatik_werte). Je Geraet (ohne Geraet
 * die Beispielnummer) die Geraetethemen, danach die Themen des Plugins einmal.
 * Je Zeile: thema, name, text (bool), retained, v2 ('' | 'H' | 'N'), fehlwert, s (Sprachschluessel), id.
 */
function mi_mqtt_namen($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $topic = mi_mqtt_topic($cfg);
    $haken = mi_v2_haken();
    $ids = array();
    foreach (mi_devices() as $d) { $ids[] = (string) $d['id']; }
    if (!$ids) { $ids[] = '123456789012'; }
    $aus = array();
    foreach ($ids as $id) {
        foreach (mi_werte() as $wert => $w) {
            $schl = '<id>/' . $wert;
            $aus[] = array('thema' => $id . '/' . $wert, 'name' => mi_gateway_name($topic, $id . '_' . $wert),
                'text' => $w[3] === null, 'retained' => mi_retain($wert),
                'v2' => isset($haken[$schl]) ? ($haken[$schl] ? 'N' : 'H') : '',
                'fehlwert' => $wert === 'online' ? '' : mi_fehlwert($wert), 's' => $w[1], 'id' => $id);
        }
    }
    foreach (array_merge(mi_status_werte(), mi_automatik_werte()) as $thema => $w) {
        $aus[] = array('thema' => $thema, 'name' => mi_gateway_name($topic, $thema),
            'text' => $w[2] === null, 'retained' => mi_retain($thema),
            'v2' => isset($haken[$thema]) ? ($haken[$thema] ? 'N' : 'H') : '',
            'fehlwert' => ($thema === 'status/ok' || $thema === 'status/befehle' || $thema === 'anwesenheit/aktiv')
                          ? '-1' : '', 's' => $w[1], 'id' => '');
    }
    return $aus;
}

/**
 * Pruefzeile Namenstabelle: jeder Name folgt der Gateway-Regel aus seinem Thema, keine Doppelten, und die
 * V2-Hakenliste steht vollstaendig darin (30, davon 2 Noncached). Rueckgabe array(ok, Zahl, Haken, Befunde).
 */
function mi_namen_probe($cfg = null)
{
    $namen = mi_mqtt_namen($cfg);
    $topic = mi_mqtt_topic($cfg);
    $befund = array();
    $gesehen = array();
    $haken = 0;
    $erstes = $namen ? $namen[0]['id'] : '';
    foreach ($namen as $z) {
        if ($z['name'] !== str_replace(array('/', '%'), '_', $topic . '/' . $z['thema'])) { $befund[] = $z['name']; }
        if (isset($gesehen[$z['name']])) { $befund[] = $z['name'] . ' doppelt'; }
        $gesehen[$z['name']] = true;
        if ($z['v2'] !== '' && ($z['id'] === '' || $z['id'] === $erstes)) { $haken++; }
    }
    if ($haken !== count(mi_v2_haken())) { $befund[] = 'V2 ' . $haken . '/' . count(mi_v2_haken()); }
    return array(!$befund, count($namen), $haken, $befund);
}

/** Lage der MQTT-Befehle aus lebenszeichen.json: array(lage, letzte Quittung|null, Alter). */
function mi_befehle_lage($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    if (mi_cfg($cfg, 'befehle_mqtt_ein', '0') !== '1') {
        return array('aus', null, null);
    }
    list($alter, , , $roh) = mi_lebenszeichen();
    if ($alter === null) {
        return array('fehlt', null, null);
    }
    if ($alter > 180) {
        return array('alt', null, $alter);
    }
    $b = isset($roh['befehle']) ? (int) $roh['befehle'] : null;
    $q = (isset($roh['befehl_zuletzt']) && is_array($roh['befehl_zuletzt'])) ? $roh['befehl_zuletzt'] : null;
    if ($b === 1) {
        return array('ok', $q, $alter);
    }
    return array('abgelehnt', $q, $alter);
}

/**
 * Der Stand der Anwesenheit (V1), wie der Dienst ihn in data/anwesenheit.json ablegt:
 * array(lage, daten|null, alter|null); lage aus, fehlt, unlesbar, alt (> 180 s), ok.
 */
function mi_anwesenheit_lage($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    if (mi_cfg($cfg, 'anwesenheit_ein', '0') !== '1') {
        return array('aus', null, null);
    }
    $datei = mi_paths()['anwesenheit'];
    clearstatcache(true, $datei);
    if (!is_readable($datei)) {
        return array('fehlt', null, null);
    }
    $d = json_decode((string) @file_get_contents($datei), true);
    if (!is_array($d) || !isset($d['ts'])) {
        return array('unlesbar', null, null);
    }
    $alter = time() - (int) $d['ts'];
    return array($alter > 180 ? 'alt' : 'ok', $d, $alter);
}
