<?php
/**
 * Midea2Lox - gemeinsame Funktionen der Oberflaeche
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

function mi_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) {
        foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
            if (is_dir($k)) { $home = $k; break; }
        }
    }
    $home = $home ? $home : lb_wurzel_ermitteln();
    // Den Pluginordner aus dem eigenen Ablageort ableiten statt ihn fest
    // einzutragen: er heisst laut plugin.cfg "Midea2Lox" mit grossen
    // Buchstaben, und unter Linux ist das ein Unterschied.
    $ordner = basename(dirname(__FILE__));
    if ($ordner === '' || $ordner === 'htmlauth') {
        $ordner = 'Midea2Lox';
    }
    $p = array(
        'home'    => $home,
        'plugin'  => $ordner,
        'config'  => $home . '/config/plugins/' . $ordner . '/midea2lox.cfg',
        'devices' => $home . '/config/plugins/' . $ordner . '/devices.cfg',
        'abo'     => $home . '/config/plugins/' . $ordner . '/mqtt_subscriptions.cfg',
        'log'     => $home . '/log/plugins/' . $ordner . '/midea2lox.log',
        'datadir'    => $home . '/data/plugins/' . $ordner,
        'leben'   => $home . '/data/plugins/' . $ordner . '/lebenszeichen.json',
        'bin'     => $home . '/bin/plugins/' . $ordner,
        'venv'    => $home . '/bin/plugins/' . $ordner . '/venv/bin/python3',
        'daemon'  => $home . '/system/daemons/plugins/' . $ordner,
        'general' => $home . '/config/system/general.json',
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
    );
    return $p;
}

function mi_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
        $ordner = mi_paths()['home'] . '/templates/plugins/'
                . mi_paths()['plugin'] . '/lang';
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
    // Rechte VOR dem Inhalt.
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $neu) !== false) {
        @chmod($tmp, 0600);
        if (@rename($tmp, $datei)) {
            @chmod($datei, 0600);
        } else {
            @unlink($tmp);
        }
    }
    $wort = $neu;
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
    $ist = isset($_POST['fmt']) ? $_POST['fmt']
         : (isset($_GET['fmt']) ? $_GET['fmt'] : null);
    if (!is_string($ist) || $ist === '' || $soll === '') {
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
    );
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
            return preg_match('/^MINISERVER\d{1,2}$/', $w) ? ''
                 : mi_t('UI.PRUEF_MINISERVER');
        case 'UDP_PORT':
            return (ctype_digit($w) && (int) $w >= 1 && (int) $w <= 65535) ? ''
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
        case 'MideaUser':
        case 'MideaPassword':
            // Freitext. Geprueft wird nur, was mi_wert_taugt() ohnehin
            // prueft; ein Kennwort darf jedes druckbare Zeichen enthalten.
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
    $roh = @parse_ini_file($datei, true, INI_SCANNER_RAW);
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

/** midea2lox.cfg lesen. Aufbau: INI mit dem Abschnitt [default]. */
function mi_config_read()
{
    $cfg = mi_vorgaben();
    $datei = mi_paths()['config'];
    if (is_readable($datei)) {
        $roh = @parse_ini_file($datei, true, INI_SCANNER_RAW);
        if (is_array($roh) && isset($roh['default']) && is_array($roh['default'])) {
            foreach ($cfg as $k => $v) {
                if (isset($roh['default'][$k])) {
                    $cfg[$k] = (string) $roh['default'][$k];
                }
            }
        }
    }
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
    if (!is_file($datei)) {
        return array('fehlt', mi_t('UI.LAGE_FEHLT'));
    }
    if (filesize($datei) === 0) {
        return array('leer', mi_t('UI.LAGE_LEER'));
    }
    $roh = @parse_ini_file($datei, true, INI_SCANNER_RAW);
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
    $z = "[default]\n";
    foreach ($voll as $k => $v) {
        // Zeilenumbrueche wuerden die Datei zerlegen. mi_wert_taugt() weist
        // sie oben schon ab; diese Zeile bleibt als zweite Wache stehen.
        $v = str_replace(array("\r", "\n"), '', (string) $v);
        $z .= $k . '=' . $v . "\n";
    }
    $datei = mi_paths()['config'];
    if (!is_dir(dirname($datei))) {
        @mkdir(dirname($datei), 0775, true);
    }
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $z) === false) {
        return false;
    }
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    @chmod($datei, 0600);   // enthaelt das Midea-Passwort
    return true;
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
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $inhalt) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    @chmod($datei, 0600);
    return true;
}

/** Die ID fuer die Beispiele in den Befehlstabellen. */
function mi_beispiel_id()
{
    $d = mi_devices();
    return $d ? $d[0]['id'] : '123456789';
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
 */
function mi_dienst_pid()
{
    $datei = mi_paths()['datadir'] . '/dienst.pid';
    $roh = is_readable($datei) ? trim((string) @file_get_contents($datei)) : '';
    if ($roh === '' || !ctype_digit($roh)) {
        return null;
    }
    $pid = (int) $roh;
    if ($pid < 2) {
        return null;
    }
    // Gegenprobe argumentweise, nicht als Teilzeichenkette: Prozessnummern
    // werden wiederverwendet, und die Oberflaeche bietet einen Stopp-Knopf.
    $cmd = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($cmd === false) {
        return null;
    }
    $teile = array_slice(array_values(array_filter(explode("\0", $cmd), 'strlen')), 0, 3);
    foreach ($teile as $teil) {
        if (basename($teil) === 'midea2lox.py') {
            return (string) $pid;
        }
    }
    return null;
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
    @exec(escapeshellarg($d) . ' ' . $was . ' >/dev/null 2>&1');
    return '';
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

/** Etwas in der virtuellen Python-Umgebung ausfuehren. */
function mi_python($argumente)
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

    $text = (string) stream_get_contents($rohr[1]) . (string) stream_get_contents($rohr[2]);
    fclose($rohr[1]);
    fclose($rohr[2]);
    $code = proc_close($prozess);
    return array($code, rtrim($text, "\n"));
}

function mi_msmart_version()
{
    list($code, $aus) = mi_python(array('-c', 'import msmart; print(msmart.__version__)'));
    return ($code === 0) ? trim($aus) : '';
}

function mi_python_version()
{
    list($code, $aus) = mi_python(
        array('-c', "import sys; print('%d.%d.%d' % sys.version_info[:3])"));
    return ($code === 0) ? trim($aus) : '';
}

/** Die Fassung von paho-mqtt in der Umgebung - '' heisst nicht ermittelbar. */
function mi_paho_version()
{
    list($code, $aus) = mi_python(
        array('-c', 'import paho.mqtt as p; print(getattr(p, "__version__", ""))'));
    return ($code === 0) ? trim($aus) : '';
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
    return array(
        // ---- Grundwerte ----
        'power_state'            => array($g,      'WERT.POWER_STATE',        'grund',   array('false', '0',   '1',    '<v.0>')),
        'online'                 => array($g,      'WERT.ONLINE',             'grund',   array('false', '0',   '1',    '<v.0>')),
        'indoor_temperature'     => array($c,      'WERT.INDOOR_TEMPERATURE', 'grund',   array('true',  '-50', '80',   '<v.1> °C')),
        'outdoor_temperature'    => array($c,      'WERT.OUTDOOR_TEMPERATURE','grund',   array('true',  '-50', '80',   '<v.1> °C')),
        'target_temperature'     => array($c,      'WERT.TARGET_TEMPERATURE', 'grund',   array('true',  '0',   '40',   '<v.1> °C')),
        'operational_mode'       => array('Text',  'WERT.OPERATIONAL_MODE',   'grund',   null),
        'fan_speed'              => array('Text',  'WERT.FAN_SPEED',          'grund',   null),
        'swing_mode'             => array('Text',  'WERT.SWING_MODE',         'grund',   null),
        // ---- Komfort ----
        'audible_feedback'       => array($g,      'WERT.AUDIBLE_FEEDBACK',   'komfort', array('false', '0',   '1',    '<v.0>')),
        'eco_mode'               => array($g,      'WERT.ECO_MODE',           'komfort', array('false', '0',   '1',    '<v.0>')),
        'turbo_mode'             => array($g,      'WERT.TURBO_MODE',         'komfort', array('false', '0',   '1',    '<v.0>')),
        'display_on'             => array($g,      'WERT.DISPLAY_ON',         'komfort', array('false', '0',   '1',    '<v.0>')),
        'sleep_mode'             => array($g,      'WERT.SLEEP_MODE',         'komfort', array('false', '0',   '1',    '<v.0>')),
        'follow_me'              => array($g,      'WERT.FOLLOW_ME',          'komfort', array('false', '0',   '1',    '<v.0>')),
        'freeze_protection_mode' => array($g,      'WERT.FREEZE_PROTECTION',  'komfort', array('false', '0',   '1',    '<v.0>')),
        'purifier'               => array($g,      'WERT.PURIFIER',           'komfort', array('false', '0',   '1',    '<v.0>')),
        'self_clean_active'      => array($g,      'WERT.SELF_CLEAN',         'komfort', array('false', '0',   '1',    '<v.0>')),
        'filter_alert'           => array($g,      'WERT.FILTER_ALERT',       'komfort', array('false', '0',   '1',    '<v.0>')),
        'target_humidity'        => array('%',     'WERT.TARGET_HUMIDITY',    'komfort', array('false', '0',   '100',  '<v.0> %')),
        'indoor_humidity'        => array('%',     'WERT.INDOOR_HUMIDITY',    'komfort', array('false', '0',   '100',  '<v.0> %')),
        'horizontal_swing_angle' => array('Text',  'WERT.H_SWING_ANGLE',      'komfort', null),
        'vertical_swing_angle'   => array('Text',  'WERT.V_SWING_ANGLE',      'komfort', null),
        'rate_select'            => array('Text',  'WERT.RATE_SELECT',        'komfort', null),
        'breeze_mode'            => array('Text',  'WERT.BREEZE_MODE',        'komfort', null),
        'ieco'                   => array($g,      'WERT.IECO',               'komfort', array('false', '0',   '1',    '<v.0>')),
        // ---- Energie ----
        'total_energy_usage'     => array('kWh',   'WERT.TOTAL_ENERGY',       'energie', array('false', '0',   '100000', '<v.2> kWh')),
        'current_energy_usage'   => array('kWh',   'WERT.CURRENT_ENERGY',     'energie', array('false', '0',   '100000', '<v.2> kWh')),
        'real_time_power_usage'  => array('W',     'WERT.REAL_TIME_POWER',    'energie', array('false', '0',   '20000',  '<v.0> W')),
    );
}

/** Die vier Themen des Lebenszeichens - eine Quelle fuer Tabelle und Vorlage. */
function mi_status_werte()
{
    return array(
        'status/ok'      => array('&mdash;', 'WERT.ST_OK',      array('false', '0', '1',        '<v.0>')),
        'status/ts'      => array('s',       'WERT.ST_TS',      array('false', '0', '4000000000', '<v.0>')),
        'status/zaehler' => array('&mdash;', 'WERT.ST_ZAEHLER', array('false', '0', '999',      '<v.0>')),
        'status/dienst'  => array('&mdash;', 'WERT.ST_DIENST',  array('false', '0', '1',        '<v.0>')),
    );
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
    return array(
        'DE' => array('Deutschland',        'DE'),
        'AT' => array('Österreich',         'DE'),
        'CH' => array('Schweiz',            'DE'),
        'NL' => array('Niederlande',        'DE'),
        'IT' => array('Italien',            'DE'),
        'ES' => array('Spanien',            'DE'),
        'FR' => array('Frankreich',         'DE'),
        'PL' => array('Polen',              'DE'),
        'GB' => array('Großbritannien',     'DE'),
        'US' => array('USA',                'US'),
        'KR' => array('Südkorea',           'KR'),
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

/** Vorlage der Gateway-Eingaenge nach dem Heimkino-Kunstgriff (12.08.2026):
 *  VirtualInHttp mit Dummy-Adresse http://localhost und Abfragezyklus 604800 s,
 *  nur damit Loxone die richtig benannten Eingaenge anlegt - die Werte kommen
 *  vom MQTT-Gateway. Format wie Original-Export aus Loxone Config 17.1.
 *
 *  Seit 4.3.0 enthaelt sie ALLE Zahlenwerte aus mi_werte() statt fuenf, dazu
 *  die vier Themen des Lebenszeichens. Die Textwerte (operational_mode,
 *  fan_speed, swing_mode, die beiden Schwenkwinkel, rate_select, breeze_mode)
 *  bleiben aussen vor: ein Textthema bekommt keinen virtuellen Eingang.
 */
function mi_vorlage($cfg = null)
{
    if ($cfg === null) { $cfg = mi_config_read(); }
    $topic   = mi_mqtt_topic($cfg);
    $geraete = mi_devices();
    $crlf = "\r\n";
    $x = function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    };
    $o  = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp HintText="" Title="Midea Klimageraete" Comment="Erzeugt vom LoxBerry-Plugin Midea2Lox ('
        . date('d.m.Y') . '). Werte kommen vom MQTT-Gateway - Abo ' . $x($topic)
        . '/# noetig." Address="http://localhost" PollingTime="604800">' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($geraete as $d) {
        if (!isset($d['id']) || $d['id'] === '') { continue; }
        // Nicht $d['name'] benutzen: der ist fuer die Oberflaeche bereits
        // HTML-maskiert und wuerde im XML doppelt maskiert.
        $name = mi_geraetename($d);
        foreach (mi_werte() as $wert => $w) {
            if ($w[3] === null) { continue; }   // Textwert, kein Eingang
            $titel = $topic . '_' . $d['id'] . '_' . $wert;
            $o .= "\t" . '<VirtualInHttpCmd Title="' . $x($titel) . '" ';
            $o .= 'Comment="' . $x(mi_t($w[1]) . ' ' . $name) . '" Check=" " ';
            $o .= 'Signed="' . $w[3][0] . '" Analog="true" SourceValLow="0" DestValLow="0" '
                . 'SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $w[3][1]
                . '" MaxVal="' . $w[3][2] . '" Unit="' . $x($w[3][3]) . '" HintText=""/>' . $crlf;
        }
    }
    // Das Lebenszeichen gilt fuer das Plugin, nicht je Geraet.
    foreach (mi_status_werte() as $wert => $w) {
        $titel = $topic . '_' . str_replace('/', '_', $wert);
        $o .= "\t" . '<VirtualInHttpCmd Title="' . $x($titel) . '" ';
        $o .= 'Comment="' . $x(mi_t($w[1])) . '" Check=" " ';
        $o .= 'Signed="' . $w[2][0] . '" Analog="true" SourceValLow="0" DestValLow="0" '
            . 'SourceValHigh="1" DestValHigh="1" DefVal="0" MinVal="' . $w[2][1]
            . '" MaxVal="' . $w[2][2] . '" Unit="' . $x($w[2][3]) . '" HintText=""/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return array('VI_midea2lox.xml', $o);
}

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
    return array(
        array('BEF.EIN_AUS',     'power.True',                       'power.False'),
        array('BEF.MODE_AUTO',   'ac.operational_mode_enum.auto',    null),
        array('BEF.MODE_COOL',   'ac.operational_mode_enum.cool',    null),
        array('BEF.MODE_HEAT',   'ac.operational_mode_enum.heat',    null),
        array('BEF.MODE_DRY',    'ac.operational_mode_enum.dry',     null),
        array('BEF.MODE_FAN',    'ac.operational_mode_enum.fan_only', null),
        array('BEF.FAN_AUTO',    'ac.fan_speed_enum.Auto',           null),
        array('BEF.FAN_SILENT',  'ac.fan_speed_enum.Silent',         null),
        array('BEF.FAN_LOW',     'ac.fan_speed_enum.Low',            null),
        array('BEF.FAN_MEDIUM',  'ac.fan_speed_enum.Medium',         null),
        array('BEF.FAN_HIGH',    'ac.fan_speed_enum.High',           null),
        array('BEF.FAN_FULL',    'ac.fan_speed_enum.Full',           null),
        array('BEF.SWING_OFF',   'ac.swing_mode_enum.Off',           null),
        array('BEF.SWING_VERT',  'ac.swing_mode_enum.Vertical',      null),
        array('BEF.SWING_HORZ',  'ac.swing_mode_enum.Horizontal',    null),
        array('BEF.SWING_BOTH',  'ac.swing_mode_enum.Both',          null),
        array('BEF.ECO',         'eco.True',                         'eco.False'),
        array('BEF.TURBO',       'turbo.True',                       'turbo.False'),
        array('BEF.SLEEP',       'sleep.True',                       'sleep.False'),
        array('BEF.FOLLOW',      'follow.True',                      'follow.False'),
        array('BEF.FREEZE',      'freeze.True',                      'freeze.False'),
        array('BEF.PURIFIER',    'purifier.True',                    'purifier.False'),
        array('BEF.TONE',        'tone.True',                        'tone.False'),
        array('BEF.DISPLAY',     'toggle_Display',                   null),
        array('BEF.SELFCLEAN',   'toggle_self_clean',                null),
        array('BEF.STATUS',      'status',                           null),
        array('BEF.TEMP',        '<v>',                              null),
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
    $o .= '<VirtualOut Title="Midea2Lox Befehle" Comment="Erzeugt vom LoxBerry-Plugin Midea2Lox ('
        . date('d.m.Y') . '). Befehle gehen per UDP an den LoxBerry." Address="'
        . $x('/dev/udp/' . $ip . '/' . $port) . '" CmdInit="" CloseAfterSend="false" CmdSeparator="">' . $crlf;
    $o .= "\t" . '<Info templateType="1" minVersion="17010727"/>' . $crlf;
    foreach ($geraete as $d) {
        if (!isset($d['id']) || $d['id'] === '') { continue; }
        $name = mi_geraetename($d);
        foreach (mi_befehle() as $b) {
            $titel = 'Midea2Lox ' . $d['id'] . ' ' . mi_t($b[0]);
            $ein  = $d['id'] . ',' . $b[1];
            $aus  = ($b[2] !== null) ? ($d['id'] . ',' . $b[2]) : '';
            $o .= "\t" . '<VirtualOutCmd Title="' . $x($titel) . '" ';
            $o .= 'Comment="' . $x($name) . '" ';
            $o .= 'CmdOn="' . $x($ein) . '" CmdOnMethod="UDP" ';
            $o .= 'CmdOff="' . $x($aus) . '" CmdOffMethod="UDP" ';
            $o .= 'Analog="' . ($b[1] === '<v>' ? 'true' : 'false') . '" ';
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
    return @file_put_contents($datei, mi_mqtt_topic($cfg) . '/#') !== false;
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
    $merkmale  = preg_match_all('/mi_fmt\(\)/', $q);
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
        return array(null, 0, count(mi_werte()) + count(mi_status_werte()), array());
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

    $genannt = array_merge(array_keys(mi_werte()), array_keys(mi_status_werte()));
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
    foreach (mi_status_werte() as $w) {
        $anzahl++;
        if (mi_t($w[1]) === $w[1]) { $fehlt[] = $w[1]; }
    }
    foreach (mi_befehle() as $b) {
        $anzahl++;
        if (mi_t($b[0]) === $b[0]) { $fehlt[] = $b[0]; }
    }
    foreach (mi_regionen() as $r) {
        $anzahl++;   // Regionsnamen stehen nicht in der Sprachdatei, sondern
                     // sind Eigennamen - hier wird nur mitgezaehlt, damit die
                     // Zahl der angesehenen Stellen stimmt.
    }
    return array(!$fehlt, $anzahl, $fehlt);
}

/** Sind die erzeugbaren Loxone-Vorlagen wohlgeformt? */
function mi_vorlagen_probe($cfg = null)
{
    $anzahl = 0;
    foreach (array('mi_vorlage', 'mi_vorlage_ausgang') as $f) {
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
        $fassung = (string) LBSystem::pluginversion();
    }
    return array(
        '_hinweis'      => mi_t('UI.SICH_KOPF'),
        '_plugin'       => 'Midea2Lox',
        '_fassung'      => $fassung,
        '_stand'        => date('Y-m-d H:i:s'),
        'einstellungen' => $voll,
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
function mi_sicherung_lesen($roh)
{
    $mangel = array();
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

    $neu = mi_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($roh_einst as $k => $w) {
        // Der lesbare Kopf wird UEBERGANGEN, nicht beanstandet.
        if (is_string($k) && $k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(mi_t('UI.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $fehler = mi_wert_pruefen($k, $w);
        if ($fehler !== '') {
            $mangel[] = mi_e($k) . ': ' . $fehler;
            continue;
        }
        $neu[$k] = trim((string) $w);
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = mi_t('UI.SICH_LEER');
    }

    // Geraete - je Feld eine Positivliste. Ein Token, das nicht wie eines
    // aussieht, ist eine Beanstandung: er geht spaeter an das Geraet, und
    // eine geratene Zeichenkette dort ist dasselbe wie eine geratene
    // Registeradresse.
    $geraete = array();
    foreach ($roh_ger as $i => $g) {
        $nr = (int) $i + 1;
        if (!is_array($g)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_FORM'), $nr);
            continue;
        }
        $id = isset($g['id']) ? trim((string) $g['id']) : '';
        if (!preg_match('/^\d{6,20}$/', $id)) {
            $mangel[] = sprintf(mi_t('UI.SICH_GERAET_ID'), $nr);
            continue;
        }
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

    if ($mangel) {
        return array(null, $mangel, $anzahl, count($geraete));
    }
    return array(array($neu, $geraete), array(), $anzahl, count($geraete));
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
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $z) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    @chmod($datei, 0600);
    return true;
}
