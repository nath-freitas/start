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
 * AUTENTICAÇÃO: esta pasta é pública (o navegador de quem preenche precisa
 * alcançar o enviar.php), e a branch de deploy também é. Logo, chave escrita
 * NESTE arquivo é chave publicada. A que vale mora em BASE_DADOS/.setup_key,
 * fora do docroot e fora do repositório. A CHAVE_BOOTSTRAP abaixo só serve para
 * instalar a primeira — depois que o arquivo existe, ela para de valer.
 *
 * Perdeu a chave? Não há como recuperá-la do servidor. O caminho é apagar
 * /home/u346131448/dados/formularios/.setup_key pelo Gerenciador de Arquivos da
 * Hostinger: a bootstrap volta a valer e você instala outra.
 */

const BASE_DADOS      = '/home/u346131448/dados/formularios';
const CHAVE_BOOTSTRAP = 'kQ7mR2xW9pLvT4dN8fHbZ6yJcA3sEuGn';
const ARQ_CHAVE       = BASE_DADOS . '/.setup_key';

header('Content-Type: application/json; charset=utf-8');

function fim(int $code, array $corpo): void {
    http_response_code($code);
    echo json_encode($corpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$instalada = is_file(ARQ_CHAVE) ? trim((string)@file_get_contents(ARQ_CHAVE)) : '';
$k = (string)($_POST['k'] ?? $_GET['k'] ?? '');
if (!hash_equals($instalada !== '' ? $instalada : CHAVE_BOOTSTRAP, $k)) {
    fim(401, ['ok' => false, 'msg' => 'chave invalida']);
}

$acao = (string)($_POST['acao'] ?? $_GET['acao'] ?? 'status');

// Instala (ou troca) a chave de verdade. Não depende de slug: é do serviço.
if ($acao === 'instalar-chave') {
    $nova = trim((string)($_POST['nova'] ?? ''));
    if (strlen($nova) < 24) fim(400, ['ok' => false, 'msg' => 'nova precisa de 24+ caracteres']);
    if (!is_dir(BASE_DADOS) && !@mkdir(BASE_DADOS, 0750, true) && !is_dir(BASE_DADOS)) {
        fim(500, ['ok' => false, 'msg' => 'nao consegui criar ' . BASE_DADOS]);
    }
    if (@file_put_contents(ARQ_CHAVE, $nova . "\n", LOCK_EX) === false) {
        fim(500, ['ok' => false, 'msg' => 'nao consegui escrever a chave']);
    }
    @chmod(ARQ_CHAVE, 0600);
    fim(200, ['ok' => true, 'msg' => 'chave instalada', 'bootstrap_ainda_vale' => false]);
}

$c = (string)($_POST['c'] ?? $_GET['c'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, ['ok' => false, 'msg' => 'slug invalido']);
$dir = BASE_DADOS . '/' . $c;

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
        'mailchimp_tag_pagamento' => $cfg['mailchimp_tag_pagamento'] ?? null,
        'alertas'       => is_file($dir . '/alertas.jsonl')
            ? count(file($dir . '/alertas.jsonl', FILE_SKIP_EMPTY_LINES)) : 0,
        'webhooks'      => array_map(
            fn($w) => ['nome' => $w['nome'] ?? '?', 'host' => parse_url($w['url'] ?? '', PHP_URL_HOST)],
            (array)($cfg['webhooks'] ?? [])
        ),
        'entregas'      => is_file($dir . '/entregas.jsonl')
            ? count(file($dir . '/entregas.jsonl', FILE_SKIP_EMPTY_LINES)) : 0,
        'limite_hora'   => (int)($cfg['limite_hora'] ?? 12),
        // se isto vier false, a chave publicada na branch ainda abre este endpoint
        'chave_propria' => $instalada !== '',
        'php'           => PHP_VERSION,
        'curl'          => function_exists('curl_init'),
    ]);
}

/* Zera a base do formulário ARQUIVANDO, não apagando. A mesma chamada que tirava
   o teste de ontem tiraria a reserva paga de hoje, e não havia volta: com dado
   real dentro, "limpar" é irreversível. Renomear custa o mesmo e não destrói. */
if ($acao === 'limpar') {
    $stamp = date('Ymd-His'); $arquivados = [];
    foreach (['respostas.jsonl', 'entregas.jsonl', 'alertas.jsonl'] as $f) {
        if (is_file($dir . '/' . $f) && @rename($dir . '/' . $f, $dir . '/' . $f . '.bak-' . $stamp)) {
            $arquivados[] = $f;
        }
    }
    fim(200, ['ok' => true, 'arquivados' => $arquivados, 'sufixo' => '.bak-' . $stamp]);
}

/* Remove respostas pelo id, sem tocar no resto — é como se tira um teste depois
   que a base já tem resposta real dentro. Poda os três arquivos: deixar o log de
   entrega e o alerta de um id que não existe mais é ruído que ninguém sabe ler. */
if ($acao === 'podar') {
    $alvos = array_filter(array_map('trim', explode(',', (string)($_POST['id'] ?? $_GET['id'] ?? ''))));
    if (!$alvos) fim(400, ['ok' => false, 'msg' => 'id e obrigatorio']);
    $out = [];
    foreach (['respostas.jsonl', 'entregas.jsonl', 'alertas.jsonl'] as $f) {
        $arq = $dir . '/' . $f;
        if (!is_file($arq)) { $out[$f] = ['removidas' => 0, 'mantidas' => 0]; continue; }
        $removidas = 0; $mantidas = 0; $saida = '';
        foreach (file($arq, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $r = json_decode($l, true);
            if (is_array($r) && in_array((string)($r['id'] ?? ''), $alvos, true)) { $removidas++; continue; }
            $saida .= $l . "\n"; $mantidas++;
        }
        $tmp = $arq . '.tmp';
        if ($removidas && (file_put_contents($tmp, $saida, LOCK_EX) === false || !@rename($tmp, $arq))) {
            @unlink($tmp);
            fim(500, ['ok' => false, 'msg' => 'nao consegui reescrever ' . $f]);
        }
        $out[$f] = ['removidas' => $removidas, 'mantidas' => $mantidas];
    }
    fim(200, ['ok' => true] + $out);
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
    // tag que a plataforma de pagamento aplica na compra aprovada. O receptor não
    // aplica esta: só confere se ela está no contato e, se não estiver, abre alerta.
    'mailchimp_tag_pagamento' => (string)($_POST['mailchimp_tag_pagamento'] ?? $atual['mailchimp_tag_pagamento'] ?? ''),
    // teto de envios por hora vindos do mesmo IP; 0 desliga
    'limite_hora'    => (int)($_POST['limite_hora'] ?? $atual['limite_hora'] ?? 12),
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
