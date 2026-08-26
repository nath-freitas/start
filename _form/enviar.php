<?php
/**
 * Receptor de formulários — Catapulta de Ideias.
 *
 *   POST /_form/enviar.php?c=<slug>      (application/x-www-form-urlencoded)
 *
 * Nasce multi-cliente: um endpoint só, uma pasta de dados por slug.
 * O dado vive FORA do docroot (/home/<user>/dados/formularios/<slug>/), porque
 * o deploy espelha a branch e apaga o que não estiver nela.
 *
 * Ordem de operações, e o porquê dela:
 *   1. grava a resposta em disco  → é a fonte de verdade e o backup;
 *   2. só depois repassa para Mailchimp e webhooks (Unnichat, planilha).
 * Se um repasse falhar, a resposta JÁ está salva e o registro da falha vai para
 * entregas.jsonl — nada se perde em silêncio, e o usuário nunca vê erro por
 * causa de uma API de terceiro fora do ar.
 */

const BASE_DADOS = '/home/u346131448/dados/formularios';
date_default_timezone_set('America/Sao_Paulo');

// Este endpoint só pode responder JSON. Um warning impresso no meio da resposta
// quebra o JSON.parse do navegador e vira "não consegui enviar" para quem preencheu.
ini_set('display_errors', '0');

// Domínios que podem postar aqui. Sem curinga: é o que separa o formulário do
// cliente de qualquer página que copie o endpoint.
const ORIGENS = [
    'https://jorgegrimberg.com.br',
    'https://www.jorgegrimberg.com.br',
    'https://cliente.catapultadeideias.com.br',
];

$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origem, ORIGENS, true)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=utf-8');

function fim(int $code, array $corpo): void {
    http_response_code($code);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fim(405, ['ok' => false, 'msg' => 'use POST']);

$c = (string)($_GET['c'] ?? '');
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, ['ok' => false, 'msg' => 'slug invalido']);

$dir = BASE_DADOS . '/' . $c;
if (!is_file($dir . '/config.php')) fim(404, ['ok' => false, 'msg' => 'formulario nao configurado']);
$cfg = require $dir . '/config.php';

/* -------------------------------------------------------------------------
   Armadilha de robô. Devolve sucesso de propósito: robô que recebe erro tenta
   de novo com outra combinação; robô que recebe 200 vai embora satisfeito.
   ------------------------------------------------------------------------- */
if (trim((string)($_POST['empresa'] ?? '')) !== '') fim(200, ['ok' => true, 'id' => 'ok']);

/* ---------------------------- captura dos campos ---------------------------- */
function texto(string $chave, int $max): string {
    $v = (string)($_POST[$chave] ?? '');
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);   // controles, menos \n e \t
    $v = trim($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

// nome do campo => tamanho máximo aceito. O que não estiver aqui é descartado.
$ESQUEMA = [
    'nome'       => 80,
    'whatsapp'   => 26,   // cabe E.164 de qualquer país já formatado: "+351 912345678"
    'email'      => 120,
    'link'       => 200,
    'profissao'  => 160,
    'estagio'    => 80,
    'projeto'    => 1500,
    'seis_meses' => 1500,
];
$OBRIGATORIOS = array_keys($ESQUEMA);

$campos = [];
foreach ($ESQUEMA as $k => $max) $campos[$k] = texto($k, $max);

$faltando = [];
foreach ($OBRIGATORIOS as $k) if ($campos[$k] === '') $faltando[] = $k;
if (!filter_var($campos['email'], FILTER_VALIDATE_EMAIL)) $faltando[] = 'email';
// piso de 8 dígitos: número internacional curto existe e não pode ser recusado aqui
if (strlen(preg_replace('/\D/', '', $campos['whatsapp'])) < 8) $faltando[] = 'whatsapp';
if ($faltando) fim(422, ['ok' => false, 'msg' => 'campos invalidos', 'campos' => array_values(array_unique($faltando))]);

/* telefone em E.164 — é o formato que CRM de WhatsApp espera; o mascarado fica junto.
   Se o formulário já mandou com "+", o DDI é o que a pessoa escolheu e vale como veio:
   assumir +55 aqui transformaria um número de Portugal em número inválido. Só quando
   não vier DDI é que o Brasil entra como padrão. */
$digitos = preg_replace('/\D/', '', $campos['whatsapp']);
if (substr($campos['whatsapp'], 0, 1) === '+') {
    $e164 = '+' . $digitos;
} else {
    $e164 = '+55' . ltrim($digitos, '0');
    if (strlen($digitos) > 11 && substr($digitos, 0, 2) === '55') $e164 = '+' . $digitos;
}

// UTMs vindas da querystring da página, quando o link do fluxo carregar alguma
parse_str((string)($_POST['query'] ?? ''), $q);
$utm = [];
foreach (['utm_source','utm_medium','utm_campaign','utm_content','utm_term','sck','cid'] as $k) {
    if (!empty($q[$k])) $utm[$k] = texto_bruto((string)$q[$k]);
}
function texto_bruto(string $v): string {
    return substr(preg_replace('/[^\w\-.:@+ ]/u', '', $v), 0, 120);
}

$registro = array_merge([
    'id'          => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
    'recebido_em' => date('c'),
    'formulario'  => $c,
], $campos, [
    'whatsapp_e164' => $e164,
    'pagina'        => substr((string)($_POST['pagina'] ?? ''), 0, 300),
    'utm'           => $utm,
    // IP nunca em claro: só o hash serve para "é a mesma pessoa?" sem guardar PII de rede
    'ip_hash'       => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $c), 0, 16),
    'agente'        => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
]);

/* ------------- 1. grava primeiro: nada depende de API de terceiro ------------- */
$linha = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($dir . '/respostas.jsonl', $linha, FILE_APPEND | LOCK_EX) === false) {
    fim(500, ['ok' => false, 'msg' => 'nao consegui gravar']);
}

/* ---- 2. responde AGORA e repassa depois, com a conexão do navegador fechada ----
   Mailchimp e Unnichat somam vários segundos de rede. Quem preencheu não pode
   esperar por eles: a resposta já está salva, então o "Recebido" é verdade antes
   do primeiro repasse. Sem isso, um CRM lento vira formulário travado. */
function responder(array $corpo): void {
    $json = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    ignore_user_abort(true);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
    if (function_exists('fastcgi_finish_request'))   { fastcgi_finish_request();   return; }
    while (ob_get_level() > 0) @ob_end_flush();      // mod_php: o melhor possível
    @flush();
}

responder(['ok' => true, 'id' => $registro['id']]);
@set_time_limit(45);

function http_json(string $url, array $headers, $corpo, string $metodo = 'POST'): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_POSTFIELDS     => is_string($corpo) ? $corpo : json_encode($corpo, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    return ['http' => $code, 'erro' => $erro, 'corpo' => substr((string)$body, 0, 400)];
}

$entregas = [];

/* Mailchimp — upsert do contato + tag + NOTA com as respostas.
   A nota existe porque merge field que não está criado na audiência devolve 400:
   nota é campo livre, sempre aceita, e é onde a Andressa quer ler mesmo. */
if (!empty($cfg['mailchimp_key']) && !empty($cfg['mailchimp_list'])) {
    $dc   = substr(strrchr($cfg['mailchimp_key'], '-') ?: '-us1', 1);
    $base = "https://{$dc}.api.mailchimp.com/3.0/lists/{$cfg['mailchimp_list']}/members/";
    $hash = md5(strtolower($campos['email']));
    $auth = ['Authorization: Basic ' . base64_encode('anystring:' . $cfg['mailchimp_key'])];

    $entregas['mailchimp_membro'] = http_json($base . $hash, $auth, [
        'email_address' => $campos['email'],
        'status_if_new' => 'subscribed',
        'merge_fields'  => ['FNAME' => $campos['nome']],
    ], 'PUT');

    if (!empty($cfg['mailchimp_tag'])) {
        $entregas['mailchimp_tag'] = http_json($base . $hash . '/tags', $auth, [
            'tags' => [['name' => $cfg['mailchimp_tag'], 'status' => 'active']],
        ]);
    }

    $nota = "RESERVA MENTORIA JG — " . date('d/m/Y H:i') . "\n"
          . "WhatsApp: {$campos['whatsapp']}\n"
          . "Link: {$campos['link']}\n"
          . "Faz hoje: {$campos['profissao']}\n"
          . "Estagio do projeto: {$campos['estagio']}\n"
          . "Projeto: {$campos['projeto']}\n"
          . "Em 6 meses: {$campos['seis_meses']}";
    $entregas['mailchimp_nota'] = http_json($base . $hash . '/notes', $auth,
        ['note' => substr($nota, 0, 1000)]);
}

/* Webhooks genéricos — Unnichat, Apps Script da planilha, o que vier.
   Configuráveis: destino novo não pede deploy, só um setup.php?acao=criar. */
foreach ((array)($cfg['webhooks'] ?? []) as $w) {
    if (empty($w['url'])) continue;
    $entregas['webhook_' . ($w['nome'] ?? 'sem-nome')] =
        http_json($w['url'], (array)($w['headers'] ?? []), $registro,
                  strtoupper((string)($w['metodo'] ?? 'POST')));
}

if ($entregas) {
    @file_put_contents($dir . '/entregas.jsonl', json_encode([
        'id' => $registro['id'], 'em' => date('c'), 'resultado' => $entregas,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
