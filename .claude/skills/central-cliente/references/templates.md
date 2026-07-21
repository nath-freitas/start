# Templates verbatim

Textos prontos para copiar. Troque `<slug>` e `<material>` conforme o caso.

## save.php — Plano de ação (dados fora da pasta)

Contrato do index.html da central: `GET` devolve `{plano:[...]}`; `POST {plano}`
grava e devolve `{saved_at}`. Grava em `/dados/` para o deploy não apagar.

```php
<?php
header('Content-Type: application/json; charset=utf-8');
$dataFile = '/home/u346131448/dados/<slug>-plano.json';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $in = json_decode(file_get_contents('php://input'), true);
  $plano = (is_array($in) && isset($in['plano']) && is_array($in['plano'])) ? $in['plano'] : [];
  @mkdir(dirname($dataFile), 0755, true);
  $payload = json_encode(['plano' => $plano, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (@file_put_contents($dataFile, $payload, LOCK_EX) === false) { http_response_code(500); echo json_encode(['error'=>'write_failed']); exit; }
  echo $payload; exit;
}
echo is_file($dataFile) ? file_get_contents($dataFile) : json_encode(['plano' => []]);
```

## save.php de material interativo próprio (canvas, painel)

Quando o material já vem com seu próprio `save.php`, **não reescreva** — só mude
o caminho do arquivo de dados para fora da pasta e garanta o `@mkdir`:

```php
$FILE = '/home/u346131448/dados/<slug>-<material>.json';   // era __DIR__ . '/algo.json'
// ...antes do file_put_contents:
@mkdir(dirname($FILE), 0755, true);
```
Mantenha `save.php` na MESMA pasta do material (o HTML faz `fetch('save.php')`
relativo). Só o destino dos dados muda.

## .htaccess de cliente (`<slug>/.htaccess`)

```
AuthType Basic
AuthName "Central de Entregas — acesso restrito"
AuthUserFile "/home/u346131448/public_html/cliente/<slug>/.htpasswd"
Require valid-user
<FilesMatch "^\.ht">
  Require all denied
</FilesMatch>
```

## .htpasswd de cliente (`<slug>/.htpasswd`)

Uma linha, `usuario:hash` (apr1):

```bash
echo "<slug>:$(openssl passwd -apr1 'PrimeiroNome@CTP')"
```

## .htaccess da raiz do subdomínio (`public_html/cliente/.htaccess`)

Já existe na branch; só recrie se sumir. Esconde a lista de pastas de clientes e
protege os `.htpasswd`:

```
Options -Indexes
<FilesMatch "^\.ht">
  Require all denied
</FilesMatch>
```

## Landing neutra da raiz (`public_html/cliente/index.html`)

Página do subdomínio sem dado de cliente (evita 403 na raiz):

```html
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Catapulta de Ideias · Central de Clientes</title>
<style>body{font-family:system-ui,-apple-system,sans-serif;background:#EBE1CD;color:#16130E;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;text-align:center;padding:24px}.c{max-width:460px}h1{font-size:1.5rem;margin:0 0 12px}p{color:#736A57;line-height:1.6}</style>
</head>
<body><div class="c"><h1>Central de Clientes</h1><p>Área restrita. Cada cliente acessa seu espaço por um link próprio e protegido por senha. Se você é cliente e não tem o seu link, entre em contato.</p></div></body>
</html>
```

## Clientes ativos (referência)

| Cliente | slug | login |
|---|---|---|
| Jorge Grimberg | `jg` | `jorge` / `grimberg2026` |
| Laisse Moreira | `laisse` | `laisse` / `Laisse@CTP` |

Dados vivos ficam em `/home/u346131448/dados/` (ex.: `jg-plano.json`,
`jg-canvas.json`, `laisse-plano.json`, `laisse-painel-campanha.json`).
