<?php
/**
 * Leitura das vendas gravadas pelo receptor — Catapulta de Ideias.
 *   GET /_ingest/vendas.php?c=<slug>&k=<token de leitura>[&formato=csv][&desde=AAAA-MM-DD]
 *
 * Devolve as transações já deduplicadas por evento_id, com reembolso/chargeback
 * aplicados, e um bloco de totais por produto e por origem de tráfego.
 * É a fonte do dashboard e da sincronização com a Sphere. Não há PII para vazar:
 * o arquivo de origem nunca teve.
 */

const BASE_DADOS = '/home/u346131448/dados/vendas';
date_default_timezone_set('America/Sao_Paulo');   // senão 'atualizado' sai em UTC

function fim(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'msg' => $msg]);
    exit;
}

$c = $_GET['c'] ?? '';
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, 'cliente invalido');
$dir = BASE_DADOS . '/' . $c;
if (!is_file($dir . '/config.php')) fim(404, 'cliente nao configurado');
$cfg = require $dir . '/config.php';

if (!hash_equals((string)($cfg['token_leitura'] ?? ''), (string)($_GET['k'] ?? ''))) fim(401, 'token invalido');

$desde = $_GET['desde'] ?? '';
$linhas = [];
foreach (file($dir . '/vendas.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
    $r = json_decode($l, true);
    if (!is_array($r) || !isset($r['evento_id'])) continue;
    if ($desde !== '' && substr((string)$r['data_hora'], 0, 10) < $desde) continue;
    $linhas[$r['evento_id']] = $r;            // dedup: retry da Hotmart sobrescreve, não soma
}

// Estado final por transação: uma venda aprovada que depois foi reembolsada sai da conta.
$estado = [];
foreach ($linhas as $r) {
    $t = $r['transacao'];
    if ($r['evento'] === 'aprovada') {
        $estado[$t] = ($estado[$t] ?? []) + $r;
        $estado[$t]['status'] = $estado[$t]['status'] ?? 'aprovada';
    } else {
        $estado[$t] = array_merge($estado[$t] ?? $r, ['status' => $r['evento'], 'estornada_em' => $r['data_hora']]);
    }
}
$transacoes = array_values($estado);
usort($transacoes, fn($a, $b) => strcmp($a['data_hora'], $b['data_hora']));

$validas = array_filter($transacoes, fn($r) => ($r['status'] ?? '') === 'aprovada');

$por = function (string $campo) use ($validas): array {
    $out = [];
    foreach ($validas as $r) {
        $k = (string)($r[$campo] ?? '');
        $out[$k]['vendas'] = ($out[$k]['vendas'] ?? 0) + 1;
        $out[$k]['valor']  = round(($out[$k]['valor'] ?? 0) + (float)$r['valor'], 2);
    }
    return $out;
};

if (($_GET['formato'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['data_hora', 'transacao', 'pedido', 'produto', 'valor', 'valor_liquido', 'origem', 'trafego', 'status']);
    foreach ($transacoes as $r) {
        fputcsv($out, [$r['data_hora'], $r['transacao'], $r['pedido'], $r['produto'],
                       $r['valor'], $r['valor_liquido'] ?? '', $r['origem'], $r['trafego'], $r['status'] ?? '']);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok'          => true,
    'atualizado'  => date('c'),
    'totais'      => [
        'transacoes' => count($validas),
        // 'valor' é o BRUTO do produtor. 'liquido' é o repasse da plataforma.
        // Nunca somar valor_total (traz juros de parcelamento do comprador).
        'valor'      => round(array_sum(array_map(fn($r) => (float)$r['valor'], $validas)), 2),
        'liquido'    => round(array_sum(array_map(fn($r) => (float)($r['valor_liquido'] ?? 0), $validas)), 2),
        'estornos'   => count($transacoes) - count($validas),
    ],
    'por_produto' => $por('produto'),
    'por_trafego' => $por('trafego'),
    'por_origem'  => $por('origem'),
    'transacoes'  => $transacoes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
