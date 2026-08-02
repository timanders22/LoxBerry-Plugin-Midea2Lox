<?php
/**
 * Midea2Lox - Bedienoberflaeche
 *
 * Ausschliesslich Oberflaeche. Die Werte holt der Dienst data/midea2lox.py,
 * der ueber system/daemons laeuft; Befehle nimmt er per UDP entgegen.
 */

require_once 'loxberry_web.php';
require_once __DIR__ . '/mi_lib.php';

$mi_p       = mi_paths();
$mi_meldung = '';
$mi_fehler  = array();

$mi_tab = preg_match('/^tab-(settings|mqtt|loxone|test|log)$/',
                     (string) (isset($_POST['activetab']) ? $_POST['activetab'] : ''))
    ? $_POST['activetab'] : 'tab-settings';

$mi_cfg = mi_config_read();

$mi_test_titel = '';
$mi_test_text  = '';

/* ---------------------------------------------------------------- *
 * Formulare
 * ---------------------------------------------------------------- */
if (isset($_POST['speichern']) || isset($_POST['speichern_suchen'])) {
    $neu = $mi_cfg;

    $ms = isset($_POST['MINISERVER']) ? trim((string) $_POST['MINISERVER']) : '';
    if (!preg_match('/^MINISERVER\d+$/', $ms)) {
        $mi_fehler[] = 'Der Miniserver muss aus der Liste gew&auml;hlt werden.';
    } else {
        $neu['MINISERVER'] = $ms;
    }

    $port = isset($_POST['UDP_PORT']) ? trim((string) $_POST['UDP_PORT']) : '';
    if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $mi_fehler[] = 'Der UDP-Port ist eine Zahl zwischen 1 und 65535.';
    } else {
        $neu['UDP_PORT'] = $port;
    }

    $conn = isset($_POST['maxConnectionLifetime'])
        ? trim((string) $_POST['maxConnectionLifetime']) : '';
    if (!ctype_digit($conn) || (int) $conn < 10 || (int) $conn > 3600) {
        $mi_fehler[] = 'Die Verbindungsdauer ist eine Zahl zwischen 10 und 3600 Sekunden.';
    } else {
        $neu['maxConnectionLifetime'] = $conn;
    }

    $reg = isset($_POST['region']) ? trim((string) $_POST['region']) : '';
    if (!array_key_exists($reg, mi_regionen())) {
        $mi_fehler[] = 'Die Region muss aus der Liste gew&auml;hlt werden.';
    } else {
        $neu['region'] = $reg;
    }

    $neu['DEBUG'] = (isset($_POST['DEBUG']) && $_POST['DEBUG'] === '1') ? '1' : '0';

    if (isset($_POST['MideaUser'])) {
        $neu['MideaUser'] = trim((string) $_POST['MideaUser']);
    }
    // Leeres Passwortfeld heisst "unveraendert lassen", nicht "loeschen".
    if (isset($_POST['MideaPassword']) && $_POST['MideaPassword'] !== '') {
        $neu['MideaPassword'] = (string) $_POST['MideaPassword'];
    }

    $neu['LoxberryIP'] = mi_localip();

    if (!$mi_fehler) {
        if (mi_config_write($neu)) {
            $mi_cfg = mi_config_read();
            if (isset($_POST['speichern_suchen'])) {
                require_once __DIR__ . '/mi_test.php';
                list($mi_test_titel, $mi_test_text) = mi_test_ausfuehren('discover', $mi_cfg);
                $mi_meldung = 'Gespeichert. Das Ergebnis der Ger&auml;tesuche steht '
                            . 'im Reiter <i>Test</i>.';
                $mi_tab = 'tab-test';
            } else {
                mi_dienst('restart');
                $mi_meldung = 'Gespeichert. Der Dienst wurde neu gestartet.';
                $mi_tab = 'tab-settings';
            }
        } else {
            $mi_fehler[] = 'Die Datei <span class="sm-mono">midea2lox.cfg</span> liess sich '
                         . 'nicht schreiben. Rechte im Konfigurationsordner pr&uuml;fen.';
        }
    }
    if ($mi_fehler) {
        $mi_tab = 'tab-settings';
    }
}

if (isset($_POST['dienst'])) {
    $was = (string) $_POST['dienst'];
    if (mi_dienst($was)) {
        $mi_meldung = 'Der Dienst wurde ' . ($was === 'stop' ? 'angehalten'
            : ($was === 'start' ? 'gestartet' : 'neu gestartet')) . '.';
    } else {
        $mi_fehler[] = 'Der Dienst liess sich nicht steuern &ndash; '
                     . '<span class="sm-mono">' . mi_e($mi_p['daemon']) . '</span> fehlt.';
    }
    $mi_tab = 'tab-settings';
}

if (isset($_POST['test'])) {
    require_once __DIR__ . '/mi_test.php';
    list($mi_test_titel, $mi_test_text) = mi_test_ausfuehren((string) $_POST['test'], $mi_cfg);
    $mi_tab = 'tab-test';
}

$mi_pid     = mi_dienst_pid();
$mi_geraete = mi_devices();
$mi_mq      = mi_mqtt_config();
$mi_topic   = mi_mqtt_topic();
$mi_port    = mi_cfg($mi_cfg, 'UDP_PORT', '7013');
$mi_ip      = mi_cfg($mi_cfg, 'LoxberryIP', mi_localip());
$mi_bsp     = mi_beispiel_id();
$mi_zeilen  = mi_log_tail();

$mi_version = '';
if (class_exists('LBSystem', false) && method_exists('LBSystem', 'pluginversion')) {
    $mi_version = (string) LBSystem::pluginversion();
}

LBWeb::lbheader('Midea2Lox' . ($mi_version !== '' ? ' V' . $mi_version : ''),
                'https://wiki.loxberry.de/plugins/midea2lox/start', 'help.html');
?>

<style>
.sm-wrap { max-width: 1100px; }
.sm-wrap h2 { color: #4f7d17; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px;
  font-size: 1.15em; margin: 22px 0 8px; }
.sm-small { font-size: 0.88em; color: #555; }
.sm-mono { font-family: monospace; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
  padding: 9px 18px; cursor: pointer; font-size: 0.95em; color: #444 !important; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-pane { display: none; padding-top: 4px; }
.sm-pane.sm-active { display: block; }
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
.sm-knopfreihe form { margin: 0; display: flex; }
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
.sm-gruen { background: #6dac20; } .sm-rot { background: #c62828; }
</style>

<div class="sm-wrap">

<?php if ($mi_fehler) { ?>
<div class="sm-alert sm-warn"><b>Nicht gespeichert:</b><ul>
<?php foreach ($mi_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($mi_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $mi_meldung; ?></div>
<?php } ?>

<div class="sm-tabs">
  <div class="sm-tab" data-ziel="tab-settings">Einstellungen</div>
  <div class="sm-tab" data-ziel="tab-mqtt">MQTT</div>
  <div class="sm-tab" data-ziel="tab-loxone">Einbindung in Loxone</div>
  <div class="sm-tab" data-ziel="tab-test">Test</div>
  <div class="sm-tab" data-ziel="tab-log">Logdateien</div>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-pane" id="tab-settings">

<h2>Dienst</h2>
<p><span class="sm-scheibe <?php echo $mi_pid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo $mi_pid !== null
    ? 'Der Dienst l&auml;uft (PID ' . mi_e($mi_pid) . ').'
    : 'Der Dienst l&auml;uft nicht &ndash; siehe Reiter <i>Logdateien</i>.'; ?></p>
<p class="sm-small">msmart-ng: <span class="sm-mono"><?php
  echo mi_e(mi_msmart_version() ?: 'unbekannt'); ?></span> &middot;
Python: <span class="sm-mono"><?php
  echo mi_e(mi_python_version() ?: 'unbekannt'); ?></span></p>

<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-settings">
    <button type="submit" name="dienst" value="start">Dienst starten</button>
  </form>
</div>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-settings">
    <button type="submit" name="dienst" value="restart">Dienst neu starten</button>
  </form>
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-settings">
    <button type="submit" name="dienst" value="stop">Dienst anhalten</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> Harmlos &mdash; ein Start l&auml;sst sich jederzeit r&uuml;ckg&auml;ngig machen</span>
<span><i class="sm-punkt sm-b-aktion"></i> Greift in den laufenden Betrieb ein</span>
</div>

<form method="post" action="index.php" autocomplete="off">
<input type="hidden" name="activetab" value="tab-settings">

<h2>Verbindung zum Miniserver</h2>
<div class="sm-row">
  <label for="MINISERVER">Miniserver</label>
  <select id="MINISERVER" name="MINISERVER">
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
  <label for="UDP_PORT">UDP-Port</label>
  <input type="text" id="UDP_PORT" name="UDP_PORT"
         value="<?php echo mi_e($mi_cfg['UDP_PORT']); ?>">
  <p class="sm-small">Auf diesem Port nimmt das Plugin Befehle von Loxone
  entgegen. Muss mit der Adresse des virtuellen Ausgangs zusammenpassen
  &ndash; siehe Reiter <i>Einbindung in Loxone</i>.</p>
</div>

<h2>Midea-Konto</h2>
<p class="sm-small">Dieselben Zugangsdaten wie in der Hersteller-App
(SmartHome / NetHome Plus). Sie werden nur f&uuml;r die <b>Ger&auml;tesuche</b>
gebraucht; danach l&auml;uft alles im eigenen Netz.</p>
<div class="sm-row">
  <label for="MideaUser">Benutzer (E-Mail-Adresse)</label>
  <input type="text" id="MideaUser" name="MideaUser"
         value="<?php echo mi_e($mi_cfg['MideaUser']); ?>">
</div>
<div class="sm-row">
  <label for="MideaPassword">Passwort</label>
  <input type="password" id="MideaPassword" name="MideaPassword" value=""
         placeholder="<?php echo $mi_cfg['MideaPassword'] !== ''
             ? 'gespeichert &ndash; leer lassen, um es zu behalten'
             : 'noch nicht gesetzt'; ?>">
</div>
<div class="sm-row">
  <label for="region">Region des Kontos</label>
  <select id="region" name="region">
<?php foreach (mi_regionen() as $k => $v) { ?>
    <option value="<?php echo $k; ?>"<?php
      echo $mi_cfg['region'] === $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
<?php } ?>
  </select>
</div>

<h2>Erweitert</h2>
<div class="sm-row">
  <label for="maxConnectionLifetime">Verbindungsdauer je Ger&auml;t (Sekunden)</label>
  <input type="text" id="maxConnectionLifetime" name="maxConnectionLifetime"
         value="<?php echo mi_e($mi_cfg['maxConnectionLifetime']); ?>">
  <p class="sm-small">Nach dieser Zeit baut der Dienst die Verbindung neu auf.
  90 Sekunden sind ein ruhiger Wert.</p>
</div>
<div class="sm-row">
  <label for="DEBUG">Ausf&uuml;hrliches Protokoll</label>
  <select id="DEBUG" name="DEBUG">
    <option value="0"<?php echo $mi_cfg['DEBUG'] !== '1' ? ' selected' : ''; ?>>aus</option>
    <option value="1"<?php echo $mi_cfg['DEBUG'] === '1' ? ' selected' : ''; ?>>ein</option>
  </select>
  <p class="sm-small">Nur zur Fehlersuche einschalten &ndash; die Logdatei
  w&auml;chst dann schnell.</p>
</div>

<div class="sm-knopfreihe sm-b-aktion">
  <button type="submit" name="speichern" value="1">Speichern und Dienst neu starten</button>
  <button type="submit" name="speichern_suchen" value="1">Speichern und Ger&auml;te suchen</button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> Startet den Dienst neu bzw. spricht mit dem Midea-Konto</span>
</div>
</form>

<h2>Gefundene Klimager&auml;te</h2>
<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-info">Es sind noch keine Ger&auml;te hinterlegt. Bitte
Zugangsdaten eintragen und <i>Speichern und Ger&auml;te suchen</i> dr&uuml;cken.</div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:26%">Ger&auml;te-ID</th><th style="width:26%">IP-Adresse</th><th>Bezeichnung</th></tr>
<?php foreach ($mi_geraete as $d) { ?>
<tr><td class="sm-mono"><?php echo mi_e($d['id']); ?></td>
    <td class="sm-mono"><?php echo mi_e($d['ip']); ?></td>
    <td><?php echo $d['name']; ?></td></tr>
<?php } ?>
</table>
<p class="sm-small">Die Liste steht in
<span class="sm-mono"><?php echo mi_e($mi_p['devices']); ?></span> und
&uuml;berlebt Updates.</p>
<?php } ?>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane" id="tab-mqtt">

<h2>Zustand des MQTT-Gateways</h2>
<p class="sm-small">Das MQTT-Gateway ist seit LoxBerry&nbsp;3 <b>Bestandteil des
Systems</b> und kein Plugin. Es wird unter <i>System &rarr; MQTT Gateway</i>
eingerichtet (<span class="sm-mono">/admin/system/mqtt.cgi</span>).</p>

<?php if (!$mi_mq) { ?>
<div class="sm-alert sm-warn">Die Systemkonfiguration
(<span class="sm-mono">config/system/general.json</span>) ist nicht lesbar.
Der UDP-Weg funktioniert trotzdem.</div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%">Gr&ouml;&szlig;e</th><th>Wert</th></tr>
<tr><td>Broker</td><td class="sm-mono"><?php
  echo mi_e($mi_mq['host'] . ':' . $mi_mq['port']); ?></td></tr>
<tr><td>Eigener Broker auf dem LoxBerry</td><td><?php
  echo $mi_mq['local'] ? 'ja' : 'nein &ndash; es wird ein fremder Broker verwendet'; ?></td></tr>
<tr><td>Gateway startet automatisch</td><td><?php
  echo $mi_mq['autostart'] ? 'ja' : 'nein &ndash; nach einem Neustart kommt nichts an'; ?></td></tr>
<tr><td>Benutzer</td><td class="sm-mono"><?php
  echo $mi_mq['user'] !== '' ? mi_e($mi_mq['user']) : '(ohne)'; ?></td></tr>
</table>
<?php } ?>

<h2>Das einzutragende Abo</h2>
<p class="sm-small"><b>Ohne diesen Eintrag kommt am Miniserver nichts an.</b>
Einzutragen unter <i>System &rarr; MQTT Gateway &rarr; Abonnements</i>:</p>
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>

<h2>Ver&ouml;ffentlichte Themen</h2>
<?php if (!$mi_geraete) { ?>
<p class="sm-small">Sobald Ger&auml;te hinterlegt sind, steht hier je Ger&auml;t
die vollst&auml;ndige Liste. Aufbau:
<span class="sm-mono"><?php echo mi_e($mi_topic); ?>/&lt;Ger&auml;te-ID&gt;/&lt;Wert&gt;</span></p>
<?php } ?>
<table class="sm-tbl">
<tr><th style="width:46%">Thema</th><th style="width:12%">Einheit</th><th>Bedeutung</th></tr>
<?php foreach (mi_werte() as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '/' . $mi_bsp . '/' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo $info[1]; ?></td></tr>
<?php } ?>
</table>

<p class="sm-small"><b>MQTT ist der Regelweg.</b> UDP steht als Ausweichweg
bereit &ndash; das Plugin sendet immer beides, die Entscheidung f&auml;llt
allein in Loxone.</p>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane" id="tab-loxone">

<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p class="sm-small">Midea2Lox arbeitet in <b>beide Richtungen</b>: Es meldet den
Zustand der Klimager&auml;te an den Miniserver (Schritt&nbsp;1 bis&nbsp;3) und
nimmt umgekehrt Befehle entgegen (Schritt&nbsp;4). Beides ist unabh&auml;ngig
voneinander &mdash; man kann auch nur lesen oder nur schalten.</p>

<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-warn"><b>Es sind noch keine Ger&auml;te hinterlegt.</b>
Die Beispiele unten benutzen deshalb die Platzhalter-ID
<span class="sm-mono">123456789</span>. Suchen Sie zuerst im Reiter
<i>Einstellungen</i> nach Ihren Ger&auml;ten &mdash; danach steht hier Ihre
echte ID.</div>
<?php } ?>

<div class="sm-step"><b>Schritt 1: Weg festlegen &mdash; MQTT oder UDP</b><br><br>
<b>MQTT</b> ist der bequemere Weg: Das Gateway legt die Namen selbst an, man
muss in Loxone nur virtuelle Eing&auml;nge mit passendem Titel erzeugen. Der
MQTT-Gateway ist <b>seit LoxBerry 3 Bestandteil des Systems</b> und muss nicht
nachinstalliert werden &mdash; zu finden unter <i>System &rarr; MQTT Gateway</i>
(<span class="sm-mono">/admin/system/mqtt.cgi</span>).<br><br>
<b>UDP</b> kommt ohne den Gateway aus, verlangt daf&uuml;r aber je Wert eine
Befehlserkennung. Auf LoxBerry 3 und 4 gibt es kaum einen Grund, nicht MQTT zu
nehmen.<br><br>
<b>Das Plugin sendet immer beides</b> &mdash; die Entscheidung f&auml;llt allein
in Loxone.
</div>

<div class="sm-step"><b>Schritt 2: Abo im MQTT-Gateway eintragen</b><br><br>
Nur f&uuml;r den MQTT-Weg. Unter <i>System-Einstellungen &rarr; MQTT Gateway
&rarr; Abonnements</i> eintragen:
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>
<b>Ohne diesen Eintrag kommt nichts an.</b> Danach zeigt das Gateway unter
<i>Eingehende Daten</i> die erzeugten Namen &mdash; die dort angezeigten gelten,
falls sie von der Tabelle abweichen.
</div>

<div class="sm-step"><b>Schritt 3: Virtuelle Eing&auml;nge anlegen</b><br><br>
In <b>Loxone Config</b>: Miniserver anklicken &rarr; <i>Virtuelle
Eing&auml;nge</i> &rarr; je Wert einen Eingang. <b>Der Titel muss exakt
stimmen</b> &mdash; das Gateway ordnet ausschlie&szlig;lich &uuml;ber den Namen
zu.
<?php foreach (($mi_geraete ?: array(array('id' => $mi_bsp, 'name' => 'Klimager&auml;t'))) as $d) { ?>
<p class="sm-small">Klimager&auml;t <b><?php echo $d['name']; ?></b>
(ID <span class="sm-mono"><?php echo mi_e($d['id']); ?></span>):</p>
<table class="sm-tbl">
<tr><th>Titel des virtuellen Eingangs</th><th style="width:12%">Einheit</th><th style="width:34%">Bedeutung</th></tr>
<?php foreach (mi_werte() as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $d['id'] . '_' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo $info[1]; ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<p class="sm-small"><b>Der UDP-Weg</b> stattdessen: einen <i>virtuellen
UDP-Eingang</i> auf Port <b><?php echo mi_e($mi_port); ?></b> anlegen, je Wert
einen Befehl mit dieser Erkennung:</p>
<pre class="sm-pre">\iMidea/<?php echo mi_e($mi_bsp); ?>/indoor_temperature,\i\v</pre>
<p class="sm-small">Das Muster hei&szlig;t: &bdquo;Suche den Text zwischen den
beiden <span class="sm-mono">\i</span> und nimm die Zahl, die direkt dahinter
steht (<span class="sm-mono">\v</span>).&ldquo;</p>
</div>

<div class="sm-step"><b>Schritt 4: Befehle an das Klimager&auml;t senden</b><br><br>
Zum Schalten legt man einen <i>Virtuellen Ausgang</i> an. Adresse:
<pre class="sm-pre">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></pre>
Darunter je Befehl einen <i>Virtuellen Ausgang Befehl</i>. Der Befehlstext
besteht aus der Ger&auml;te-ID und den gew&uuml;nschten Eigenschaften, durch
Komma getrennt:
<table class="sm-tbl">
<tr><th style="width:34%">Was</th><th>Befehl bei &bdquo;Ein&ldquo;</th></tr>
<tr><td>Einschalten</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True</td></tr>
<tr><td>Ausschalten</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,False</td></tr>
<tr><td>K&uuml;hlen, 22&nbsp;&deg;C</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.cool,22</td></tr>
<tr><td>Heizen, 21&nbsp;&deg;C</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.heat,21</td></tr>
<tr><td>L&uuml;fterstufe leise</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,ac.fan_speed_enum.Silent</td></tr>
<tr><td>Schwenken senkrecht</td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,ac.swing_mode_enum.Vertical</td></tr>
</table>
<b>Solltemperatur stufenlos:</b> Im Befehlstext <span class="sm-mono">&lt;v&gt;</span>
als Platzhalter benutzen, zum Beispiel
<span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.cool,&lt;v&gt;</span>
&mdash; dann setzt der angeschlossene Analogwert die Temperatur.
</div>

<div class="sm-step"><b>Schritt 5: Merken, wenn ein Ger&auml;t schweigt</b><br><br>
F&auml;llt ein Klimager&auml;t aus dem Netz, behalten die virtuellen
Eing&auml;nge ihren <b>letzten Wert</b>. In der App sieht dann alles normal aus.
Daf&uuml;r gibt es <span class="sm-mono">online</span>: Solange es
<span class="sm-mono">True</span> meldet, ist das Ger&auml;t erreichbar. Ein
Schwellwertschalter darauf, und eine Benachrichtigung meldet den Ausfall
&mdash; Aufbau in Schritt&nbsp;6, Zeilen 8 bis 10.
</div>

<div class="sm-step"><b>Schritt 6: Komplette Baustein-Liste zum 1:1-Nachbauen</b><br><br>
So sieht die vollst&auml;ndige Logik f&uuml;r <b>ein</b> Klimager&auml;t auf der
Programmierseite aus (jede Zeile = ein Baustein). Bei mehreren Ger&auml;ten
wiederholt man 1 bis 11 je Ger&auml;t. Alle Bausteine findet man in Loxone
Config &uuml;ber die Baustein-Suche (F5):
<table class="sm-tbl">
<tr><th>#</th><th>Baustein (Typ)</th><th>Name (Vorschlag)</th><th>Parameter</th><th>Eing&auml;nge verbinden mit</th></tr>
<tr><td>1</td><td>Virtueller Eingang</td><td class="sm-mono">&hellip;_indoor_temperature</td><td>Einheit &deg;C, 1 Nachkommastelle</td><td>&mdash; (kommt &uuml;ber das Gateway)</td></tr>
<tr><td>2</td><td>Virtueller Eingang</td><td class="sm-mono">&hellip;_outdoor_temperature</td><td>Einheit &deg;C</td><td>&mdash;</td></tr>
<tr><td>3</td><td>Virtueller Eingang</td><td class="sm-mono">&hellip;_target_temperature</td><td>Einheit &deg;C</td><td>&mdash;</td></tr>
<tr><td>4</td><td>Virtueller Eingang</td><td class="sm-mono">&hellip;_power_state</td><td>Digital (True/False)</td><td>&mdash;</td></tr>
<tr><td>5</td><td>Virtueller Eingang</td><td class="sm-mono">&hellip;_online</td><td>Digital</td><td>&mdash;</td></tr>
<tr><td>6</td><td>Virtueller Ausgang</td><td>Midea2Lox</td><td>Adresse <span class="sm-mono">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></span></td><td>&mdash;</td></tr>
<tr><td>7</td><td>Virt. Ausgang Befehl</td><td>Klima ein/aus</td><td>Ein: <span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True</span> &middot; Aus: <span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,False</span></td><td>&larr; vom Schalter/Baustein</td></tr>
<tr><td>8</td><td>Nicht (NOT)</td><td>Ger&auml;t offline</td><td>&mdash;</td><td>Eingang = #5</td></tr>
<tr><td>9</td><td>Einschaltverz&ouml;gerung</td><td>Ausfall best&auml;tigt</td><td>Verz&ouml;gerung <b>600</b> s</td><td>Eingang = #8</td></tr>
<tr><td>10</td><td>Benachrichtigung</td><td>Klimager&auml;t pr&uuml;fen</td><td>Text z.&nbsp;B. &bdquo;Das Klimager&auml;t meldet sich seit 10 Minuten nicht mehr.&ldquo;</td><td>&larr; #9</td></tr>
<tr><td>11</td><td>Status</td><td>Klima Wohnzimmer</td><td>Statustext siehe unten, Visualisierung EIN</td><td>v1 = #1, v2 = #3, v3 = #4</td></tr>
<tr><td>12 <i>(optional)</i></td><td>Intelligente Raumregelung</td><td>Klima Wohnzimmer</td><td>Betriebsart K&uuml;hlen</td><td>Ist-Temperatur = #1, Stellgr&ouml;&szlig;e &rarr; #7</td></tr>
<tr><td>13 <i>(optional)</i></td><td>Merker</td><td>Klima Freigabe</td><td>&mdash;</td><td>Sperrt #7 bei offenem Fenster</td></tr>
<tr><td>14 <i>(optional)</i></td><td>Formel</td><td>Temperaturspreizung</td><td>Formel: <span class="sm-mono">I1-I2</span></td><td>I1 = #1, I2 = #2</td></tr>
</table>
<br>
<b>Statustext f&uuml;r #11:</b>
<pre class="sm-pre">Raum &lt;v1.1&gt; &deg;C &middot; Soll &lt;v2.1&gt; &deg;C &middot; &lt;v3&gt;</pre>
<b>Zu #9:</b> Die Verz&ouml;gerung muss deutlich &uuml;ber dem Abfragetakt
liegen. Bei einer Abfrage je Minute sind 600&nbsp;Sekunden ein ruhiger Wert
&mdash; ein einzelner verpasster Durchlauf l&ouml;st damit noch keine Meldung
aus.<br>
<b>Zu #10:</b> Der Benachrichtigungs-Baustein sendet nur bei einem Wechsel von
Aus auf Ein. Niemals mehrere Quellen direkt an seinen Eingang legen &mdash; erst
&uuml;ber einen ODER-Baustein zusammenf&uuml;hren, sonst verschluckt eine
dauerhaft aktive Quelle alle &uuml;brigen.<br>
<b>Zu #12:</b> Die Raumregelung ist der elegante Weg, weil sie Zeitplan,
Anwesenheit und Fensterkontakte schon mitbringt. Wer es einfach mag, schaltet #7
direkt.<br>
<b>Zu #13:</b> Ein offenes Fenster sollte das Klimager&auml;t abschalten
&mdash; sonst k&uuml;hlt man den Garten.
</div>

<div class="sm-step"><b>Schritt 7: Gegenprobe</b><br><br>
Nach dem Speichern in Loxone Config: Im Reiter <i>Test</i> auf <i>Zustand aller
Ger&auml;te abfragen</i> klicken. Erscheinen dort Werte, sendet das Plugin.
Kommen sie in Loxone trotzdem nicht an, liegt es fast immer am fehlenden Abo aus
Schritt&nbsp;2 oder an einem Tippfehler im Titel des virtuellen Eingangs.
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane" id="tab-test">

<?php if ($mi_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $mi_test_titel; ?></b></div>
<?php echo $mi_test_text; ?>
<?php } ?>

<h2>Selbstpr&uuml;fung</h2>
<table class="sm-tbl">
<tr><th style="width:44%">Frage</th><th>Antwort</th></tr>
<?php
require_once __DIR__ . '/mi_test.php';
foreach (mi_pruefungen($mi_cfg) as $c) { ?>
<tr><td><?php echo $c[0]; ?></td>
    <td><?php echo ($c[1] ? '&#10004; ' : '&#10008; ') . $c[2]; ?></td></tr>
<?php } ?>
</table>

<h2>Nachsehen</h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php foreach (array('umgebung' => 'Umgebung pr&uuml;fen',
                     'status'   => 'Zustand aller Ger&auml;te abfragen') as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="<?php echo mi_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>

<h2>Ger&auml;tesuche</h2>
<p class="sm-small">Spricht mit dem Midea-Konto und schreibt die gefundenen
Ger&auml;te in <span class="sm-mono">devices.cfg</span>.</p>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input type="hidden" name="activetab" value="tab-test">
    <button type="submit" name="test" value="discover">Jetzt nach Ger&auml;ten suchen</button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="sm-punkt sm-b-aktion"></i> L&ouml;st etwas aus &mdash; spricht mit dem Midea-Konto</span>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane" id="tab-log">
<h2>Logdateien</h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
} else { ?>
<p class="sm-small">Neueste Zeile oben. Datei:
<span class="sm-mono"><?php echo mi_e($mi_p['log']); ?></span></p>
<?php if (!$mi_zeilen) { ?>
<div class="sm-alert sm-info">Die Protokolldatei ist leer oder nicht lesbar.</div>
<?php } else { ?>
<div class="sm-log"><?php
  foreach ($mi_zeilen as $z) { echo mi_e($z) . "\n"; }
?></div>
<?php } ?>
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
        reiter[k].addEventListener('click', function () {
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($mi_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
