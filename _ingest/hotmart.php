<?php
/**
 * Receptor de webhook de vendas da Hotmart — Catapulta de Ideias.
 * Multi-cliente:  POST https://<dominio>/_ingest/hotmart.php?c=<slug>
 *
 * REGRA DURA: nenhum dado pessoal do comprador é lido, gravado ou logado.
 * O payload da Hotmart chega com nome, e-mail, telefone e CPF — nada disso
 * é tocado. Só o esqueleto da transação sai daqui. O que o mapa não
 * reconhece é DESCARTADO, nunca gravado "por garantia".
 *
 * O arquivo de dados vive FORA da pasta publicada: o deploy é destrutivo
 * (espelha a branch e apaga o resto). Ver BASE_DADOS.
 */

// Pasta de dados, fora do docroot. Ajuste por servidor.
const BASE_DADOS = '/home/u346131448/dados/vendas';
const TZ         = 'America/Sao_Paulo';

// O webhook pode ser cadastrado com TODOS os eventos (é o mais robusto: produto
// novo entra sozinho). Quem filtra é este mapa. Só o que vira linha na base:
const MAPA_EVENTO = [
    'PURCHASE_APPROVED'       => 'aprovada',
    'PURCHASE_REFUNDED'       => 'reembolso',
    'PURCHASE_CHARGEBACK'     => 'chargeback',
    'PURCHASE_PROTEST'        => 'protesto',
    'PURCHASE_CANCELED'       => 'cancelada',
    // Ignorados de propósito — não são venda nova:
    'PURCHASE_COMPLETE'          => null,  // redispara no fim da garantia; contaria dobrado
    'PURCHASE_BILLET_PRINTED'    => null,  // boleto/PIX gerado — intenção, não venda
    'PURCHASE_OUT_OF_SHOPPING_CART' => null,  // carrinho abandonado — só PII, zero venda
    'PURCHASE_EXPIRED'           => null,
    'PURCHASE_DELAYED'           => null,
    'SUBSCRIPTION_CANCELLATION'  => null,
    'SWITCH_PLAN'                => null,
    'UPDATE_SUBSCRIPTION_CHARGE_DATE' => null,
    'CLUB_FIRST_ACCESS'          => null,
    'CLUB_MODULE_COMPLETED'      => null,
];

function fim(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => $code < 300, 'msg' => $msg]);
    exit;
}

/** Primeiro caminho não-vazio. Caminho = 'data.purchase.price.value'. */
function cava(array $p, array $caminhos) {
    foreach ($caminhos as $caminho) {
        $v = $p;
        foreach (explode('.', $caminho) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) { $v = null; break; }
            $v = $v[$k];
        }
        if ($v !== null && $v !== '' && $v !== []) return $v;
    }
    return null;
}

/** Mapa de chaves do payload com os VALORES trocados pelo tipo. Nunca contém dado. */
function esqueleto($v, string $prefixo = ''): array {
    $out = [];
    if (is_array($v)) {
        $listaish = array_keys($v) === range(0, count($v) - 1);
        foreach ($v as $k => $sub) {
            $p = $prefixo === '' ? (string)$k : $prefixo . '.' . ($listaish ? '[]' : $k);
            $out += esqueleto($sub, $p);
            if ($listaish) break; // um item da lista basta
        }
        if (!$v) $out[$prefixo] = 'array vazio';
    } else {
        $out[$prefixo] = gettype($v);
    }
    return $out;
}

/** Epoch em ms, epoch em s ou string ISO → ISO-8601 no fuso de São Paulo. */
function quando($v): string {
    $tz = new DateTimeZone(TZ);
    if (is_numeric($v)) {
        $seg = (int)$v > 20000000000 ? (int)((int)$v / 1000) : (int)$v;
        return (new DateTimeImmutable('@' . $seg))->setTimezone($tz)->format('c');
    }
    if (is_string($v) && $v !== '') {
        try { return (new DateTimeImmutable($v))->setTimezone($tz)->format('c'); } catch (Exception $e) {}
    }
    return (new DateTimeImmutable('now', $tz))->format('c');
}

// ---------------------------------------------------------------- cliente
$c = $_GET['c'] ?? '';
if (!preg_match('/^[a-z0-9-]{2,40}$/', $c)) fim(400, 'cliente invalido');
$dir = BASE_DADOS . '/' . $c;
if (!is_dir($dir) || !is_file($dir . '/config.php')) fim(404, 'cliente nao configurado');
$cfg = require $dir . '/config.php';

// ---------------------------------------------------------------- autenticação
$raw = file_get_contents('php://input');
$p   = json_decode($raw, true);
if (!is_array($p)) fim(400, 'json invalido');

$tok = $_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? '';
if ($tok === '') $tok = (string)($p['hottok'] ?? '');           // webhook v1
if (!is_string($tok) || !hash_equals((string)$cfg['hottok'], $tok)) fim(401, 'token invalido');

// ------------------------------------------- mapa da estrutura (uma vez, sem valores)
if (!empty($cfg['mapear_estrutura']) && !is_file($dir . '/estrutura.json')) {
    file_put_contents(
        $dir . '/estrutura.json',
        json_encode(esqueleto($p), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

// ---------------------------------------------------------------- extração
$evento_bruto = (string)(cava($p, ['event', 'data.event']) ?? '');

// Censo de eventos: só NOME e contagem, zero payload. Com o webhook cadastrado
// em todos os eventos, é assim que se enxerga o que a Hotmart manda de fato —
// sem nunca guardar o conteúdo de um evento que não vira venda.
$censo_arq = $dir . '/eventos-vistos.json';
$censo = is_file($censo_arq) ? (json_decode(file_get_contents($censo_arq), true) ?: []) : [];
$agora_iso = (new DateTimeImmutable('now', new DateTimeZone(TZ)))->format('c');
$k = $evento_bruto === '' ? '(sem campo event)' : $evento_bruto;
$censo[$k] = [
    'vezes'   => (int)($censo[$k]['vezes'] ?? 0) + 1,
    'primeiro'=> $censo[$k]['primeiro'] ?? $agora_iso,
    'ultimo'  => $agora_iso,
    'gravado' => array_key_exists($k, MAPA_EVENTO) && MAPA_EVENTO[$k] !== null,
];
file_put_contents($censo_arq, json_encode($censo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

if (!array_key_exists($evento_bruto, MAPA_EVENTO)) fim(200, 'evento desconhecido, ignorado');
$evento = MAPA_EVENTO[$evento_bruto];
if ($evento === null) fim(200, 'evento ignorado por regra');

$transacao = (string)(cava($p, [
    'data.purchase.transaction',
    'data.transaction',
    'transaction',
]) ?? '');
if ($transacao === '') fim(422, 'transacao ausente');

// Order bump vem como transação IRMÃ: HP0395056608C1 e ...C2, mesmo checkout.
// O código-base é o que permite casar bump com pedido e calcular attach rate.
$pedido = preg_match('/^(.+?)C\d+$/', $transacao, $m) ? $m[1] : $transacao;

// O webhook é de TODOS os produtos: um produto que ninguém cadastrou no config
// não pode virar "desconhecido:123456" e sumir do relatório. Guardamos o nome
// (dado do produto, não do comprador) e derivamos um slug legível dele.
$produto_id   = (string)(cava($p, ['data.product.id', 'data.product.ucode', 'prod']) ?? '');
$produto_nome = (string)(cava($p, ['data.product.name', 'prod_name']) ?? '');
$produto      = $cfg['produtos'][$produto_id] ?? null;
if ($produto === null) {
    // iconv//TRANSLIT depende do locale do servidor e devolve "?" para acento na
    // Hostinger — o mapa abaixo não depende de nada.
    $sem_acento = strtr($produto_nome, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','í'=>'i','ì'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O',
        'Ú'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N',
    ]);
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $sem_acento), '-'));
    $produto = $slug !== '' ? $slug : ('produto-' . $produto_id);
}

$origem = (string)(cava($p, [
    'data.purchase.sckPaymentLink',
    'data.purchase.tracking.source_sck',
    'data.tracking.source_sck',
    'data.purchase.origin.sck',
    'sck',
]) ?? '');

$src = (string)(cava($p, [
    'data.purchase.tracking.source',
    'data.tracking.source',
    'src',
]) ?? '');

// pago x orgânico: no padrão Catapulta o 2º campo do sck é o utm_medium —
// numérico (id do conjunto) = pago, palavra = orgânico. Regex sobrescrevível.
$partes = $origem === '' ? [] : explode('|', $origem);
$medium = $partes[1] ?? '';
if ($origem === '') {
    $trafego = 'sem_origem';
} else {
    $regra   = $cfg['regra_pago'] ?? '/^\d+$/';
    $trafego = preg_match($regra, $medium) ? 'pago' : 'organico';
}

$valor = cava($p, [
    'data.purchase.price.value',          // o que foi PAGO — nunca o preço de tabela
    'data.purchase.full_price.value',
    'data.purchase.original_offer_price.value',
]);

$linha = [
    'evento_id'  => (string)(cava($p, ['id', 'data.purchase.order_id']) ?? ($transacao . ':' . $evento)),
    'evento'     => $evento,
    'transacao'  => $transacao,
    'pedido'     => $pedido,
    'produto'    => $produto,
    'produto_id' => $produto_id,
    'produto_nome' => $produto_nome,
    'oferta'     => (string)(cava($p, ['data.purchase.offer.code', 'off']) ?? ''),
    'data_hora'  => quando(cava($p, [
        'data.purchase.approved_date', 'data.purchase.order_date', 'creation_date',
    ])),
    'valor'      => $valor === null ? null : round((float)$valor, 2),
    'moeda'      => (string)(cava($p, ['data.purchase.price.currency_value', 'data.purchase.price.currency_code']) ?? 'BRL'),
    'origem'     => $origem,
    'src'        => $src,
    'trafego'    => $trafego,
    'pagamento'  => (string)(cava($p, ['data.purchase.payment.type']) ?? ''),
    'parcelas'   => (int)(cava($p, ['data.purchase.payment.installments_number']) ?? 1),
    'recebido'   => (new DateTimeImmutable('now', new DateTimeZone(TZ)))->format('c'),
];

// ---------------------------------------------------------------- gravação
$fh = fopen($dir . '/vendas.jsonl', 'a');
if ($fh === false) fim(500, 'sem escrita');
flock($fh, LOCK_EX);
fwrite($fh, json_encode($linha, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
flock($fh, LOCK_UN);
fclose($fh);

// A deduplicação acontece na LEITURA (por evento_id): o receptor fica burro e
// rápido, e o retry da Hotmart nunca vira venda a mais.
fim(200, 'ok');
