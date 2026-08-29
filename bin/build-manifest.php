<?php
/**
 * Rigenera manifest.json: elenco ordinato dei pattern + conteggio.
 * Output deterministico (nessun timestamp) → nessun commit spurio quando nulla è cambiato.
 *
 *   php bin/build-manifest.php
 */

$root  = dirname(__DIR__);
$files = array_map(
    static function ($p) { return 'patterns/' . basename($p); },
    glob($root . '/patterns/*.json')
);
sort($files);

$json = json_encode(
    array('count' => count($files), 'files' => array_values($files)),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";

$path = $root . '/manifest.json';
$old  = is_file($path) ? file_get_contents($path) : '';

if ($old === $json) {
    echo 'manifest.json già aggiornato (' . count($files) . " pattern).\n";
    exit(0);
}

file_put_contents($path, $json);
echo 'manifest.json rigenerato (' . count($files) . " pattern).\n";
