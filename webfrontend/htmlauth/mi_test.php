<?php
/**
 * Midea2Lox - die Aktionen und Prüfungen des Reiters Test
 */

require_once __DIR__ . '/mi_lib.php';

function mi_block($text)
{
    return '<div class="sm-log">' . mi_e($text) . '</div>';
}

/**
 * Die Selbstpruefung: je Zeile eine Frage mit Haekchen oder Kreuz.
 * Liefert eine Liste aus (Name, erfuellt, Text).
 */
function mi_pruefungen($cfg)
{
    $p = mi_paths();
    $z = array();

    $venv = is_executable($p['venv']);
    $z[] = array('Virtuelle Python-Umgebung', $venv,
        $venv ? 'vorhanden' : 'fehlt (' . $p['venv'] . ') &ndash; Plugin bitte neu installieren');

    $msmart = mi_msmart_version();
    $z[] = array('msmart-ng ladbar', $msmart !== '',
        $msmart !== '' ? 'Fassung ' . $msmart
                       : 'nicht ladbar &ndash; Plugin bitte neu installieren');

    $py = mi_python_version();
    $z[] = array('Python in der Umgebung', $py !== '',
        $py !== '' ? 'Fassung ' . $py : 'nicht ermittelbar');

    $pid = mi_dienst_pid();
    $z[] = array('Dienst midea2lox.py', $pid !== null,
        $pid !== null ? 'l&auml;uft (PID ' . $pid . ')' : 'gestoppt');

    $port = mi_cfg($cfg, 'UDP_PORT', '');
    $ok = ($port !== '' && ctype_digit($port));
    $z[] = array('UDP-Port gesetzt', $ok, $ok ? $port : 'nicht gesetzt');

    $u = mi_cfg($cfg, 'MideaUser', '');
    $z[] = array('Midea-Zugangsdaten hinterlegt', $u !== '',
        $u !== '' ? mi_e($u) : 'nicht hinterlegt &ndash; Ger&auml;tesuche nicht m&ouml;glich');

    $dev = mi_devices();
    $z[] = array('Klimager&auml;te hinterlegt', (bool) $dev,
        $dev ? count($dev) . ' Ger&auml;t(e)' : 'keine &ndash; bitte suchen lassen');

    $mq = mi_mqtt_config();
    if (!$mq) {
        $z[] = array('MQTT-Gateway (Systembestandteil)', false,
            'Systemkonfiguration nicht lesbar &ndash; UDP funktioniert trotzdem');
    } elseif (!$mq['autostart']) {
        $z[] = array('MQTT-Gateway (Systembestandteil)', false,
            mi_e($mq['host'] . ':' . $mq['port']) . ', startet aber nicht automatisch mit');
    } else {
        $z[] = array('MQTT-Gateway (Systembestandteil)', true,
            mi_e($mq['host'] . ':' . $mq['port']) . ', Autostart ein');
    }

    return $z;
}

/** Geraetesuche anstossen. */
function mi_discover()
{
    $p = mi_paths();
    $skript = $p['data'] . '/discover.py';
    if (!is_readable($skript)) {
        return 'discover.py wurde nicht gefunden (' . $skript . ').';
    }
    $alt = getcwd();
    @chdir($p['data']);
    list($code, $aus) = mi_python(array($skript));
    if ($alt !== false) { @chdir($alt); }
    return trim($aus) !== '' ? $aus : '(keine Ausgabe)';
}

/**
 * Zustand aller Geraete.
 *
 * Bewusst nur lesend: eine eigene Abfrage wuerde mit dem laufenden Dienst
 * um die Verbindung zum Klimageraet streiten.
 */
function mi_status()
{
    $p = mi_paths();
    $geraete = mi_devices();
    if (!$geraete) {
        return "Es sind keine Ger&auml;te hinterlegt. Bitte zuerst im Reiter\n"
             . "'Einstellungen' nach Ger&auml;ten suchen lassen.";
    }
    if (!is_readable($p['log'])) {
        return "Es gibt noch keine Logdatei (" . $p['log'] . ").\n"
             . "L&auml;uft der Dienst? Er schreibt bei jedem Durchlauf hinein.";
    }
    $aus = "Hinterlegte Ger&auml;te:\n";
    foreach ($geraete as $d) {
        $aus .= sprintf("  %-16s %-16s %s\n", $d['id'], $d['ip'],
                        html_entity_decode($d['name'], ENT_QUOTES, 'UTF-8'));
    }
    $aus .= "\nLetzte Zeilen aus midea2lox.log:\n";
    $aus .= "--------------------------------------------------------------\n";
    $zeilen = mi_log_tail(40);
    $aus .= $zeilen ? implode("\n", array_reverse($zeilen)) : '(Logdatei ist leer)';
    return $aus;
}

/** Eine Aktion des Reiters Test ausfuehren. */
function mi_test_ausfuehren($was, $cfg)
{
    switch ($was) {
        case 'discover':
            return array('Ger&auml;tesuche', mi_block(mi_discover()));
        case 'status':
            return array('Zustand aller Ger&auml;te', mi_block(mi_status()));
        case 'umgebung':
            $p = mi_paths();
            $z = array();
            $z[] = 'Pluginordner      : ' . $p['plugin'];
            $z[] = 'Python-Umgebung   : ' . ($p['venv'])
                 . (is_executable($p['venv']) ? '  [vorhanden]' : '  [FEHLT]');
            $z[] = 'Python            : ' . (mi_python_version() ?: 'nicht ermittelbar');
            $z[] = 'msmart-ng         : ' . (mi_msmart_version() ?: 'nicht ladbar');
            $z[] = '';
            foreach (array('config' => 'midea2lox.cfg', 'devices' => 'devices.cfg',
                           'log' => 'midea2lox.log') as $k => $name) {
                $z[] = sprintf('%-18s: %s', $name, is_readable($p[$k])
                    ? 'vorhanden (' . number_format(filesize($p[$k]) / 1024, 1, ',', '.') . ' kB)'
                    : 'nicht vorhanden');
            }
            $z[] = '';
            $mq = mi_mqtt_config();
            $z[] = 'MQTT-Gateway      : ' . ($mq
                ? $mq['host'] . ':' . $mq['port']
                  . ' (Autostart ' . ($mq['autostart'] ? 'ein' : 'aus') . ')'
                : 'Konfiguration nicht lesbar');
            return array('Umgebung', mi_block(implode("\n", $z)));
    }
    return array('Unbekannte Pr&uuml;fung',
        '<p class="sm-small">Diese Pr&uuml;fung gibt es nicht.</p>');
}
