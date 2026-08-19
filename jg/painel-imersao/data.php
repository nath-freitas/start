<?php
/**
 * JSON do painel. O acesso é protegido pelo basic auth da pasta /jg/ (Apache),
 * então aqui não há segunda senha — o que existe é o cache de 1h, que é o que
 * dispensa cron e mantém o painel barato.
 *   GET data.php            -> serve o cache se tiver menos de 1h
 *   GET data.php?forcar=1   -> ignora o cache e recoleta
 */
require __DIR__ . '/lib.php';

ini_set('serialize_precision', '-1');   // evita 33.840000000000003 no JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    if (!is_readable(PAINEL_CONFIG)) {
        throw new Exception('config do painel ausente — rode o bootstrap.php');
    }
    $CFG = require PAINEL_CONFIG;
    date_default_timezone_set($CFG['tz']);
    echo json_encode(dash_payload($CFG, isset($_GET['forcar'])), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
