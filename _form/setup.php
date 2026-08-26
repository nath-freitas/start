<?php
/**
 * Bootstrap de UM formulário — Catapulta de Ideias.
 *
 * Existe porque o dado (e a credencial) vivem FORA da pasta publicada e o deploy
 * só escreve dentro dela: sem SSH, este é o único jeito de criar a pasta e o
 * config.php. Protegido por chave própria.
 *
 *   GET  /_form/setup.php?k=<CHAVE>&c=<slug>&acao=status
 *   POST /_form/setup.php?k=<CHAVE>&c=<slug>&acao=criar
 *        corpo (urlencoded): mailchimp_key, mailchimp_list, mailchimp_tag,
 *                            leitura, webhooks (JSON), force
 *
 * Os segredos entram por POST, não por querystring: querystring vai parar no log
 * de acesso do servidor em claro, corpo de POST não.
 *
 * A chave abaixo mora numa branch PÚBLICA. Ela não protege o dado — quem protege
 * é o basic auth do Apache no .htaccess. Ela só evita esbarrão.
 * Depois de configurado e com a campanha fechada, REMOVA este arquivo da branch.
 */

const BASE_DADOS  = '/home/u346131448/dados/formularios';
const CHAVE_SETUP = 'kQ7mR2xW9pLvT4dN8fHbZ6yJcA3sEuGn';

header('Content-Type: application/json; charset=utf-8');

function fim(int $code, array $corpo): void {
    http_response_code($code);
    echo json_encode($corpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$k = (string)($_POST['k'] ?? $_GET['k'] ?? '');
if (!hash_equals(CHAVE_SETUP, $k)) fim(401, ['ok' => false, 'msg' => 'chave invalida']);

$c = (string)($_POST['c'] ?? $_GET['c'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, ['ok' => false, 'msg' => 'slug invalido']);
$dir = BASE_DADOS . '/' . $c;

$acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? 'status');

if ($acao === 'status') {
    $resp   = $dir . '/respostas.jsonl';
    $cfg    = is_file($dir . '/config.php') ? (require $dir . '/config.php') : [];
    $linhas = is_file($resp) ? (file($resp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    $ultima = $linhas ? json_decode((string)end($linhas), true) : null;
    fim(200, [
        'ok'            => true,
        'formulario'    => $c,
        'pasta'         => $dir,
        'pasta_existe'  => is_dir($dir),
        'config_existe' => is_file($dir . '/config.php'),
        'gravavel'      => is_dir($dir) && is_writable($dir),
        'respostas'     => count($linhas),
        'ultima'        => is_array($ultima) ? ($ultima['recebido_em'] ?? null) : null,
        // só diz SE existe credencial, nunca o valor
        'tem_mailchimp' => !empty($cfg['mailchimp_key']) && !empty($cfg['mailchimp_list']),
        'mailchimp_tag' => $cfg['mailchimp_tag'] ?? null,
        'webhooks'      => array_map(
            fn($w) => ['nome' => $w['nome'] ?? '?', 'host' => parse_url($w['url'] ?? '', PHP_URL_HOST)],
            (array)($cfg['webhooks'] ?? [])
        ),
        'entregas'      => is_file($dir . '/entregas.jsonl')
            ? count(file($dir . '/entregas.jsonl', FILE_SKIP_EMPTY_LINES)) : 0,
        'php'           => PHP_VERSION,
        'curl'          => function_exists('curl_init'),
    ]);
}

// Apaga só o dado de teste. O config.php fica.
if ($acao === 'limpar') {
    $apagados = [];
    foreach (['respostas.jsonl', 'entregas.jsonl'] as $f) {
        if (is_file($dir . '/' . $f) && @unlink($dir . '/' . $f)) $apagados[] = $f;
    }
    fim(200, ['ok' => true, 'apagados' => $apagados]);
}

// Remove UMA resposta pelo id, sem tocar no resto (poda de teste depois que a
// base já tem resposta real dentro).
if ($acao === 'podar') {
    $alvos = array_filter(array_map('trim', explode(',', (string)($_POST['id'] ?? $_GET['id'] ?? ''))));
    $arq = $dir . '/respostas.jsonl';
    $removidas = 0; $mantidas = 0; $saida = '';
    foreach (file($arq, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (is_array($r) && in_array((string)($r['id'] ?? ''), $alvos, true)) { $removidas++; continue; }
        $saida .= $l . "\n"; $mantidas++;
    }
    $tmp = $arq . '.tmp';
    if ($removidas && (file_put_contents($tmp, $saida, LOCK_EX) === false || !@rename($tmp, $arq))) {
        @unlink($tmp);
        fim(500, ['ok' => false, 'msg' => 'nao consegui reescrever a base']);
    }
    fim(200, ['ok' => true, 'removidas' => $removidas, 'mantidas' => $mantidas]);
}

if ($acao !== 'criar') fim(400, ['ok' => false, 'msg' => 'acao desconhecida']);

$leitura = (string)($_POST['leitura'] ?? '');
if ($leitura === '') fim(400, ['ok' => false, 'msg' => 'leitura (token) e obrigatorio']);

$existe = is_file($dir . '/config.php');
if ($existe && ($_POST['force'] ?? '') !== '1') {
    fim(409, ['ok' => false, 'msg' => 'config ja existe; mande force=1 para sobrescrever']);
}
// Sobrescrever regrava o arquivo INTEIRO: o que não for reenviado, some.
// Por isso o criar com force parte do config atual e só troca o que veio.
$atual = $existe ? (require $dir . '/config.php') : [];

if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui criar ' . $dir]);
}

$webhooks = $atual['webhooks'] ?? [];
if (isset($_POST['webhooks'])) {
    $w = json_decode((string)$_POST['webhooks'], true);
    if (!is_array($w)) fim(400, ['ok' => false, 'msg' => 'webhooks nao e json valido']);
    $webhooks = $w;
}

$novo = [
    'token_leitura'  => $leitura,
    'mailchimp_key'  => (string)($_POST['mailchimp_key']  ?? $atual['mailchimp_key']  ?? ''),
    'mailchimp_list' => (string)($_POST['mailchimp_list'] ?? $atual['mailchimp_list'] ?? ''),
    'mailchimp_tag'  => (string)($_POST['mailchimp_tag']  ?? $atual['mailchimp_tag']  ?? ''),
    'webhooks'       => $webhooks,
];

$php = "<?php\n// Gerado pelo setup em " . date('c') . ". NAO versionar.\nreturn "
     . var_export($novo, true) . ";\n";

if (file_put_contents($dir . '/config.php', $php, LOCK_EX) === false) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui escrever config.php']);
}
@chmod($dir . '/config.php', 0640);

// Sem isto, o opcache serve a versão ANTERIOR do config por alguns segundos
// (revalidate_freq) e o primeiro envio depois de configurar sai com a credencial
// velha — falha que parece do Mailchimp e é do cache. Pego em teste local.
if (function_exists('opcache_invalidate')) @opcache_invalidate($dir . '/config.php', true);

fim(200, [
    'ok'            => true,
    'msg'           => 'formulario configurado',
    'pasta'         => $dir,
    'tem_mailchimp' => $novo['mailchimp_key'] !== '' && $novo['mailchimp_list'] !== '',
    'webhooks'      => array_map(fn($w) => $w['nome'] ?? '?', $webhooks),
]);
