<?php
/**
 * Midea2Lox - Bruecke vom Dienst zur gemeinsamen Sprachausgabe (seit 4.5.19).
 * Aufruf: php mi_ansage.php <anlass> <0|1> <pluginordner> [name] [frist]. Anlaesse: 'ausfall', 'fehler'. Legt nur hin und kehrt sofort zurueck; es spricht ein eigener Vorgang.
 * Ausgabe: eine ASCII-Zeile, nie ein Text oder Token. Rueckgabe 0 gelegt oder nichts zu tun, 1 nicht ausfuehrbar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur Kommandozeile.\n");
}
$mi_a = isset($argv[1]) ? (string) $argv[1] : '';
$mi_g = isset($argv[2]) ? (string) $argv[2] : '';
$mi_o = isset($argv[3]) ? (string) $argv[3] : '';
$mi_n = isset($argv[4]) ? (string) $argv[4] : '';
$mi_f = isset($argv[5]) ? (int) $argv[5] : 0;
if (!in_array($mi_a, array('ausfall', 'fehler'), true) || ($mi_g !== '0' && $mi_g !== '1') || preg_match('/^[A-Za-z0-9_-]{1,64}\z/', $mi_o) !== 1) {
    echo "ANSAGE;STAND=0;KENNUNG=AUFRUF\n";
    exit(1);
}
$mi_h = getenv('LBHOMEDIR');
if (!$mi_h || !is_dir($mi_h . '/config/plugins')) {
    $mi_h = dirname(dirname(dirname(__DIR__)));
}
$mi_lib = $mi_h . '/webfrontend/htmlauth/plugins/' . $mi_o . '/mi_lib.php';
if (!is_file($mi_lib)) {
    echo "ANSAGE;STAND=0;KENNUNG=MODUL_FEHLT\n";
    exit(1);
}
putenv('LBHOMEDIR=' . $mi_h);
putenv('LBPPLUGINDIR=' . $mi_o);
require_once $mi_lib;
echo 'ANSAGE;STAND=1;KENNUNG=' . strtoupper((string) mi_ansage_flanke($mi_a, $mi_g === '1', $mi_n, $mi_f)) . "\n";
exit(0);
