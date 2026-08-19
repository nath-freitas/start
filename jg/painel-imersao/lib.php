<?php
/**
 * Painel de campanha com VENDA REAL — coleta e consolidação.
 * Catapulta de Ideias · variante "venda direta" da skill montar-dashboard-trafego.
 *
 * Duas fontes:
 *   Meta Ads API      -> investimento e funil de mídia (Impressions, Clicks, LP View, Checkout)
 *   vendas.jsonl      -> VENDAS (fonte de verdade), gravadas pelo receptor de webhook
 *                        da plataforma. Sem nenhum dado pessoal de comprador.
 *
 * O join é o `sck` do checkout, que carrega o mesmo par utm_medium/utm_content do
 * anúncio: medium numérico = id do conjunto = tráfego pago; palavra = orgânico.
 *
 * Todo valor de MÍDIA exposto já sai COM imposto (spend * (1 + tax)).
 * Todo valor de VENDA é o BRUTO do produtor (nunca o total com juros do comprador).
 */

// Onde mora o config com as credenciais. FORA da pasta publicada: o deploy é
// destrutivo e o repositório é público.
const PAINEL_CONFIG = '/home/u346131448/dados/painel/jorge-grimberg/config.php';

// ---------------------------------------------------------------- HTTP

function dash_http_json($url)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'catapulta-painel/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new Exception("Falha de rede: $err");
    $json = json_decode($body, true);
    if ($code >= 400) {
        $msg = $json['error']['message'] ?? substr((string) $body, 0, 200);
        throw new Exception("HTTP $code — $msg");
    }
    if (!is_array($json)) throw new Exception('Resposta não é JSON');
    return $json;
}

// ---------------------------------------------------------------- Meta

/** Soma um action_type dos insights, com lista de aliases (o Meta muda o nome). */
function dash_act($row, $tipos)
{
    foreach ((array) $tipos as $t) {
        foreach (($row['actions'] ?? []) as $a) {
            if (($a['action_type'] ?? '') === $t) return (float) $a['value'];
        }
    }
    return 0.0;
}

function dash_meta_url($cfg, $hoje, $extra)
{
    $base = [
        'time_range'   => json_encode(['since' => $cfg['start'], 'until' => $hoje]),
        'filtering'    => json_encode([[
            'field' => 'campaign.name', 'operator' => 'CONTAIN', 'value' => $cfg['campaign_filter'],
        ]]),
        'access_token' => $cfg['meta_token'],
    ];
    return "https://graph.facebook.com/{$cfg['graph_ver']}/act_{$cfg['ad_account']}/insights?"
         . http_build_query($extra + $base);
}

function dash_meta_insights($cfg, $hoje)
{
    $url = dash_meta_url($cfg, $hoje, [
        'level'          => 'ad',
        'fields'         => 'campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,'
                          . 'spend,impressions,clicks,inline_link_clicks,actions',
        'time_increment' => 1,
        'limit'          => 500,
    ]);
    $rows = [];
    $guarda = 0;
    while ($url && $guarda++ < 25) {
        $j    = dash_http_json($url);
        $rows = array_merge($rows, $j['data'] ?? []);
        $url  = $j['paging']['next'] ?? null;
    }
    return $rows;
}

/** Reach e frequência não são somáveis — vêm agregados da própria API. */
function dash_meta_conta($cfg, $hoje)
{
    $j = dash_http_json(dash_meta_url($cfg, $hoje, [
        'level'  => 'account',
        'fields' => 'reach,frequency,impressions,spend',
    ]));
    return $j['data'][0] ?? [];
}

/**
 * Mídia: ao vivo se houver token; senão o último snapshot gravado no servidor.
 * O snapshot existe para o painel não nascer cego enquanto o token de usuário de
 * sistema não é emitido. Ele é datado e a página avisa quando envelhece.
 */
function dash_midia($cfg, $hoje)
{
    if (!empty($cfg['meta_token'])) {
        return [
            'linhas' => dash_meta_insights($cfg, $hoje),
            'conta'  => dash_meta_conta($cfg, $hoje),
            'fonte'  => 'api',
            'em'     => null,
            'erro'   => null,
        ];
    }

    $arq = $cfg['snapshot'] ?? '';
    if ($arq === '' || !is_readable($arq)) {
        return ['linhas' => [], 'conta' => [], 'fonte' => 'ausente', 'em' => null,
                'erro' => 'sem token do Meta e sem snapshot'];
    }
    $s = json_decode((string) file_get_contents($arq), true);
    $linhas = [];
    foreach ($s['linhas'] ?? [] as $r) {
        // O snapshot é compacto; reidrata para o mesmo formato dos insights.
        $linhas[] = [
            'date_start'         => $r['d'],
            'campaign_id'        => $r['cid'],  'campaign_name' => $r['cn'],
            'adset_id'           => $r['sid'],  'adset_name'    => $r['sn'],
            'ad_id'              => $r['aid'],  'ad_name'       => $r['an'],
            'spend'              => $r['sp'],   'impressions'   => $r['im'],
            'clicks'             => $r['cl'],   'inline_link_clicks' => $r['ilc'],
            'actions'            => [
                ['action_type' => 'landing_page_view', 'value' => $r['lpv']],
                ['action_type' => 'initiate_checkout', 'value' => $r['ic']],
                ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => $r['pu']],
            ],
        ];
    }
    return ['linhas' => $linhas, 'conta' => $s['conta'] ?? [], 'fonte' => 'snapshot',
            'em' => $s['em'] ?? null, 'erro' => null];
}

// ---------------------------------------------------------------- vendas

/**
 * Lê o vendas.jsonl do receptor: deduplica por evento_id, colapsa por transação e
 * aplica o estado final (aprovada que virou reembolso sai da conta). Mesma lógica
 * do /_ingest/vendas.php — aqui direto do disco, sem token e sem volta pela rede.
 */
function dash_vendas($cfg)
{
    $arq = $cfg['vendas_arquivo'];
    if (!is_readable($arq)) throw new Exception('vendas.jsonl não encontrado em ' . $arq);

    $linhas = [];
    foreach (file($arq, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $r = json_decode($l, true);
        if (!is_array($r) || !isset($r['evento_id'])) continue;
        $linhas[$r['evento_id']] = $r;              // retry sobrescreve, não soma
    }

    $estado = [];
    foreach ($linhas as $r) {
        $t = (string) ($r['transacao'] ?? '');
        if ($t === '') continue;
        if (($r['evento'] ?? '') === 'aprovada') {
            $estado[$t] = ($estado[$t] ?? []) + $r;
            $estado[$t]['status'] = $estado[$t]['status'] ?? 'aprovada';
        } else {
            $estado[$t] = array_merge($estado[$t] ?? $r,
                ['status' => $r['evento'], 'estornada_em' => $r['data_hora']]);
        }
    }
    $tx = array_values($estado);
    usort($tx, function ($a, $b) { return strcmp($a['data_hora'], $b['data_hora']); });
    return $tx;
}

/**
 * Desmonta o sck. No pago ele carrega os ids do Meta; no orgânico, palavras.
 * Formato observado: source|medium|campanha|termo|conteudo — mas a posição varia
 * entre versões do script de UTM, então o que vale é: o PRIMEIRO campo numérico
 * longo é o conjunto e o ÚLTIMO é o anúncio. Assim uma mudança de ordem no script
 * não parte a série ao meio.
 */
function dash_sck($origem)
{
    $p = array_map('trim', explode('|', (string) $origem));
    $nums = [];
    foreach ($p as $x) if (ctype_digit($x) && strlen($x) >= 10) $nums[] = $x;
    $n = count($nums);
    return [
        'source'   => $p[0] ?? '',
        'medium'   => $p[1] ?? '',
        'conjunto' => $n >= 1 ? $nums[0] : '',
        'anuncio'  => $n >= 2 ? $nums[$n - 1] : '',
    ];
}

// ---------------------------------------------------------------- helpers

function dash_div($a, $b, $casas = 2)
{
    return $b > 0 ? round($a / $b, $casas) : null;
}

function dash_dia($iso, $tz)
{
    if (!$iso) return null;
    try {
        $d = new DateTime($iso);
        $d->setTimezone(new DateTimeZone($tz));
        return $d->format('Y-m-d');
    } catch (Exception $e) { return null; }
}

/** Em qual etapa (campanha do Meta) cai este nome de campanha — índice ou null. */
function dash_etapa_de($nomeCampanha, $etapas)
{
    $n = strtoupper((string) $nomeCampanha);
    if ($n === '') return null;
    foreach ($etapas as $i => $e) {
        $alvo = strtoupper((string) ($e['campanha_match'] ?? $e['rot']));
        if ($alvo !== '' && strpos($n, $alvo) !== false) return $i;
    }
    return null;
}

function dash_vazio()
{
    return ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'lpv' => 0, 'ic' => 0, 'compra_pixel' => 0];
}

// ---------------------------------------------------------------- consolidação

function dash_build($cfg)
{
    $tz    = $cfg['tz'];
    $mult  = 1 + $cfg['tax'];
    $agora = new DateTime('now', new DateTimeZone($tz));
    $hoje  = $agora->format('Y-m-d');

    $midia = dash_midia($cfg, $hoje);
    $tx    = dash_vendas($cfg);

    $etapas = $cfg['etapas'];
    $nE     = count($etapas);

    // ---------- mídia: por anúncio, conjunto, dia e etapa ----------
    $ads = []; $sets = []; $dias = [];
    $setEtapa = []; $setNome = []; $adSet = [];
    $tot = dash_vazio();
    $te  = array_fill(0, $nE, dash_vazio());
    $fora_etapa = 0;

    foreach ($midia['linhas'] as $r) {
        $d   = $r['date_start'];
        $sp  = (float) ($r['spend'] ?? 0);
        $im  = (int)   ($r['impressions'] ?? 0);
        $cl  = (int)   ($r['inline_link_clicks'] ?? 0);
        $lpv = dash_act($r, 'landing_page_view');
        $ic  = dash_act($r, ['initiate_checkout', 'offsite_conversion.fb_pixel_initiate_checkout']);
        $pu  = dash_act($r, ['offsite_conversion.fb_pixel_purchase', 'purchase']);

        $aid = (string) $r['ad_id'];
        $sid = (string) $r['adset_id'];
        $setNome[$sid] = $r['adset_name'] ?? '';
        $adSet[$aid]   = $sid;

        if (!isset($ads[$aid]))  $ads[$aid]  = dash_vazio() + ['nome' => $r['ad_name'] ?? '(sem nome)',
                                                               'conjunto' => $r['adset_name'] ?? ''];
        if (!isset($sets[$sid])) $sets[$sid] = dash_vazio() + ['nome' => $r['adset_name'] ?? '(sem nome)'];

        $ads[$aid]['spend'] += $sp;  $ads[$aid]['impressions'] += $im;
        $ads[$aid]['clicks'] += $cl; $ads[$aid]['lpv'] += $lpv;
        $ads[$aid]['ic'] += $ic;     $ads[$aid]['compra_pixel'] += $pu;

        $sets[$sid]['spend'] += $sp;  $sets[$sid]['impressions'] += $im;
        $sets[$sid]['clicks'] += $cl; $sets[$sid]['lpv'] += $lpv;
        $sets[$sid]['ic'] += $ic;     $sets[$sid]['compra_pixel'] += $pu;

        if (!isset($dias[$d])) $dias[$d] = ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'lpv' => 0,
                                            'ic' => 0, 'compra_pixel' => 0,
                                            'v_total' => 0, 'v_pago' => 0, 'v_org' => 0, 'fat' => 0];
        $dias[$d]['spend'] += $sp; $dias[$d]['impressions'] += $im; $dias[$d]['clicks'] += $cl;
        $dias[$d]['lpv'] += $lpv;  $dias[$d]['ic'] += $ic;          $dias[$d]['compra_pixel'] += $pu;

        $tot['spend'] += $sp; $tot['impressions'] += $im; $tot['clicks'] += $cl;
        $tot['lpv'] += $lpv;  $tot['ic'] += $ic;          $tot['compra_pixel'] += $pu;

        $ie = dash_etapa_de($r['campaign_name'] ?? '', $etapas);
        $setEtapa[$sid] = $ie;
        if ($ie !== null) {
            $te[$ie]['spend'] += $sp; $te[$ie]['impressions'] += $im; $te[$ie]['clicks'] += $cl;
            $te[$ie]['lpv'] += $lpv;  $te[$ie]['ic'] += $ic;          $te[$ie]['compra_pixel'] += $pu;
        } else {
            $fora_etapa += $sp;
        }
    }

    // ---------- vendas ----------
    $principal = $cfg['produto_principal'];
    $V = ['pago' => 0, 'organico' => 0, 'sem_origem' => 0];         // transações
    $F = ['pago' => 0.0, 'organico' => 0.0, 'sem_origem' => 0.0];   // faturamento bruto
    $L = ['pago' => 0.0, 'organico' => 0.0, 'sem_origem' => 0.0];   // líquido
    $inscr = ['pago' => 0, 'organico' => 0, 'sem_origem' => 0];     // só o produto principal
    $porProduto = [];  $porOrganico = [];  $porAd = [];  $porSet = [];
    $vEtapa = array_fill(0, $nE, 0);        // inscrições pagas por etapa
    $tEtapa = array_fill(0, $nE, 0);        // transações pagas por etapa
    $fEtapa = array_fill(0, $nE, 0.0);      // faturamento por etapa
    $vSemEtapa = 0;
    $estornos = 0; $fat_estornado = 0.0;
    $pedidos = [];                          // pedido => produtos, para o attach rate

    foreach ($tx as $r) {
        $valor = (float) ($r['valor'] ?? 0);
        $liq   = (float) ($r['valor_liquido'] ?? 0);
        $prod  = (string) ($r['produto'] ?? '');
        $dia   = substr((string) $r['data_hora'], 0, 10);

        if (($r['status'] ?? '') !== 'aprovada') { $estornos++; $fat_estornado += $valor; continue; }

        $bal = (string) ($r['trafego'] ?? 'sem_origem');
        if (!isset($V[$bal])) $bal = 'sem_origem';

        $V[$bal]++; $F[$bal] += $valor; $L[$bal] += $liq;
        if ($prod === $principal) $inscr[$bal]++;

        if (!isset($porProduto[$prod])) $porProduto[$prod] = ['vendas' => 0, 'valor' => 0.0, 'pago' => 0];
        $porProduto[$prod]['vendas']++; $porProduto[$prod]['valor'] += $valor;
        if ($bal === 'pago') $porProduto[$prod]['pago']++;

        $ped = (string) ($r['pedido'] ?? $r['transacao']);
        $pedidos[$ped][$prod] = ($pedidos[$ped][$prod] ?? 0) + 1;

        $s = dash_sck($r['origem'] ?? '');

        if ($bal === 'pago') {
            if ($s['anuncio']  !== '') { $porAd[$s['anuncio']]['v']  = ($porAd[$s['anuncio']]['v'] ?? 0) + 1;
                                         $porAd[$s['anuncio']]['f']  = ($porAd[$s['anuncio']]['f'] ?? 0) + $valor;
                                         if ($prod === $principal) $porAd[$s['anuncio']]['i'] = ($porAd[$s['anuncio']]['i'] ?? 0) + 1; }
            if ($s['conjunto'] !== '') { $porSet[$s['conjunto']]['v'] = ($porSet[$s['conjunto']]['v'] ?? 0) + 1;
                                         $porSet[$s['conjunto']]['f'] = ($porSet[$s['conjunto']]['f'] ?? 0) + $valor;
                                         if ($prod === $principal) $porSet[$s['conjunto']]['i'] = ($porSet[$s['conjunto']]['i'] ?? 0) + 1; }
            $ie = $setEtapa[$s['conjunto']] ?? null;
            if ($ie !== null) {
                $tEtapa[$ie]++; $fEtapa[$ie] += $valor;
                if ($prod === $principal) $vEtapa[$ie]++;
            } else {
                $vSemEtapa++;
            }
        } else {
            $k = $s['source'] !== '' ? $s['source'] : '(sem origem)';
            $k .= ' / ' . ($s['medium'] !== '' ? $s['medium'] : '(sem posição)');
            if (!isset($porOrganico[$k])) $porOrganico[$k] = ['v' => 0, 'f' => 0.0, 'i' => 0];
            $porOrganico[$k]['v']++; $porOrganico[$k]['f'] += $valor;
            if ($prod === $principal) $porOrganico[$k]['i']++;
        }

        if (!isset($dias[$dia])) $dias[$dia] = ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'lpv' => 0,
                                                'ic' => 0, 'compra_pixel' => 0,
                                                'v_total' => 0, 'v_pago' => 0, 'v_org' => 0, 'fat' => 0];
        $dias[$dia]['v_total']++;
        $dias[$dia]['fat'] += $valor;
        if ($bal === 'pago') $dias[$dia]['v_pago']++; else $dias[$dia]['v_org']++;
    }

    // attach rate do order bump: pedidos com o principal que também levaram um extra
    $ped_principal = 0; $ped_com_bump = 0;
    foreach ($pedidos as $itens) {
        if (!isset($itens[$principal])) continue;
        $ped_principal++;
        if (count($itens) > 1) $ped_com_bump++;
    }

    // ---------- série diária ----------
    ksort($dias);
    $serie = []; $cum = 0; $cum_pago = 0; $cum_spend = 0.0; $cum_fat = 0.0;
    $ini = new DateTime($cfg['campanha_start'], new DateTimeZone($tz));
    $fim = new DateTime($hoje, new DateTimeZone($tz));
    for ($d = clone $ini; $d <= $fim; $d->modify('+1 day')) {
        $k = $d->format('Y-m-d');
        $v = $dias[$k] ?? ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'lpv' => 0, 'ic' => 0,
                           'compra_pixel' => 0, 'v_total' => 0, 'v_pago' => 0, 'v_org' => 0, 'fat' => 0];
        $g = $v['spend'] * $mult;
        $cum += $v['v_total']; $cum_pago += $v['v_pago'];
        $cum_spend += $g;      $cum_fat += $v['fat'];
        $serie[] = [
            'data' => $k, 'investido' => round($g, 2),
            'vendas' => $v['v_total'], 'vendas_pago' => $v['v_pago'], 'vendas_org' => $v['v_org'],
            'faturamento' => round($v['fat'], 2),
            'cpa'        => dash_div($g, $v['v_pago']),
            'acumulado'  => $cum, 'acumulado_pago' => $cum_pago,
            'cpa_acum'   => dash_div($cum_spend, $cum_pago),
            'roas'       => dash_div($cum_fat, $cum_spend),
            'impressoes' => $v['impressions'], 'cliques' => $v['clicks'],
            'lpv' => (int) $v['lpv'], 'checkouts' => (int) $v['ic'],
            'compra_pixel' => (int) $v['compra_pixel'],
        ];
    }

    // ---------- ritmo ----------
    $ritmo = function ($ini, $fimStr, $meta) use ($tz, $fim) {
        $a = new DateTime($ini, new DateTimeZone($tz));
        $b = new DateTime($fimStr, new DateTimeZone($tz));
        $janela = (int) $a->diff($b)->format('%a') + 1;
        $dec    = (int) $a->diff($fim)->format('%r%a') + 1;
        $dec    = max(0, min($janela, $dec));
        return ['janela' => $janela, 'decorridos' => $dec, 'restantes' => max(0, $janela - $dec),
                'esperado' => (int) round($meta * $dec / max(1, $janela))];
    };
    $rp = $ritmo($cfg['capture_start'],  $cfg['capture_end'], $cfg['meta_vendas_pagas']);
    $rt = $ritmo($cfg['campanha_start'], $cfg['capture_end'], $cfg['meta_vendas_total']);

    // ---------- KPIs ----------
    $inv     = $tot['spend'] * $mult;
    $alcance = (int) ($midia['conta']['reach'] ?? 0);
    $freq    = $alcance > 0 ? round($tot['impressions'] / $alcance, 2) : null;
    $vTotal  = $V['pago'] + $V['organico'] + $V['sem_origem'];
    $fTotal  = $F['pago'] + $F['organico'] + $F['sem_origem'];
    $lTotal  = $L['pago'] + $L['organico'] + $L['sem_origem'];
    $iTotal  = $inscr['pago'] + $inscr['organico'] + $inscr['sem_origem'];

    $kpi = [
        'investido'      => round($inv, 2),
        'investido_liq'  => round($tot['spend'], 2),
        'inscricoes_pago'      => $inscr['pago'],
        'inscricoes_organico'  => $inscr['organico'] + $inscr['sem_origem'],
        'inscricoes_total'     => $iTotal,
        'vendas_pago'    => $V['pago'],
        'vendas_organico'=> $V['organico'] + $V['sem_origem'],
        'vendas_total'   => $vTotal,
        'fat_pago'       => round($F['pago'], 2),
        'fat_organico'   => round($F['organico'] + $F['sem_origem'], 2),
        'fat_total'      => round($fTotal, 2),
        'liq_total'      => round($lTotal, 2),
        'liq_pago'       => round($L['pago'], 2),
        'cpa'            => dash_div($inv, $inscr['pago']),          // por INSCRIÇÃO paga
        'cpa_transacao'  => dash_div($inv, $V['pago']),              // por transação (com bump)
        'roas'           => dash_div($F['pago'], $inv),
        // ROAS de equilíbrio da meta: um CPA no alvo, num ticket cheio, dá este ROAS.
        'roas_alvo'      => dash_div($cfg['produtos'][$principal]['ticket'] ?? 0, $cfg['cpa_target']),
        'roas_liquido'   => dash_div($L['pago'], $inv),
        'ticket_medio'   => dash_div($fTotal, $vTotal),
        'cpc'            => dash_div($inv, $tot['clicks']),
        'cpm'            => dash_div($inv * 1000, $tot['impressions']),
        'custo_lpv'      => dash_div($inv, $tot['lpv']),
        'custo_checkout' => dash_div($inv, $tot['ic']),
        'ctr'            => dash_div($tot['clicks'] * 100, $tot['impressions']),
        'connect_rate'   => dash_div($tot['lpv'] * 100, $tot['clicks']),
        'lpv_checkout'   => dash_div($tot['ic'] * 100, $tot['lpv']),
        'checkout_venda' => dash_div($V['pago'] * 100, $tot['ic']),
        'conv_pagina'    => dash_div($V['pago'] * 100, $tot['lpv']),
        'frequencia'     => $freq,
        'alcance'        => $alcance,
        'impressoes'     => $tot['impressions'],
        'cliques'        => $tot['clicks'],
        'lpv'            => (int) $tot['lpv'],
        'checkouts'      => (int) $tot['ic'],
        'compra_pixel'   => (int) $tot['compra_pixel'],
        'estornos'       => $estornos,
        'fat_estornado'  => round($fat_estornado, 2),
        // Attach rate de verdade = extra comprado NO MESMO pedido. O extra vendido
        // em compra separada (transação sem o irmão C1/C2) não é attach; contá-lo
        // junto infla a taxa e some com a distinção que decide o order bump.
        'attach_rate'    => dash_div($ped_com_bump * 100, $ped_principal, 1),
        'pedidos'        => $ped_principal,
        'pedidos_bump'   => $ped_com_bump,
        'extras_total'   => $vTotal - $iTotal,
        'extras_avulsos' => ($vTotal - $iTotal) - $ped_com_bump,
        'vendas_sem_etapa' => $vSemEtapa,
        'investido_fora_etapa' => round($fora_etapa * $mult, 2),

        'meta_pagas'  => $cfg['meta_vendas_pagas'],
        'meta_total'  => $cfg['meta_vendas_total'],
        'cpa_target'  => $cfg['cpa_target'],
        'pct_meta_pagas' => round($inscr['pago'] / max(1, $cfg['meta_vendas_pagas']) * 100, 1),
        'pct_meta_total' => round($iTotal / max(1, $cfg['meta_vendas_total']) * 100, 1),

        'dias_janela'     => $rp['janela'],
        'dias_decorridos' => $rp['decorridos'],
        'dias_restantes'  => $rp['restantes'],
        'esperado_pagas'  => $rp['esperado'],
        'esperado_total'  => $rt['esperado'],
        'dias_janela_total'     => $rt['janela'],
        'dias_decorridos_total' => $rt['decorridos'],
        'ritmo_necessario' => $rp['restantes'] > 0
            ? (int) ceil(max(0, $cfg['meta_vendas_pagas'] - $inscr['pago']) / $rp['restantes']) : null,
        'verba_restante' => round(max(0, $cfg['meta_vendas_pagas'] - $inscr['pago']) * $cfg['cpa_target'], 2),
        'verba_esperada' => round($cfg['verba_total'] * $rp['decorridos'] / max(1, $rp['janela']), 2),
        'verba_total'    => $cfg['verba_total'],
    ];

    // ---------- funil por etapa ----------
    $funis = [];
    foreach ($etapas as $i => $e) {
        $t  = $te[$i];
        $g  = $t['spend'] * $mult;
        $funis[] = [
            'chave'      => 'e' . $i,
            'rot'        => $e['rot'],
            'cpa_alvo'   => $e['cpa_alvo'],
            'investido'  => round($g, 2),
            'impressoes' => $t['impressions'],
            'cliques'    => $t['clicks'],
            'lpv'        => (int) $t['lpv'],
            'checkouts'  => (int) $t['ic'],
            'inscricoes' => $vEtapa[$i],
            'vendas'     => $tEtapa[$i],
            'faturamento'=> round($fEtapa[$i], 2),
            'cpm'        => dash_div($g * 1000, $t['impressions']),
            'cpc'        => dash_div($g, $t['clicks']),
            'custo_lpv'  => dash_div($g, $t['lpv']),
            'custo_checkout' => dash_div($g, $t['ic']),
            'cpa'        => dash_div($g, $vEtapa[$i]),
            'roas'       => dash_div($fEtapa[$i], $g),
            'ctr'        => dash_div($t['clicks'] * 100, $t['impressions']),
            'connect_rate'   => dash_div($t['lpv'] * 100, $t['clicks'], 1),
            'lpv_checkout'   => dash_div($t['ic'] * 100, $t['lpv'], 1),
            'checkout_venda' => dash_div($tEtapa[$i] * 100, $t['ic'], 1),
            'compra_pixel'   => (int) $t['compra_pixel'],
        ];
    }

    // ---------- roscas ----------
    $prodItens = [];
    foreach ($cfg['produtos'] as $slug => $p) {
        $prodItens[] = ['rot' => $p['rot'], 'valor' => $porProduto[$slug]['vendas'] ?? 0];
    }
    foreach ($porProduto as $slug => $p) {
        if (!isset($cfg['produtos'][$slug])) $prodItens[] = ['rot' => $slug, 'valor' => $p['vendas']];
    }

    uasort($porOrganico, function ($a, $b) { return $b['v'] <=> $a['v']; });
    $orgItens = [];
    foreach ($porOrganico as $k => $v) $orgItens[] = ['rot' => str_replace(' / ', ' · ', $k), 'valor' => $v['v']];

    $roscas = [
        'origem' => [
            ['rot' => 'Tráfego pago', 'valor' => $V['pago']],
            ['rot' => 'Orgânico',     'valor' => $V['organico']],
            ['rot' => 'Sem origem',   'valor' => $V['sem_origem'], 'neutro' => true],
        ],
        'organico' => $orgItens,
        'produto'  => $prodItens,
    ];

    // ---------- tabelas ----------
    $t_org = [];
    foreach ($porOrganico as $k => $v) {
        [$s, $m] = array_pad(explode(' / ', $k, 2), 2, '');
        $t_org[] = ['source' => $s, 'medium' => $m, 'inscricoes' => $v['i'],
                    'vendas' => $v['v'], 'faturamento' => round($v['f'], 2)];
    }

    $t_sets = [];
    foreach ($sets as $id => $s) {
        $g  = $s['spend'] * $mult;
        $ie = $setEtapa[$id] ?? null;
        $t_sets[] = [
            'id' => (string) $id, 'nome' => $s['nome'],
            'etapa' => $ie !== null ? $etapas[$ie]['rot'] : '—',
            'investido' => round($g, 2), 'impressoes' => $s['impressions'], 'cliques' => $s['clicks'],
            'ctr' => dash_div($s['clicks'] * 100, $s['impressions']),
            'cpm' => dash_div($g * 1000, $s['impressions']),
            'cpc' => dash_div($g, $s['clicks']),
            'lpv' => (int) $s['lpv'], 'checkouts' => (int) $s['ic'],
            'connect' => dash_div($s['lpv'] * 100, $s['clicks'], 1),
            'inscricoes' => $porSet[$id]['i'] ?? 0,
            'vendas' => $porSet[$id]['v'] ?? 0,
            'faturamento' => round($porSet[$id]['f'] ?? 0, 2),
            'compra_pixel' => (int) $s['compra_pixel'],
            'cpa'  => dash_div($g, $porSet[$id]['i'] ?? 0),
            'roas' => dash_div($porSet[$id]['f'] ?? 0, $g),
            'cpa_alvo' => $ie !== null ? $etapas[$ie]['cpa_alvo'] : $cfg['cpa_target'],
        ];
    }
    usort($t_sets, function ($x, $y) { return $y['investido'] <=> $x['investido']; });

    $t_ads = [];
    foreach ($ads as $id => $a) {
        $g   = $a['spend'] * $mult;
        $sid = $adSet[$id] ?? '';
        $ie  = $setEtapa[$sid] ?? null;
        $t_ads[] = [
            'id' => (string) $id, 'nome' => $a['nome'], 'conjunto' => $a['conjunto'],
            'investido' => round($g, 2), 'impressoes' => $a['impressions'], 'cliques' => $a['clicks'],
            'ctr' => dash_div($a['clicks'] * 100, $a['impressions']),
            'cpm' => dash_div($g * 1000, $a['impressions']),
            'cpc' => dash_div($g, $a['clicks']),
            'lpv' => (int) $a['lpv'], 'checkouts' => (int) $a['ic'],
            'connect' => dash_div($a['lpv'] * 100, $a['clicks'], 1),
            'inscricoes' => $porAd[$id]['i'] ?? 0,
            'vendas' => $porAd[$id]['v'] ?? 0,
            'faturamento' => round($porAd[$id]['f'] ?? 0, 2),
            'compra_pixel' => (int) $a['compra_pixel'],
            'cpa'  => dash_div($g, $porAd[$id]['i'] ?? 0),
            'roas' => dash_div($porAd[$id]['f'] ?? 0, $g),
            'cpa_alvo' => $ie !== null ? $etapas[$ie]['cpa_alvo'] : $cfg['cpa_target'],
        ];
    }
    usort($t_ads, function ($x, $y) { return $y['investido'] <=> $x['investido']; });

    $t_prod = [];
    foreach ($porProduto as $slug => $p) {
        $t_prod[] = [
            'slug' => $slug,
            'rot'  => $cfg['produtos'][$slug]['rot'] ?? $slug,
            'ticket' => $cfg['produtos'][$slug]['ticket'] ?? null,
            'vendas' => $p['vendas'], 'pago' => $p['pago'],
            'organico' => $p['vendas'] - $p['pago'],
            'faturamento' => round($p['valor'], 2),
            'ticket_real' => dash_div($p['valor'], $p['vendas']),
        ];
    }
    usort($t_prod, function ($x, $y) { return $y['faturamento'] <=> $x['faturamento']; });

    return [
        'gerado_em' => $agora->format('c'),
        'midia'     => ['fonte' => $midia['fonte'], 'em' => $midia['em'], 'erro' => $midia['erro']],
        'kpi'       => $kpi,
        'funis'     => $funis,
        'roscas'    => $roscas,
        'serie'     => $serie,
        'conjuntos' => $t_sets,
        'anuncios'  => $t_ads,
        'organico'  => $t_org,
        'produtos'  => $t_prod,
        'cfg'       => [
            'cliente' => $cfg['cliente'], 'campanha' => $cfg['campanha'], 'objetivo' => $cfg['objetivo'],
            'meta_pagas' => $cfg['meta_vendas_pagas'], 'meta_total' => $cfg['meta_vendas_total'],
            'cpa_target' => $cfg['cpa_target'], 'tax' => $cfg['tax'],
            'campanha_start' => $cfg['campanha_start'], 'start' => $cfg['start'],
            'capture_start' => $cfg['capture_start'], 'capture_end' => $cfg['capture_end'],
            'ttl' => $cfg['cache_ttl'], 'produto_principal_rot' => $cfg['produtos'][$principal]['rot'] ?? $principal,
            'verba_total' => $cfg['verba_total'],
        ],
    ];
}

// ---------------------------------------------------------------- cache

function dash_payload($cfg, $forcar = false)
{
    $dir = $cfg['cache_dir'];
    @mkdir($dir, 0750, true);
    $arquivo = $dir . '/cache.json';
    $lock    = $dir . '/cache.lock';
    $cache   = is_readable($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
    $idade   = $cache ? (time() - ($cache['ts'] ?? 0)) : PHP_INT_MAX;

    if (!$forcar && $cache && $idade < $cfg['cache_ttl']) {
        $cache['dados']['cache'] = ['idade' => $idade, 'stale' => false, 'erro' => null];
        return $cache['dados'];
    }

    $fh = fopen($lock, 'c');
    if ($fh && !flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        if ($cache) {
            $cache['dados']['cache'] = ['idade' => $idade, 'stale' => true, 'erro' => null];
            return $cache['dados'];
        }
        $fh = null;
    }

    try {
        $dados = dash_build($cfg);
        file_put_contents($arquivo, json_encode(['ts' => time(), 'dados' => $dados]), LOCK_EX);
        $dados['cache'] = ['idade' => 0, 'stale' => false, 'erro' => null];
    } catch (Exception $e) {
        if (!$cache) throw $e;
        $dados = $cache['dados'];
        $dados['cache'] = ['idade' => $idade, 'stale' => true, 'erro' => $e->getMessage()];
    } finally {
        if ($fh) { flock($fh, LOCK_UN); fclose($fh); }
    }
    return $dados;
}
