<?php
/**
 * Leitura das respostas gravadas — Catapulta de Ideias.
 *
 *   GET /_form/ler.php?c=<slug>&k=<token de leitura>[&formato=csv][&desde=AAAA-MM-DD]
 *
 * É a fonte da sincronização com a planilha de backup e com a Sphere.
 * Devolve as respostas deduplicadas por e-mail (a última preenchida vence) e,
 * separadamente, o histórico bruto — se alguém preencher duas vezes, a segunda
 * é a boa, mas a primeira não some.
 */

const BASE_DADOS = '/home/u346131448/dados/formularios';
date_default_timezone_set('America/Sao_Paulo');

function fim(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

$c = (string)($_GET['c'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, 'slug invalido');
$dir = BASE_DADOS . '/' . $c;
if (!is_file($dir . '/config.php')) fim(404, 'formulario nao configurado');
$cfg = require $dir . '/config.php';

if (!hash_equals((string)($cfg['token_leitura'] ?? ''), (string)($_GET['k'] ?? ''))) fim(401, 'token invalido');

$desde  = (string)($_GET['desde'] ?? '');
$brutas = [];
foreach (file($dir . '/respostas.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $r = json_decode($l, true);
    if (!is_array($r) || empty($r['id'])) continue;
    if ($desde !== '' && substr((string)$r['recebido_em'], 0, 10) < $desde) continue;
    $brutas[] = $r;
}
usort($brutas, fn($a, $b) => strcmp($a['recebido_em'], $b['recebido_em']));

$unicas = [];
foreach ($brutas as $r) $unicas[strtolower($r['email'])] = $r;   // a última sobrescreve
$unicas = array_values($unicas);

$COLUNAS = ['recebido_em','nome','whatsapp','whatsapp_e164','email','link','profissao',
            'estagio','projeto','seis_meses','id'];

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $c . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                                 // BOM: Excel/Sheets lê acento
    // $escape explícito: sem ele o PHP 8.4+ emite deprecation no meio do CSV
    fputcsv($out, $COLUNAS, ',', '"', '\\');
    foreach ($unicas as $r) fputcsv($out, array_map(fn($k) => (string)($r[$k] ?? ''), $COLUNAS), ',', '"', '\\');
    exit;
}

/* Alertas — o que o receptor não resolve sozinho (e-mail que não casa com a base,
   comprador sem a tag de pagamento). É a única saída daqui que alguém precisa LER. */
$alertas = [];
if (is_file($dir . '/alertas.jsonl')) {
    foreach (file($dir . '/alertas.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $a = json_decode($l, true);
        if (is_array($a)) $alertas[] = $a;
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'          => true,
    'atualizado'  => date('c'),
    'formulario'  => $c,
    'colunas'     => $COLUNAS,
    'total'       => count($unicas),
    'reenvios'    => count($brutas) - count($unicas),
    'alertas'     => $alertas,
    'respostas'   => $unicas,
    'historico'   => $brutas,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
