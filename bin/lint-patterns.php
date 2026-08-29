<?php
/**
 * CI — linta ogni patterns/<slug>.json con il ruleset condiviso (bin/linter.php).
 * Exit 1 se un file ha errori. Gli avvisi non bloccano.
 *
 *   php bin/lint-patterns.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!function_exists('__')) {
    function __($s, $d = null) { return $s; }
}
if (!function_exists('_n')) {
    function _n($a, $b, $n, $d = null) { return 1 == $n ? $a : $b; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower(preg_replace('/[^a-z0-9_-]/', '', (string) $k)); }
}

require __DIR__ . '/linter.php';

$root  = dirname(__DIR__);
$files = glob($root . '/patterns/*.json');
sort($files);

if (!$files) {
    fwrite(STDERR, "Nessun file in patterns/\n");
    exit(1);
}

$fail       = 0;
$warn_total = 0;

foreach ($files as $f) {
    $name = basename($f);
    $d    = json_decode((string) file_get_contents($f), true);

    if (!is_array($d)) {
        echo "x  {$name} — JSON non valido\n";
        $fail++;
        continue;
    }
    if (empty($d['content'])) {
        echo "x  {$name} — manca la chiave \"content\"\n";
        $fail++;
        continue;
    }

    $meta = array(
        'title'         => isset($d['title']) ? $d['title'] : '',
        'slug'          => isset($d['slug']) ? $d['slug'] : ('lfw/' . basename($f, '.json')),
        'categories'    => isset($d['categories']) ? $d['categories'] : array(),
        'keywords'      => isset($d['keywords']) ? $d['keywords'] : array(),
        'viewportWidth' => isset($d['viewportWidth']) ? $d['viewportWidth'] : 0,
        'description'   => isset($d['description']) ? $d['description'] : '',
    );

    $r = lfw_patterns_lint($d['content'], $meta);
    $e = count($r['errors']);
    $w = count($r['warnings']);
    $warn_total += $w;

    $mark = $e ? 'x ' : ($w ? '! ' : 'ok');
    printf("%s %-46s %s\n", $mark, $name, lfw_patterns_lint_summary($r));

    foreach ($r['errors'] as $x) {
        echo "     ERRORE  {$x['code']}: {$x['msg']}\n";
        if (!empty($x['items'])) {
            echo "             " . implode('  ', $x['items']) . "\n";
        }
    }
    foreach ($r['warnings'] as $x) {
        echo "     avviso  {$x['code']}: {$x['msg']}\n";
    }

    if ($e) {
        $fail++;
    }
}

echo "\n";
if ($fail) {
    echo "FALLITO: {$fail} file con errori.\n";
    exit(1);
}
echo 'OK: ' . count($files) . ' pattern, 0 errori'
    . ($warn_total ? ", {$warn_total} avvisi (non bloccanti)" : '') . ".\n";
exit(0);
