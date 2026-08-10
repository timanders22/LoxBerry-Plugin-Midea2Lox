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
        'log'     => $home . '/log/plugins/' . $ordner . '/midea2lox.log',
        'data'    => $home . '/data/plugins/' . $ordner,
        'bin'     => $home . '/bin/plugins/' . $ordner,
        'venv'    => $home . '/bin/plugins/' . $ordner . '/venv/bin/python3',
        'daemon'  => $home . '/system/daemons/plugins/' . $ordner,
        'general' => $home . '/config/system/general.json',
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
        $texte = @parse_ini_file($ordner . '/language_' . mi_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        // Englisch ist die Rueckfallebene, nicht Deutsch.
        $rueck = @parse_ini_file($ordner . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
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
    );
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
 * midea2lox.cfg schreiben.
 *
 * Der Python-Dienst liest dieselbe Datei mit configparser. Werte werden
 * deshalb ohne Anfuehrungszeichen geschrieben - genau so, wie die frueher
 * zustaendige Perl-Fassung (Config::Simple) es getan hat.
 */
function mi_config_write($cfg)
{
    $voll = array_merge(mi_vorgaben(), $cfg);
    $z = "[default]\n";
    foreach ($voll as $k => $v) {
        // Zeilenumbrueche wuerden die Datei zerlegen.
        $v = str_replace(array("\r", "\n"), '', (string) $v);
        $z .= $k . '=' . $v . "\n";
    }
    $datei = mi_paths()['config'];
    @mkdir(dirname($datei), 0775, true);
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
 *     device min temperature = 16
 *
 * Nicht mit parse_ini_file lesen: die Schluessel enthalten Leerzeichen
 * ("device min temperature"), damit kommt PHPs INI-Leser nicht zurecht.
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
                'abschnitt' => $abschnitt,
                // Der Abschnitt heisst Midea_<ID> - die ID steht ausserdem
                // als eigener Schluessel, aber nicht bei jedem Geraet.
                'id'   => (strpos($abschnitt, 'Midea_') === 0)
                          ? substr($abschnitt, 6) : $abschnitt,
                'ip'   => '',
                'port' => '',
                'typ'  => '',
                'unterstuetzt' => '',
            );
            continue;
        }
        if ($akt === null || strpos($z, '=') === false) {
            continue;
        }
        list($k, $v) = array_map('trim', explode('=', $z, 2));
        $k = strtolower($k);
        if ($k === 'ip')             { $akt['ip']   = $v; }
        elseif ($k === 'port')       { $akt['port'] = $v; }
        elseif ($k === 'type')       { $akt['typ']  = $v; }
        elseif ($k === 'id' && $v !== '') { $akt['id'] = $v; }
        elseif ($k === 'supported')  { $akt['unterstuetzt'] = $v; }
    }
    if ($akt !== null) { $geraete[] = $akt; }

    foreach ($geraete as $i => $g) {
        $geraete[$i]['name'] = ($g['typ'] !== '')
            ? mi_e($g['typ']) . ' ' . mi_e($g['id'])
            : 'Klimager&auml;t ' . mi_e($g['id']);
    }
    return $geraete;
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
    $datei = mi_paths()['data'] . '/dienst.pid';
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
        return false;
    }
    $d = mi_paths()['daemon'];
    if (!is_executable($d)) {
        return false;
    }
    @exec(escapeshellarg($d) . ' ' . $was . ' >/dev/null 2>&1');
    return true;
}

/** Etwas in der virtuellen Python-Umgebung ausfuehren. */
function mi_python($argumente)
{
    $venv = mi_paths()['venv'];
    if (!is_executable($venv)) {
        return array(1, 'Die virtuelle Python-Umgebung fehlt (' . $venv . ").\n"
                      . 'Bitte das Plugin neu installieren.');
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

/** Das Themenpraefix - im Python-Teil fest verdrahtet. */
function mi_mqtt_topic()
{
    return 'Midea2Lox';
}

/** Die je Geraet veroeffentlichten Werte. */
function mi_werte()
{
    return array(
        'power_state'         => array('&mdash;',    'Ein/Aus'),
        'indoor_temperature'  => array('&deg;C',     'Raumtemperatur'),
        'outdoor_temperature' => array('&deg;C',     'Au&szlig;entemperatur'),
        'target_temperature'  => array('&deg;C',     'Solltemperatur'),
        'operational_mode'    => array('Text',       'Betriebsart'),
        'fan_speed'           => array('Text',       'L&uuml;fterstufe'),
        'online'              => array('&mdash;',    'Erreichbarkeit'),
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

/** Die Regionen des Midea-Kontos. */
function mi_regionen()
{
    return array(
        'DE' => 'Deutschland', 'AT' => '&Ouml;sterreich', 'CH' => 'Schweiz',
        'NL' => 'Niederlande', 'IT' => 'Italien',         'ES' => 'Spanien',
        'FR' => 'Frankreich',  'PL' => 'Polen',           'GB' => 'Gro&szlig;britannien',
        'US' => 'USA',         'CN' => 'China',
    );
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
