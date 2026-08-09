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

/* Aktiver Reiter.
 *
 * Er kommt aus dem abgesendeten Formular (activetab) oder aus der Adresse
 * (?form=...). Letzteres brauchen die Reiter, seit sie echte Verweise sind.
 * Die Positivliste MUSS jeden Reiter enthalten - fehlt einer, ist er
 * sichtbar und anklickbar, aber nach jedem Absenden springt die Seite
 * zurueck auf Einstellungen. */
$mi_muster = '/^tab-(settings|mqtt|loxone|test|log)$/';
$mi_wunsch = isset($_POST['activetab']) ? (string) $_POST['activetab']
    : (isset($_GET['form']) ? 'tab-' . (string) $_GET['form'] : '');
$mi_tab = preg_match($mi_muster, $mi_wunsch) ? $mi_wunsch : 'tab-settings';

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
  text-decoration: none !important; display: inline-block;
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
<div class="sm-alert sm-warn"><b><?php echo mi_t('UI.NICHT_GESPEICHERT'); ?></b><ul>
<?php foreach ($mi_fehler as $f) { echo '<li>' . $f . '</li>'; } ?>
</ul></div>
<?php } elseif ($mi_meldung !== '') { ?>
<div class="sm-alert sm-ok"><?php echo $mi_meldung; ?></div>
<?php } ?>

<?php
/*
 * Reiter als echte Verweise, sm-active vom SERVER.
 *
 * Bis 4.0.0 standen hier <div class="sm-tab"> ohne Verweis, und sm-active
 * vergab allein das JavaScript am Seitenende. Da .sm-pane auf display:none
 * steht, war die Seite ohne JavaScript vollstaendig leer - und die Reiter
 * liessen sich nicht einmal anklicken, weil ein <div> kein Verweis ist.
 *
 * $mi_tab wurde serverseitig laengst ermittelt und nur ans JavaScript
 * weitergereicht. Diese Liste, die Positivliste weiter oben und die id der
 * Flaechen muessen deckungsgleich bleiben - alle drei.
 */
$mi_reiter = array(
    'tab-settings' => mi_t('COMMON.LABEL_SETTINGS'),
    'tab-mqtt'     => mi_t('COMMON.LABEL_MQTT'),
    'tab-loxone'   => mi_t('COMMON.LABEL_LOXONE'),
    'tab-test'     => mi_t('COMMON.LABEL_TEST'),
    'tab-log'      => mi_t('COMMON.LABEL_LOG'),
);
?>
<div class="sm-tabs">
<?php foreach ($mi_reiter as $mi_id => $mi_bez) { ?>
  <a class="sm-tab<?php echo $mi_tab === $mi_id ? ' sm-active' : ''; ?>" data-ziel="<?php echo mi_e($mi_id); ?>"
     href="index.php?form=<?php echo mi_e(substr($mi_id, 4)); ?>"><?php echo mi_e($mi_bez); ?></a>
<?php } ?>
</div>

<!-- ============================ Einstellungen ============================ -->
<div class="sm-pane<?php echo $mi_tab === 'tab-settings' ? ' sm-active' : ''; ?>" id="tab-settings">

<h2><?php echo mi_e(mi_t('SETTINGS.HEAD_SERVICE')); ?></h2>
<p><span class="sm-scheibe <?php echo $mi_pid !== null ? 'sm-gruen' : 'sm-rot'; ?>"></span>
<?php echo $mi_pid !== null
    ? mi_e(mi_t('SETTINGS.HEAD_SERVICE')) . ' ' . mi_e(mi_t('SETTINGS.STATE_RUNNING'))
      . ' (PID ' . mi_e($mi_pid) . ').'
    : mi_e(mi_t('SETTINGS.HEAD_SERVICE')) . ' ' . mi_e(mi_t('SETTINGS.STATE_STOPPED'))
      . ' &ndash; siehe Reiter <i>' . mi_e(mi_t('COMMON.LABEL_LOG')) . '</i>.'; ?></p>
<p class="sm-small"><?php echo mi_t('UI.MSMART_NG'); ?> <span class="sm-mono"><?php
  echo mi_e(mi_msmart_version() ?: 'unbekannt'); ?></span> <?php echo mi_t('UI.PYTHON'); ?> <span class="sm-mono"><?php
  echo mi_e(mi_python_version() ?: 'unbekannt'); ?></span></p>

<div class="sm-knopfreihe sm-b-lesen">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" type="submit" name="dienst" value="start"><?php echo mi_t('UI.DIENST_STARTEN'); ?></button>
  </form>
</div>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" type="submit" name="dienst" value="restart"><?php echo mi_t('UI.DIENST_NEU_STARTEN'); ?></button>
  </form>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <button data-role="none" type="submit" name="dienst" value="stop"><?php echo mi_t('UI.DIENST_ANHALTEN'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mi_t('UI.HARMLOS_EIN_START_L_SST'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.GREIFT_IN_DEN_LAUFENDEN_BETRIEB'); ?></span>
</div>

<form method="post" action="index.php" autocomplete="off">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2><?php echo mi_e(mi_t('SETTINGS.HEAD_LOXONE')); ?></h2>
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
  <p class="sm-small"><?php echo mi_t('UI.AUF_DIESEM_PORT_NIMMT_DAS'); ?> <i><?php echo mi_t('UI.EINBINDUNG_IN_LOXONE'); ?></i>.</p>
</div>

<h2><?php echo mi_e(mi_t('SETTINGS.HEAD_MIDEA')); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.DIESELBEN_ZUGANGSDATEN_WIE_IN_DER'); ?> <b><?php echo mi_t('UI.GER_TESUCHE'); ?></b>
<?php echo mi_t('UI.GEBRAUCHT_DANACH_L_UFT_ALLES'); ?></p>
<div class="sm-row">
  <label for="MideaUser"><?php echo mi_t('UI.BENUTZER_E_MAIL_ADRESSE'); ?></label>
  <input data-role="none" type="text" id="MideaUser" name="MideaUser"
         value="<?php echo mi_e($mi_cfg['MideaUser']); ?>">
</div>
<div class="sm-row">
  <label for="MideaPassword"><?php echo mi_t('UI.PASSWORT'); ?></label>
  <input data-role="none" type="password" id="MideaPassword" name="MideaPassword" value=""
         placeholder="<?php echo $mi_cfg['MideaPassword'] !== ''
             ? 'gespeichert &ndash; leer lassen, um es zu behalten'
             : 'noch nicht gesetzt'; ?>">
</div>
<div class="sm-row">
  <label for="region"><?php echo mi_t('UI.REGION_DES_KONTOS'); ?></label>
  <select data-role="none" id="region" name="region">
<?php foreach (mi_regionen() as $k => $v) { ?>
    <option value="<?php echo $k; ?>"<?php
      echo $mi_cfg['region'] === $k ? ' selected' : ''; ?>><?php echo $v; ?></option>
<?php } ?>
  </select>
</div>

<h2><?php echo mi_e(mi_t('SETTINGS.HEAD_ADVANCED')); ?></h2>
<div class="sm-row">
  <label for="maxConnectionLifetime"><?php echo mi_t('UI.VERBINDUNGSDAUER_JE_GER_T_SEKUNDEN'); ?></label>
  <input data-role="none" type="text" id="maxConnectionLifetime" name="maxConnectionLifetime"
         value="<?php echo mi_e($mi_cfg['maxConnectionLifetime']); ?>">
  <p class="sm-small"><?php echo mi_t('UI.NACH_DIESER_ZEIT_BAUT_DER'); ?></p>
</div>
<div class="sm-row">
  <label for="DEBUG"><?php echo mi_t('UI.AUSF_HRLICHES_PROTOKOLL'); ?></label>
  <select data-role="none" id="DEBUG" name="DEBUG">
    <option value="0"<?php echo $mi_cfg['DEBUG'] !== '1' ? ' selected' : ''; ?>><?php echo mi_t('UI.AUS'); ?></option>
    <option value="1"<?php echo $mi_cfg['DEBUG'] === '1' ? ' selected' : ''; ?>><?php echo mi_t('UI.EIN'); ?></option>
  </select>
  <p class="sm-small"><?php echo mi_t('UI.NUR_ZUR_FEHLERSUCHE_EINSCHALTEN_DIE'); ?></p>
</div>

<div class="sm-knopfreihe sm-b-aktion">
  <button data-role="none" type="submit" name="speichern" value="1"><?php echo mi_t('UI.SPEICHERN_UND_DIENST_NEU_STARTEN'); ?></button>
  <button data-role="none" type="submit" name="speichern_suchen" value="1"><?php echo mi_t('UI.SPEICHERN_UND_GER_TE_SUCHEN'); ?></button>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.STARTET_DEN_DIENST_NEU_BZW'); ?></span>
</div>
</form>

<h2><?php echo mi_e(mi_t('SETTINGS.HEAD_DEVICES')); ?></h2>
<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-info"><?php echo mi_t('UI.ES_SIND_NOCH_KEINE_GER'); ?> <i><?php echo mi_t('UI.SPEICHERN_UND_GER_TE_SUCHEN_2'); ?></i> <?php echo mi_t('UI.DR_CKEN'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:26%"><?php echo mi_t('UI.GER_TE_ID'); ?></th><th style="width:26%"><?php echo mi_t('UI.IP_ADRESSE'); ?></th><th><?php echo mi_t('UI.BEZEICHNUNG'); ?></th></tr>
<?php foreach ($mi_geraete as $d) { ?>
<tr><td class="sm-mono"><?php echo mi_e($d['id']); ?></td>
    <td class="sm-mono"><?php echo mi_e($d['ip']); ?></td>
    <td><?php echo $d['name']; ?></td></tr>
<?php } ?>
</table>
<p class="sm-small"><?php echo mi_t('UI.DIE_LISTE_STEHT_IN'); ?>
<span class="sm-mono"><?php echo mi_e($mi_p['devices']); ?></span> <?php echo mi_t('UI.UND_BERLEBT_UPDATES'); ?></p>
<?php } ?>
</div>

<!-- ================================= MQTT ================================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-mqtt' ? ' sm-active' : ''; ?>" id="tab-mqtt">

<h2><?php echo mi_t('UI.ZUSTAND_DES_MQTT_GATEWAYS'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.DAS_MQTT_GATEWAY_IST_SEIT'); ?> <b><?php echo mi_t('UI.BESTANDTEIL_DES_SYSTEMS'); ?></b> <?php echo mi_t('UI.UND_KEIN_PLUGIN_ES_WIRD'); ?> <i><?php echo mi_t('UI.SYSTEM_MQTT_GATEWAY'); ?></i>
<?php echo mi_t('UI.EINGERICHTET'); ?><span class="sm-mono"><?php echo mi_t('UI.ADMIN_SYSTEM_MQTT_CGI'); ?></span>).</p>

<?php if (!$mi_mq) { ?>
<div class="sm-alert sm-warn"><?php echo mi_t('UI.DIE_SYSTEMKONFIGURATION'); ?><span class="sm-mono"><?php echo mi_t('UI.CONFIG_SYSTEM_GENERAL_JSON'); ?></span><?php echo mi_t('UI.IST_NICHT_LESBAR_DER_UDP'); ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo mi_t('UI.GR_E'); ?></th><th><?php echo mi_t('UI.WERT'); ?></th></tr>
<tr><td><?php echo mi_t('UI.BROKER'); ?></td><td class="sm-mono"><?php
  echo mi_e($mi_mq['host'] . ':' . $mi_mq['port']); ?></td></tr>
<tr><td><?php echo mi_t('UI.EIGENER_BROKER_AUF_DEM_LOXBERRY'); ?></td><td><?php
  echo $mi_mq['local'] ? 'ja' : 'nein &ndash; es wird ein fremder Broker verwendet'; ?></td></tr>
<tr><td><?php echo mi_t('UI.GATEWAY_STARTET_AUTOMATISCH'); ?></td><td><?php
  echo $mi_mq['autostart'] ? 'ja' : 'nein &ndash; nach einem Neustart kommt nichts an'; ?></td></tr>
<tr><td><?php echo mi_t('UI.BENUTZER'); ?></td><td class="sm-mono"><?php
  echo $mi_mq['user'] !== '' ? mi_e($mi_mq['user']) : '(ohne)'; ?></td></tr>
</table>
<?php } ?>

<h2><?php echo mi_t('UI.DAS_EINZUTRAGENDE_ABO'); ?></h2>
<p class="sm-small"><b><?php echo mi_t('UI.OHNE_DIESEN_EINTRAG_KOMMT_AM'); ?></b>
<?php echo mi_t('UI.EINZUTRAGEN_UNTER'); ?> <i><?php echo mi_t('UI.SYSTEM_MQTT_GATEWAY_ABONNEMENTS'); ?></i>:</p>
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>

<h2><?php echo mi_t('UI.VER_FFENTLICHTE_THEMEN'); ?></h2>
<?php if (!$mi_geraete) { ?>
<p class="sm-small"><?php echo mi_t('UI.SOBALD_GER_TE_HINTERLEGT_SIND'); ?>
<span class="sm-mono"><?php echo mi_e($mi_topic); ?>/&lt;Ger&auml;te-ID&gt;/&lt;Wert&gt;</span></p>
<?php } ?>
<table class="sm-tbl">
<tr><th style="width:46%"><?php echo mi_t('UI.THEMA'); ?></th><th style="width:12%"><?php echo mi_t('UI.EINHEIT'); ?></th><th><?php echo mi_t('UI.BEDEUTUNG'); ?></th></tr>
<?php foreach (mi_werte() as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '/' . $mi_bsp . '/' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo $info[1]; ?></td></tr>
<?php } ?>
</table>

<p class="sm-small"><b><?php echo mi_t('UI.MQTT_IST_DER_REGELWEG'); ?></b> <?php echo mi_t('UI.UDP_STEHT_ALS_AUSWEICHWEG_BEREIT'); ?></p>
</div>

<!-- ========================= Einbindung in Loxone ========================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-loxone' ? ' sm-active' : ''; ?>" id="tab-loxone">

<h2><?php echo mi_t('UI.EINBINDUNG_IN_LOXONE_SCHRITT_F'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.MIDEA2LOX_ARBEITET_IN'); ?> <b><?php echo mi_t('UI.BEIDE_RICHTUNGEN'); ?></b><?php echo mi_t('UI.ES_MELDET_DEN_ZUSTAND_DER'); ?></p>

<?php if (!$mi_geraete) { ?>
<div class="sm-alert sm-warn"><b><?php echo mi_t('UI.ES_SIND_NOCH_KEINE_GER_2'); ?></b>
<?php echo mi_t('UI.DIE_BEISPIELE_UNTEN_BENUTZEN_DESHALB'); ?>
<span class="sm-mono">123456789</span><?php echo mi_t('UI.SUCHEN_SIE_ZUERST_IM_REITER'); ?>
<i><?php echo mi_t('UI.EINSTELLUNGEN'); ?></i> <?php echo mi_t('UI.NACH_IHREN_GER_TEN_DANACH'); ?></div>
<?php } ?>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_1_WEG_FESTLEGEN_MQTT'); ?></b><br><br>
<b><?php echo mi_t('UI.MQTT'); ?></b> <?php echo mi_t('UI.IST_DER_BEQUEMERE_WEG_DAS'); ?> <b><?php echo mi_t('UI.SEIT_LOXBERRY_3_BESTANDTEIL_DES'); ?></b> <?php echo mi_t('UI.UND_MUSS_NICHT_NACHINSTALLIERT_WERDEN'); ?> <i><?php echo mi_t('UI.SYSTEM_MQTT_GATEWAY_2'); ?></i>
(<span class="sm-mono"><?php echo mi_t('UI.ADMIN_SYSTEM_MQTT_CGI_2'); ?></span>).<br><br>
<b><?php echo mi_t('UI.UDP'); ?></b> <?php echo mi_t('UI.KOMMT_OHNE_DEN_GATEWAY_AUS'); ?><br><br>
<b><?php echo mi_t('UI.DAS_PLUGIN_SENDET_IMMER_BEIDES'); ?></b> <?php echo mi_t('UI.DIE_ENTSCHEIDUNG_F_LLT_ALLEIN'); ?>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_2_ABO_IM_MQTT'); ?></b><br><br>
<?php echo mi_t('UI.NUR_F_R_DEN_MQTT'); ?> <i><?php echo mi_t('UI.SYSTEM_EINSTELLUNGEN_MQTT_GATEWAY_ABONNEMENT'); ?></i> <?php echo mi_t('UI.EINTRAGEN'); ?>
<pre class="sm-pre"><?php echo mi_e($mi_topic); ?>/#</pre>
<b><?php echo mi_t('UI.OHNE_DIESEN_EINTRAG_KOMMT_NICHTS'); ?></b> <?php echo mi_t('UI.DANACH_ZEIGT_DAS_GATEWAY_UNTER'); ?>
<i><?php echo mi_t('UI.EINGEHENDE_DATEN'); ?></i> <?php echo mi_t('UI.DIE_ERZEUGTEN_NAMEN_DIE_DORT'); ?>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_3_VIRTUELLE_EING_NGE'); ?></b><br><br>
In <b><?php echo mi_t('UI.LOXONE_CONFIG'); ?></b><?php echo mi_t('UI.MINISERVER_ANKLICKEN'); ?> <i><?php echo mi_t('UI.VIRTUELLE_EING_NGE'); ?></i> <?php echo mi_t('UI.JE_WERT_EINEN_EINGANG'); ?> <b><?php echo mi_t('UI.DER_TITEL_MUSS_EXAKT_STIMMEN'); ?></b> &mdash; das Gateway ordnet ausschlie&szlig;lich &uuml;ber den Namen
zu.
<?php foreach (($mi_geraete ?: array(array('id' => $mi_bsp, 'name' => 'Klimager&auml;t'))) as $d) { ?>
<p class="sm-small"><?php echo mi_t('UI.KLIMAGER_T'); ?> <b><?php echo $d['name']; ?></b>
(ID <span class="sm-mono"><?php echo mi_e($d['id']); ?></span>):</p>
<table class="sm-tbl">
<tr><th><?php echo mi_t('UI.TITEL_DES_VIRTUELLEN_EINGANGS'); ?></th><th style="width:12%"><?php echo mi_t('UI.EINHEIT_2'); ?></th><th style="width:34%"><?php echo mi_t('UI.BEDEUTUNG_2'); ?></th></tr>
<?php foreach (mi_werte() as $wert => $info) { ?>
<tr><td class="sm-mono"><?php echo mi_e($mi_topic . '_' . $d['id'] . '_' . $wert); ?></td>
    <td><?php echo $info[0]; ?></td><td><?php echo $info[1]; ?></td></tr>
<?php } ?>
</table>
<?php } ?>
<p class="sm-small"><b><?php echo mi_t('UI.DER_UDP_WEG'); ?></b> <?php echo mi_t('UI.STATTDESSEN_EINEN'); ?> <i><?php echo mi_t('UI.VIRTUELLEN_UDP_EINGANG'); ?></i> <?php echo mi_t('UI.AUF_PORT'); ?> <b><?php echo mi_e($mi_port); ?></b> <?php echo mi_t('UI.ANLEGEN_JE_WERT_EINEN_BEFEHL'); ?></p>
<pre class="sm-pre">\iMidea/<?php echo mi_e($mi_bsp); ?>/indoor_temperature,\i\v</pre>
<p class="sm-small"><?php echo mi_t('UI.DAS_MUSTER_HEI_T_SUCHE'); ?> <span class="sm-mono">\i</span> <?php echo mi_t('UI.UND_NIMM_DIE_ZAHL_DIE'); ?><span class="sm-mono">\v</span><?php echo mi_t('UI.TEXT'); ?></p>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_4_BEFEHLE_AN_DAS'); ?></b><br><br>
<?php echo mi_t('UI.ZUM_SCHALTEN_LEGT_MAN_EINEN'); ?> <i><?php echo mi_t('UI.VIRTUELLEN_AUSGANG'); ?></i> <?php echo mi_t('UI.AN_ADRESSE'); ?>
<pre class="sm-pre">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></pre>
<?php echo mi_t('UI.DARUNTER_JE_BEFEHL_EINEN'); ?> <i><?php echo mi_t('UI.VIRTUELLEN_AUSGANG_BEFEHL'); ?></i><?php echo mi_t('UI.DER_BEFEHLSTEXT_BESTEHT_AUS_DER'); ?>
<table class="sm-tbl">
<tr><th style="width:34%"><?php echo mi_t('UI.WAS'); ?></th><th><?php echo mi_t('UI.BEFEHL_BEI_EIN'); ?></th></tr>
<tr><td><?php echo mi_t('UI.EINSCHALTEN'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True</td></tr>
<tr><td><?php echo mi_t('UI.AUSSCHALTEN'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,False</td></tr>
<tr><td><?php echo mi_t('UI.K_HLEN_22_C'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.cool,22</td></tr>
<tr><td><?php echo mi_t('UI.HEIZEN_21_C'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.heat,21</td></tr>
<tr><td><?php echo mi_t('UI.L_FTERSTUFE_LEISE'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,ac.fan_speed_enum.Silent</td></tr>
<tr><td><?php echo mi_t('UI.SCHWENKEN_SENKRECHT'); ?></td><td class="sm-mono"><?php echo mi_e($mi_bsp); ?>,ac.swing_mode_enum.Vertical</td></tr>
</table>
<b><?php echo mi_t('UI.SOLLTEMPERATUR_STUFENLOS'); ?></b> <?php echo mi_t('UI.IM_BEFEHLSTEXT'); ?> <span class="sm-mono">&lt;v&gt;</span>
<?php echo mi_t('UI.ALS_PLATZHALTER_BENUTZEN_ZUM_BEISPIEL'); ?>
<span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True,ac.operational_mode_enum.cool,&lt;v&gt;</span>
<?php echo mi_t('UI.DANN_SETZT_DER_ANGESCHLOSSENE_ANALOGWERT'); ?>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_5_MERKEN_WENN_EIN'); ?></b><br><br>
<?php echo mi_t('UI.F_LLT_EIN_KLIMAGER_T'); ?> <b><?php echo mi_t('UI.LETZTEN_WERT'); ?></b><?php echo mi_t('UI.IN_DER_APP_SIEHT_DANN'); ?> <span class="sm-mono"><?php echo mi_t('UI.ONLINE'); ?></span><?php echo mi_t('UI.SOLANGE_ES'); ?>
<span class="sm-mono"><?php echo mi_t('UI.WERT_TRUE'); ?></span> <?php echo mi_t('UI.MELDET_IST_DAS_GER_T'); ?>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_6_KOMPLETTE_BAUSTEIN_LISTE'); ?></b><br><br>
<?php echo mi_t('UI.SO_SIEHT_DIE_VOLLST_NDIGE'); ?> <b><?php echo mi_t('UI.EIN_2'); ?></b> <?php echo mi_t('UI.KLIMAGER_T_AUF_DER_PROGRAMMIERSEITE'); ?>
<table class="sm-tbl">
<tr><th>#</th><th><?php echo mi_t('UI.BAUSTEIN_TYP'); ?></th><th><?php echo mi_t('UI.NAME_VORSCHLAG'); ?></th><th><?php echo mi_t('UI.PARAMETER'); ?></th><th><?php echo mi_t('UI.EING_NGE_VERBINDEN_MIT'); ?></th></tr>
<tr><td>1</td><td><?php echo mi_t('UI.VIRTUELLER_EINGANG'); ?></td><td class="sm-mono"><?php echo mi_t('UI.INDOOR_TEMPERATURE'); ?></td><td><?php echo mi_t('UI.EINHEIT_C_1_NACHKOMMASTELLE'); ?></td><td><?php echo mi_t('UI.KOMMT_BER_DAS_GATEWAY'); ?></td></tr>
<tr><td>2</td><td><?php echo mi_t('UI.VIRTUELLER_EINGANG_2'); ?></td><td class="sm-mono"><?php echo mi_t('UI.OUTDOOR_TEMPERATURE'); ?></td><td><?php echo mi_t('UI.EINHEIT_C'); ?></td><td><?php echo mi_t('UI.TEXT_2'); ?></td></tr>
<tr><td>3</td><td><?php echo mi_t('UI.VIRTUELLER_EINGANG_3'); ?></td><td class="sm-mono"><?php echo mi_t('UI.TARGET_TEMPERATURE'); ?></td><td><?php echo mi_t('UI.EINHEIT_C_2'); ?></td><td><?php echo mi_t('UI.TEXT_3'); ?></td></tr>
<tr><td>4</td><td><?php echo mi_t('UI.VIRTUELLER_EINGANG_4'); ?></td><td class="sm-mono"><?php echo mi_t('UI.POWER_STATE'); ?></td><td><?php echo mi_t('UI.DIGITAL_TRUE_FALSE'); ?></td><td><?php echo mi_t('UI.TEXT_4'); ?></td></tr>
<tr><td>5</td><td><?php echo mi_t('UI.VIRTUELLER_EINGANG_5'); ?></td><td class="sm-mono"><?php echo mi_t('UI.ONLINE_2'); ?></td><td><?php echo mi_t('UI.DIGITAL'); ?></td><td><?php echo mi_t('UI.TEXT_5'); ?></td></tr>
<tr><td>6</td><td><?php echo mi_t('UI.VIRTUELLER_AUSGANG'); ?></td><td><?php echo mi_t('UI.MIDEA2LOX'); ?></td><td><?php echo mi_t('UI.ADRESSE'); ?> <span class="sm-mono">/dev/udp/<?php echo mi_e($mi_ip); ?>/<?php echo mi_e($mi_port); ?></span></td><td><?php echo mi_t('UI.TEXT_6'); ?></td></tr>
<tr><td>7</td><td><?php echo mi_t('UI.VIRT_AUSGANG_BEFEHL'); ?></td><td><?php echo mi_t('UI.KLIMA_EIN_AUS'); ?></td><td><?php echo mi_t('UI.EIN_3'); ?> <span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,True</span> <?php echo mi_t('UI.AUS_2'); ?> <span class="sm-mono"><?php echo mi_e($mi_bsp); ?>,False</span></td><td><?php echo mi_t('UI.VOM_SCHALTER_BAUSTEIN'); ?></td></tr>
<tr><td>8</td><td><?php echo mi_t('UI.NICHT_NOT'); ?></td><td><?php echo mi_t('UI.GER_T_OFFLINE'); ?></td><td><?php echo mi_t('UI.TEXT_7'); ?></td><td><?php echo mi_t('UI.EINGANG_5'); ?></td></tr>
<tr><td>9</td><td><?php echo mi_t('UI.EINSCHALTVERZ_GERUNG'); ?></td><td><?php echo mi_t('UI.AUSFALL_BEST_TIGT'); ?></td><td><?php echo mi_t('UI.VERZ_GERUNG'); ?> <b>600</b> s</td><td><?php echo mi_t('UI.EINGANG_8'); ?></td></tr>
<tr><td>10</td><td><?php echo mi_t('UI.BENACHRICHTIGUNG'); ?></td><td><?php echo mi_t('UI.KLIMAGER_T_PR_FEN'); ?></td><td><?php echo mi_t('UI.TEXT_Z_B_DAS_KLIMAGER'); ?></td><td><?php echo mi_t('UI.T9'); ?></td></tr>
<tr><td>11</td><td><?php echo mi_t('UI.STATUS'); ?></td><td><?php echo mi_t('UI.KLIMA_WOHNZIMMER'); ?></td><td><?php echo mi_t('UI.STATUSTEXT_SIEHE_UNTEN_VISUALISIERUNG_EIN'); ?></td><td>v1 = #1, v2 = #3, v3 = #4</td></tr>
<tr><td>12 <i><?php echo mi_t('UI.OPTIONAL'); ?></i></td><td><?php echo mi_t('UI.INTELLIGENTE_RAUMREGELUNG'); ?></td><td><?php echo mi_t('UI.KLIMA_WOHNZIMMER_2'); ?></td><td><?php echo mi_t('UI.BETRIEBSART_K_HLEN'); ?></td><td><?php echo mi_t('UI.IST_TEMPERATUR_1_STELLGR_E'); ?></td></tr>
<tr><td>13 <i><?php echo mi_t('UI.OPTIONAL_2'); ?></i></td><td><?php echo mi_t('UI.MERKER'); ?></td><td><?php echo mi_t('UI.KLIMA_FREIGABE'); ?></td><td><?php echo mi_t('UI.TEXT_8'); ?></td><td><?php echo mi_t('UI.SPERRT_7_BEI_OFFENEM_FENSTER'); ?></td></tr>
<tr><td>14 <i><?php echo mi_t('UI.OPTIONAL_3'); ?></i></td><td><?php echo mi_t('UI.FORMEL'); ?></td><td><?php echo mi_t('UI.TEMPERATURSPREIZUNG'); ?></td><td><?php echo mi_t('UI.FORMEL_2'); ?> <span class="sm-mono">I1-I2</span></td><td>I1 = #1, I2 = #2</td></tr>
</table>
<br>
<b><?php echo mi_t('UI.STATUSTEXT_F_R_11'); ?></b>
<pre class="sm-pre"><?php echo mi_t('UI.RAUM_V1_1_C_SOLL'); ?></pre>
<b>Zu #9:</b> <?php echo mi_t('UI.DIE_VERZ_GERUNG_MUSS_DEUTLICH'); ?><br>
<b>Zu #10:</b> <?php echo mi_t('UI.DER_BENACHRICHTIGUNGS_BAUSTEIN_SENDET_NUR'); ?><br>
<b>Zu #12:</b> <?php echo mi_t('UI.DIE_RAUMREGELUNG_IST_DER_ELEGANTE'); ?><br>
<b>Zu #13:</b> <?php echo mi_t('UI.EIN_OFFENES_FENSTER_SOLLTE_DAS'); ?>
</div>

<div class="sm-step"><b><?php echo mi_t('UI.SCHRITT_7_GEGENPROBE'); ?></b><br><br>
<?php echo mi_t('UI.NACH_DEM_SPEICHERN_IN_LOXONE'); ?> <i><?php echo mi_t('UI.TEST'); ?></i> <?php echo mi_t('UI.AUF'); ?> <i><?php echo mi_t('UI.ZUSTAND_ALLER_GER_TE_ABFRAGEN'); ?></i> <?php echo mi_t('UI.KLICKEN_ERSCHEINEN_DORT_WERTE_SENDET'); ?>
</div>
</div>

<!-- ================================= Test ================================= -->
<div class="sm-pane<?php echo $mi_tab === 'tab-test' ? ' sm-active' : ''; ?>" id="tab-test">

<?php if ($mi_test_titel !== '') { ?>
<div class="sm-alert sm-ok"><b><?php echo $mi_test_titel; ?></b></div>
<?php echo $mi_test_text; ?>
<?php } ?>

<h2><?php echo mi_e(mi_t('TEST.HEAD_SELFCHECK')); ?></h2>
<table class="sm-tbl">
<tr><th style="width:44%"><?php echo mi_t('UI.FRAGE'); ?></th><th><?php echo mi_t('UI.ANTWORT'); ?></th></tr>
<?php
require_once __DIR__ . '/mi_test.php';
foreach (mi_pruefungen($mi_cfg) as $c) { ?>
<tr><td><?php echo $c[0]; ?></td>
    <td><?php echo ($c[1] ? '&#10004; ' : '&#10008; ') . $c[2]; ?></td></tr>
<?php } ?>
</table>

<h2><?php echo mi_t('UI.NACHSEHEN'); ?></h2>
<div class="sm-knopfreihe sm-b-lesen">
<?php foreach (array('umgebung' => 'Umgebung pr&uuml;fen',
                     'status'   => 'Zustand aller Ger&auml;te abfragen') as $wert => $text) { ?>
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="<?php echo mi_e($wert); ?>"><?php
      echo $text; ?></button>
  </form>
<?php } ?>
</div>

<h2><?php echo mi_t('UI.GER_TESUCHE_2'); ?></h2>
<p class="sm-small"><?php echo mi_t('UI.SPRICHT_MIT_DEM_MIDEA_KONTO'); ?> <span class="sm-mono"><?php echo mi_t('UI.DEVICES_CFG'); ?></span>.</p>
<div class="sm-knopfreihe sm-b-aktion">
  <form method="post" action="index.php">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" type="submit" name="test" value="discover"><?php echo mi_t('UI.JETZT_NACH_GER_TEN_SUCHEN'); ?></button>
  </form>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo mi_t('UI.ANSEHEN_FRAGT_NUR_AB_VER'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo mi_t('UI.L_ST_ETWAS_AUS_SPRICHT'); ?></span>
</div>
</div>

<!-- ============================== Logdateien ============================== -->
<div class="sm-pane<?php echo $mi_tab === 'tab-log' ? ' sm-active' : ''; ?>" id="tab-log">
<h2><?php echo mi_t('UI.LOGDATEIEN'); ?></h2>
<?php
if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) {
    echo LBWeb::loglist_html();
} else { ?>
<p class="sm-small"><?php echo mi_t('UI.NEUESTE_ZEILE_OBEN_DATEI'); ?>
<span class="sm-mono"><?php echo mi_e($mi_p['log']); ?></span></p>
<?php if (!$mi_zeilen) { ?>
<div class="sm-alert sm-info"><?php echo mi_t('UI.DIE_PROTOKOLLDATEI_IST_LEER_ODER'); ?></div>
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
        reiter[k].addEventListener('click', function (e) {
            e.preventDefault();
            zeige(this.getAttribute('data-ziel'));
        });
    }
    zeige(<?php echo json_encode($mi_tab); ?>);
})();
</script>

<?php LBWeb::lbfooter(); ?>
