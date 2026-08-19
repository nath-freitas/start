<?php
/**
 * Bootstrap de UM cliente do receptor de vendas — Catapulta de Ideias.
 *
 * Existe porque o dado vive FORA da pasta publicada (/home/<user>/dados/…) e o
 * deploy só escreve dentro dela: sem SSH, este é o único jeito de criar a pasta
 * e o config.php do cliente. Protegido por chave própria (CHAVE_SETUP).
 *
 *   GET /_ingest/setup.php?k=<CHAVE_SETUP>&c=<slug>&acao=status
 *   GET /_ingest/setup.php?k=<CHAVE_SETUP>&c=<slug>&acao=criar
 *          &hottok=<token da Hotmart>&leitura=<token de leitura>[&force=1]
 *
 * Depois de configurado, REMOVA este arquivo da branch: o próximo deploy
 * (destrutivo) o apaga do servidor e a chave deixa de existir.
 */

const BASE_DADOS  = '/home/u346131448/dados/vendas';
const CHAVE_SETUP = 'IBcnEZjkP6KarI0-frW5XdgJphlNktYU';

header('Content-Type: application/json; charset=utf-8');

function fim(int $code, array $corpo): void {
    http_response_code($code);
    echo json_encode($corpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals(CHAVE_SETUP, (string)($_GET['k'] ?? ''))) fim(401, ['ok' => false, 'msg' => 'chave invalida']);

$c = (string)($_GET['c'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, ['ok' => false, 'msg' => 'slug invalido']);
$dir = BASE_DADOS . '/' . $c;

$acao = (string)($_GET['acao'] ?? 'status');

if ($acao === 'status') {
    $jsonl = $dir . '/vendas.jsonl';
    fim(200, [
        'ok'             => true,
        'cliente'        => $c,
        'pasta'          => $dir,
        'pasta_existe'   => is_dir($dir),
        'config_existe'  => is_file($dir . '/config.php'),
        'gravavel'       => is_dir($dir) && is_writable($dir),
        'linhas'         => is_file($jsonl) ? count(file($jsonl, FILE_SKIP_EMPTY_LINES)) : 0,
        'eventos_vistos' => is_file($dir . '/eventos-vistos.json')
            ? json_decode(file_get_contents($dir . '/eventos-vistos.json'), true) : null,
        'estrutura'      => is_file($dir . '/estrutura.json')
            ? json_decode(file_get_contents($dir . '/estrutura.json'), true) : null,
        'php'            => PHP_VERSION,
    ]);
}

if ($acao !== 'criar') fim(400, ['ok' => false, 'msg' => 'acao desconhecida']);

$hottok  = (string)($_GET['hottok'] ?? '');
$leitura = (string)($_GET['leitura'] ?? '');
if ($hottok === '' || $leitura === '') fim(400, ['ok' => false, 'msg' => 'hottok e leitura sao obrigatorios']);
if (is_file($dir . '/config.php') && ($_GET['force'] ?? '') !== '1') {
    fim(409, ['ok' => false, 'msg' => 'config ja existe; use force=1 para sobrescrever']);
}

if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui criar ' . $dir]);
}

$php = "<?php\n// Gerado pelo setup em " . date('c') . ". NAO versionar.\nreturn " . var_export([
    'hottok'           => $hottok,
    'token_leitura'    => $leitura,
    'produtos'         => [],          // id da Hotmart => slug curto (opcional)
    'regra_pago'       => '/^\d+$/',   // 2o campo do sck numerico = id do conjunto = pago
    'mapear_estrutura' => true,        // grava estrutura.json no 1o webhook (so nomes e tipos)
], true) . ";\n";

if (file_put_contents($dir . '/config.php', $php, LOCK_EX) === false) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui escrever config.php']);
}
@chmod($dir . '/config.php', 0640);

fim(200, ['ok' => true, 'msg' => 'cliente configurado', 'pasta' => $dir]);
