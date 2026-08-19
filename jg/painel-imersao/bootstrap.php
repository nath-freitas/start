<?php
/**
 * Bootstrap do painel — Catapulta de Ideias.
 *
 * Existe pela mesma razão que o setup.php do receptor: o config com credenciais
 * tem de viver FORA da pasta publicada (o deploy é destrutivo e o repositório é
 * público) e o deploy por Git só escreve dentro dela. Sem SSH, este é o caminho.
 * Protegido por chave própria.
 *
 *   GET  bootstrap.php?k=<CHAVE>&acao=status
 *   POST bootstrap.php?k=<CHAVE>&acao=config          corpo = JSON com os campos a gravar
 *   POST bootstrap.php?k=<CHAVE>&acao=meta            corpo = snapshot de mídia {conta, linhas}
 *   GET  bootstrap.php?k=<CHAVE>&acao=limpar-cache
 *
 * REMOVER DA BRANCH depois de configurado: o próximo deploy apaga o arquivo e a
 * chave morre junto.
 */

const PASTA  = '/home/u346131448/dados/painel/jorge-grimberg';
const CHAVE  = 'wDMv2qYfR7cKp0LsXtEg9AhNjZ4Bu6Tn';

header('Content-Type: application/json; charset=utf-8');

function fim(int $code, array $corpo): void {
    http_response_code($code);
    echo json_encode($corpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!hash_equals(CHAVE, (string) ($_GET['k'] ?? ''))) fim(401, ['ok' => false, 'msg' => 'chave invalida']);

if (!is_dir(PASTA) && !@mkdir(PASTA, 0750, true) && !is_dir(PASTA)) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui criar ' . PASTA]);
}

$acao = (string) ($_GET['acao'] ?? 'status');

if ($acao === 'status') {
    $cfg = is_readable(PASTA . '/config.php') ? require PASTA . '/config.php' : null;
    $snap = is_readable(PASTA . '/meta-snapshot.json')
        ? json_decode((string) file_get_contents(PASTA . '/meta-snapshot.json'), true) : null;
    fim(200, [
        'ok'            => true,
        'pasta'         => PASTA,
        'gravavel'      => is_writable(PASTA),
        'config_existe' => $cfg !== null,
        // nunca devolve o token; só se existe e o tamanho
        'meta_token'    => $cfg ? (empty($cfg['meta_token']) ? 'ausente' : 'presente (' . strlen($cfg['meta_token']) . ' chars)') : null,
        'vendas_legivel'=> $cfg ? is_readable($cfg['vendas_arquivo']) : null,
        'snapshot_em'   => $snap['em'] ?? null,
        'snapshot_linhas' => isset($snap['linhas']) ? count($snap['linhas']) : 0,
        'cache_em'      => is_file(PASTA . '/cache.json') ? date('c', filemtime(PASTA . '/cache.json')) : null,
        'php'           => PHP_VERSION,
    ]);
}

if ($acao === 'limpar-cache') {
    $ok = is_file(PASTA . '/cache.json') && @unlink(PASTA . '/cache.json');
    fim(200, ['ok' => true, 'cache_apagado' => $ok]);
}

$corpo = file_get_contents('php://input');
$json  = json_decode((string) $corpo, true);
if (!is_array($json)) fim(400, ['ok' => false, 'msg' => 'corpo nao e JSON valido']);

if ($acao === 'meta') {
    if (!isset($json['linhas']) || !is_array($json['linhas'])) {
        fim(400, ['ok' => false, 'msg' => 'esperava {conta, linhas[]}']);
    }
    $json['em'] = date('c');
    if (file_put_contents(PASTA . '/meta-snapshot.json', json_encode($json), LOCK_EX) === false) {
        fim(500, ['ok' => false, 'msg' => 'nao consegui gravar o snapshot']);
    }
    @unlink(PASTA . '/cache.json');     // o painel tem de recoletar com o dado novo
    fim(200, ['ok' => true, 'linhas' => count($json['linhas']), 'em' => $json['em']]);
}

if ($acao === 'config') {
    // Mescla com o que já existe: dá para atualizar só o meta_token depois.
    $atual = is_readable(PASTA . '/config.php') ? require PASTA . '/config.php' : [];
    $novo  = array_merge(is_array($atual) ? $atual : [], $json);
    $php   = "<?php\n// Gerado pelo bootstrap em " . date('c') . ". NAO versionar.\nreturn "
           . var_export($novo, true) . ";\n";
    if (file_put_contents(PASTA . '/config.php', $php, LOCK_EX) === false) {
        fim(500, ['ok' => false, 'msg' => 'nao consegui gravar o config']);
    }
    @chmod(PASTA . '/config.php', 0640);
    @unlink(PASTA . '/cache.json');
    fim(200, ['ok' => true, 'campos' => array_keys($novo)]);
}

fim(400, ['ok' => false, 'msg' => 'acao desconhecida']);
