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
    'ddi'        => 4,    // só os dígitos do país ("55", "351"); opcional, ver split abaixo
    'email'      => 120,
    'link'       => 200,
    'profissao'  => 160,
    'estagio'    => 80,
    'projeto'    => 1500,
    'seis_meses' => 1500,
];
// `ddi` é derivável do `whatsapp`, então não trava o envio se a página não mandar
$OBRIGATORIOS = array_values(array_diff(array_keys($ESQUEMA), ['ddi']));

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

/* DDI e número nacional separados. Ordem de confiança:
   1) o campo `ddi` que a própria página manda (é o país que a pessoa escolheu);
   2) o espaço que a página põe entre DDI e número ("+351 912345678");
   3) Brasil.
   Nunca adivinhar o DDI contando dígitos: prefixo de país tem 1, 2 ou 3 dígitos
   e "+1 242" (Bahamas) é indistinguível de "+1" (EUA) sem tabela. */
$candidatos = [preg_replace('/\D/', '', $campos['ddi'])];
if (preg_match('/^\+(\d{1,3})\s/', $campos['whatsapp'], $m)) $candidatos[] = $m[1];
$candidatos[] = '55';   // default de quem não escolheu país

$ddi = '';
foreach ($candidatos as $cand) {
    // só vale o candidato que realmente prefixa o E.164 — senão o resto do número sai torto
    if ($cand !== '' && strpos($e164, '+' . $cand) === 0) { $ddi = $cand; break; }
}
$nacional = $ddi === '' ? substr($e164, 1) : substr($e164, strlen($ddi) + 1);

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
    /* DDI e número nacional SEPARADOS, além do E.164 junto. CRM de WhatsApp
       (Unnichat/Underchat e parentes) mapeia campo a campo e pede "DDI" e
       "telefone" como duas coisas: sem isso não há o que arrastar para o campo
       DDI na tela de mapeamento. O E.164 fica porque quem aceita junto prefere. */
    'ddi'           => $ddi,
    'telefone'      => $nacional,
    'pagina'        => substr((string)($_POST['pagina'] ?? ''), 0, 300),
    'utm'           => $utm,
    // IP nunca em claro: só o hash serve para "é a mesma pessoa?" sem guardar PII de rede
    'ip_hash'       => substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $c), 0, 16),
    'agente'        => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
]);

/* ------------------------- trava de enxurrada -------------------------------
   O endpoint é público por construção (o navegador de quem preenche tem que
   alcançar ele) e o URL está no código-fonte da página. Sem teto, um laço de
   curl enche a base de lixo e dispara uma automação de CRM por linha. Devolve
   sucesso, como a armadilha de robô: quem apanha de 429 tenta de novo.
   Conta pelo hash do IP, na última hora. */
$limite = (int)($cfg['limite_hora'] ?? 12);
if ($limite > 0 && is_file($dir . '/respostas.jsonl')) {
    $corte = date('c', time() - 3600);   // mesmo fuso em toda linha: comparação de string basta
    $recentes = 0;
    foreach (file($dir . '/respostas.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (is_array($r) && ($r['ip_hash'] ?? '') === $registro['ip_hash']
            && (string)($r['recebido_em'] ?? '') >= $corte) $recentes++;
    }
    if ($recentes >= $limite) fim(200, ['ok' => true, 'id' => 'ok']);
}

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

/* $max_corpo trunca o que vai para o log — 400 basta para diagnosticar um erro.
   Quem precisa LER a resposta (e não só registrar) tem que pedir mais: JSON cortado
   no meio não decodifica, e aí a leitura falha em silêncio. */
function http_json(string $url, array $headers, $corpo, string $metodo = 'POST', int $max_corpo = 400): array {
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
    return ['http' => $code, 'erro' => $erro, 'corpo' => substr((string)$body, 0, $max_corpo)];
}

function ok_http(array $r): bool { return $r['http'] >= 200 && $r['http'] < 300; }

/* Uma retentativa, e só uma. A falha de repasse mais comum é blip de rede, timeout
   ou 429 momentâneo, e a segunda tentativa resolve isso sem fila, sem cron e sem
   estado guardado. Erro de configuração (4xx que não seja 429) não melhora com
   repetição — não gasta a segunda tentativa nem os 2 segundos. */
function repassar(string $url, array $headers, $corpo, string $metodo = 'POST', int $max_corpo = 400): array {
    $r = http_json($url, $headers, $corpo, $metodo, $max_corpo);
    if (!ok_http($r) && ($r['http'] === 0 || $r['http'] === 429 || $r['http'] >= 500)) {
        sleep(2);
        $r = http_json($url, $headers, $corpo, $metodo, $max_corpo);
        $r['tentativas'] = 2;
        return $r;
    }
    $r['tentativas'] = 1;
    return $r;
}

/* Fila de coisas que só humano resolve (e-mail que não casa com a base, comprador
   sem a tag de pagamento, repasse que não entrou). Fica em arquivo próprio,
   separado do log de entregas, porque é a única saída do receptor que alguém
   precisa LER — o resto é histórico. */
function alerta(string $dir, string $tipo, array $registro, array $extra): void {
    @file_put_contents($dir . '/alertas.jsonl', json_encode([
        'em'       => date('c'),
        'tipo'     => $tipo,
        'id'       => $registro['id'],
        'nome'     => $registro['nome']  ?? '',
        'email'    => $registro['email'] ?? '',
        'whatsapp' => $registro['whatsapp_e164'] ?? '',
    ] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

$entregas = [];

/* Mailchimp — ATUALIZA o contato, nunca cria.
   Quem cria o contato é a Hotmart (ListBoss, na compra aprovada da taxa de reserva).
   Se o formulário criasse, um e-mail de formulário diferente do e-mail da compra
   viraria uma SEGUNDA pessoa na base — cobrada duas vezes e com a segmentação
   quebrada. Por isso: GET primeiro, 404 não vira POST.

   Isto rodava numa task agendada do agente. Voltou para cá porque toda rodada de
   task escreve uma linha no canal do cliente, inclusive quando não tem nada a
   dizer — e aqui, além de silencioso, o dado entra no ato do envio em vez de
   esperar até 15 minutos. */
if (!empty($cfg['mailchimp_key']) && !empty($cfg['mailchimp_list'])) {
    $dc   = substr(strrchr($cfg['mailchimp_key'], '-') ?: '-us1', 1);
    $base = "https://{$dc}.api.mailchimp.com/3.0/lists/{$cfg['mailchimp_list']}/members/";
    $hash = md5(strtolower($campos['email']));
    $auth = ['Authorization: Basic ' . base64_encode('anystring:' . $cfg['mailchimp_key'])];

    $busca = repassar($base . $hash . '?fields=id,tags', $auth, '', 'GET', 20000);
    $entregas['mailchimp_busca'] = ['http' => $busca['http'], 'erro' => $busca['erro']];

    /* 404 é caso previsto (vira alerta próprio, logo abaixo). Qualquer outro
       não-2xx é o Mailchimp fora do ar ou credencial recusada — e aí NENHUM
       contato desta rodada foi enriquecido. Isso tem que aparecer na fila. */
    if (!ok_http($busca) && $busca['http'] !== 404) {
        alerta($dir, 'mailchimp-falhou', $registro, [
            'etapa' => 'busca', 'http' => $busca['http'],
            'erro'  => $busca['erro'], 'resposta' => substr($busca['corpo'], 0, 300),
        ]);
    }

    if ($busca['http'] === 200) {
        /* skip_merge_validation: campo obrigatório da audiência que o formulário
           não pergunta não pode derrubar o enriquecimento inteiro.
           E não mandamos `status`: mexer no status de quem já é contato
           reinscreve ou desinscreve sem querer. */
        $entregas['mailchimp_campos'] = repassar(
            $base . $hash . '?skip_merge_validation=true', $auth,
            ['merge_fields' => [
                'FNAME'     => $campos['nome'],
                'WHATS'     => $registro['whatsapp_e164'],
                'LINK'      => $campos['link'],
                'PROFISSAO' => $campos['profissao'],
                'ESTAGIO'   => $campos['estagio'],
                'PROJETO'   => $campos['projeto'],
                'SEISMESES' => $campos['seis_meses'],
            ]], 'PATCH');

        if (!empty($cfg['mailchimp_tag'])) {
            $entregas['mailchimp_tag'] = repassar($base . $hash . '/tags', $auth, [
                'tags' => [['name' => $cfg['mailchimp_tag'], 'status' => 'active']],
            ]);
        }

        // Contato achado e mesmo assim não gravou: campo recusado, audiência
        // errada, permissão. Silencioso por natureza — a pessoa existe na base.
        foreach (['mailchimp_campos', 'mailchimp_tag'] as $etapa) {
            if (isset($entregas[$etapa]) && !ok_http($entregas[$etapa])) {
                alerta($dir, 'mailchimp-falhou', $registro, [
                    'etapa' => $etapa, 'http' => $entregas[$etapa]['http'],
                    'erro'  => $entregas[$etapa]['erro'],
                    'resposta' => substr($entregas[$etapa]['corpo'], 0, 300),
                ]);
            }
        }

        /* Vigia do ListBoss. A falha dele é silenciosa: 199 dos 201 contatos da
           audiência vieram da imersão, então um comprador que a Hotmart deixar de
           etiquetar continua existindo aqui e o enriquecimento passa por ele sem
           estranhar nada — só que ele nunca recebeu o e-mail com o link. */
        $tag_pgto = (string)($cfg['mailchimp_tag_pagamento'] ?? '');
        if ($tag_pgto !== '') {
            $tags = array_column((array)(json_decode($busca['corpo'], true)['tags'] ?? []), 'name');
            if (!in_array($tag_pgto, $tags, true)) {
                alerta($dir, 'sem-tag-de-pagamento', $registro, [
                    'tags_no_contato' => $tags,
                    'esperada'        => $tag_pgto,
                ]);
            }
        }
    } elseif ($busca['http'] === 404) {
        /* Pagou com um e-mail e preencheu com outro — ou o ListBoss ainda não
           processou a compra. Casar os dois é decisão humana. */
        alerta($dir, 'sem-match-no-mailchimp', $registro, []);
    }
}

/* Webhooks genéricos — Unnichat, Apps Script da planilha, o que vier.
   Configuráveis: destino novo não pede deploy, só um setup.php?acao=criar. */
foreach ((array)($cfg['webhooks'] ?? []) as $w) {
    if (empty($w['url'])) continue;
    /* `extras` entra no CORPO junto com a resposta. Existe porque há CRM que quer a
       credencial dentro do payload, não no cabeçalho — e sem isso descobrir qual dos
       dois é o caso viraria deploy em vez de uma linha de config. */
    $corpo = array_merge($registro, (array)($w['extras'] ?? []));
    $nome  = (string)($w['nome'] ?? 'sem-nome');
    $res   = repassar($w['url'], (array)($w['headers'] ?? []), $corpo,
                      strtoupper((string)($w['metodo'] ?? 'POST')));
    $entregas['webhook_' . $nome] = $res;

    /* Falha de repasse era o único ponto cego do fluxo: a resposta ficava salva,
       a pessoa não chegava no CRM e ninguém ficava sabendo — a descoberta era a
       Andressa estranhar uma ausência. Agora entra na mesma fila que o resto do
       que só humano resolve, com o corpo da resposta do destino junto. */
    if (!ok_http($res)) {
        alerta($dir, 'repasse-falhou', $registro, [
            'destino'    => $nome,
            'http'       => $res['http'],
            'erro'       => $res['erro'],
            'resposta'   => $res['corpo'],
            'tentativas' => $res['tentativas'] ?? 1,
        ]);
    }
}

if ($entregas) {
    @file_put_contents($dir . '/entregas.jsonl', json_encode([
        'id' => $registro['id'], 'em' => date('c'), 'resultado' => $entregas,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
