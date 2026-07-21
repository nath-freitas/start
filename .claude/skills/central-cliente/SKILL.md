---
name: central-cliente
description: >-
  Cria e atualiza as Centrais de Entrega dos clientes da Catapulta de Ideias
  (site cliente.catapultadeideias.com.br, publicado via Git na Hostinger).
  Use SEMPRE que a Nathalia pedir para criar a central de um cliente novo,
  ou atualizar a central de um cliente existente: adicionar/linkar um material
  (HTML, Google Docs/Sheets/Slides, painel de campanha, canvas), adicionar uma
  call do tl;dv, mudar o status de uma etapa (Diagnóstico/Fundamentos/Plano),
  criar página secundária, ou publicar qualquer mudança nas centrais. Acione
  também com frases como "central do jorge", "central da laisse", "adiciona esse
  material na central", "linka em fundamentos", "novo cliente na central",
  "atualiza o plano estratégico", "pega a call de hoje no tldv". Cobre a
  arquitetura (branch única deploy/cliente espelhando public_html/cliente),
  o padrão de login, o cuidado com dados que salvam no servidor, e o fluxo de
  publicação via git worktree.
---

# Central de Entregas — criação e atualização

Cada cliente tem uma **Central de Entregas**: uma página `index.html` bonita
(design system Nath Freitas) que reúne objetivos, entregas estratégicas, calls,
materiais e um plano de ação. Fica em `cliente.catapultadeideias.com.br/<slug>/`,
protegida por login, e é publicada automaticamente pela Hostinger a partir do Git.

## Fatos do ambiente (não mudam)

- **Domínio/subdomínio:** `cliente.catapultadeideias.com.br` → serve a pasta
  `public_html/cliente/` do servidor. O WordPress principal fica em `public_html`
  (raiz) e **nunca** pode ser tocado.
- **Usuário Hostinger:** `u346131448`. Caminho de um cliente no servidor:
  `/home/u346131448/public_html/cliente/<slug>/`.
- **Uma implantação só na Hostinger:** branch `deploy/cliente` → diretório
  `public_html/cliente`, auto-deploy ligado. **Cliente novo não exige mexer na
  Hostinger** — basta adicionar a pasta na branch e dar push.
- **O deploy é DESTRUTIVO:** ele deixa `public_html/cliente/` idêntica à branch
  `deploy/cliente` e apaga o que não estiver lá. Consequências:
  - Todo arquivo **estático** (index e páginas secundárias) precisa morar no repo.
  - Todo **dado vivo** (plano de ação, canvas, painéis que salvam) precisa ser
    gravado **FORA** da pasta publicada: em `/home/u346131448/dados/`. Assim o
    deploy nunca apaga o que o cliente/time preencher.
  - **Nunca** suba arquivo direto no Gerenciador de Arquivos da Hostinger — o
    próximo deploy apaga. Tudo passa pelo repositório.

## Estrutura de uma pasta de cliente (na branch `deploy/cliente`)

```
<slug>/
├── index.html            # a central (só o bloco CLIENTE muda por cliente)
├── .htaccess             # login (basic auth), aponta para o .htpasswd local
├── .htpasswd             # usuário:hash do cliente (vai junto no deploy)
├── save.php              # persistência do "Plano de ação" (grava em /dados/)
└── <pagina>/index.html   # páginas secundárias (parecer, panorama, painel…)
```
A raiz `public_html/cliente/` também tem um `.htaccess` (Options -Indexes +
protege `.ht*`) e um `index.html` neutro — landing do subdomínio.

## O coração: o bloco `CLIENTE` no index.html

O template é `_MODELO/index.html` (na branch de trabalho). Só muda o objeto
`const CLIENTE = {...}` no topo. Campos:

| Campo | Observação |
|---|---|
| `nome` | Nome do cliente |
| `instagram` | `{ url, seguidores }` (seguidores como "2.541") |
| `pastaEntregas` | link do Drive (opcional) |
| `faturamento12m`, `inicio` (AAAA-MM-DD), `duracaoDias` | cabeçalho |
| `atualizadoEm` | data da última atualização |
| `acesso` | **deixar em branco** (`usuario:"",senhaHash:"",senha:""`) → o gate JS do HTML se auto-libera e o login fica só no `.htaccess` (server-side, o que protege de verdade) |
| `objetivos` | lista de strings; use `*asterisco*` para grifar em amarelo |
| `estrategicas` | 3 blocos: `diagnostico`, `fundamentos`, `plano-estrategico` — cada um com `status` e `recursos[]` |
| `calls` | `{ data, temas, url }` — **recente sempre em cima** |
| `campanhas`, `relatoriosAtivo`, `relatorios` | condicionais (só aparecem com itens) |

**Status de etapa** (`status`): `em-breve` · `em-andamento` · `entregue`
(aparece em **azul** `#1E5AA8`) · `ativa` · `no-ar`.

**Tipo de recurso** (`tipo`): `documento` · `gravacao` · `pdf` · `planilha` ·
`apresentacao` · `link` (cada um mostra um ícone).

Um recurso é `{ titulo, tipo, url }`. Links do Google (Docs/Sheets/Slides) entram
como recursos normais — Sheets → `planilha`, Docs → `documento`, Slides →
`apresentacao`. Lembre a Nathalia de conferir o **compartilhamento** do arquivo
no Drive, senão o cliente não abre.

## Como os materiais chegam até você

1. **Anexados no chat** (preferido): estão em disco (`/root/.claude/uploads/...`),
   copie com `cp` — byte a byte, sem risco. É o mais rápido.
2. **Google Drive:** use as tools `mcp__Google_Drive__search_files` (por
   `parentId = '<id-da-pasta>'`) e `mcp__Google_Drive__download_file_content`
   (retorna base64). Ao escrever no repo, **confira o tamanho** decodificado
   contra `fileSize` do metadata — a transcrição de base64 pode corromper.
3. **Call do tl;dv:** `mcp__tldv__search-meetings` com `from`/`to` no dia e
   `query` com o nome do cliente; pegue a `url` da reunião substancial (ignore
   registros de poucos segundos, que são reconexões).

## Publicar: sempre via git worktree em `deploy/cliente`

Toda mudança nas centrais é feita em `deploy/cliente` e publicada com push
(auto-deploy). Padrão:

```bash
cd /home/user/start
WT=/tmp/wt-central && rm -rf "$WT"
git worktree add -q "$WT" deploy/cliente
cd "$WT"
# ...edite <slug>/index.html, adicione páginas/arquivos...
git add -A
git commit -q -m "<slug>: <o que mudou>"
git push origin deploy/cliente        # auto-deploy publica
cd /home/user/start && git worktree remove "$WT" --force && git worktree prune
```

Depois, peça pra confirmar em `cliente.catapultadeideias.com.br/<slug>/`
(com Ctrl+F5). Edições cirúrgicas no `index.html`: prefira o Edit tool com
strings exatas, ou Python com `assert` na string antiga antes de substituir —
nunca reescreva o arquivo inteiro às cegas.

## Receita 1 — Cliente novo

Peça à Nathalia: nome, slug, Instagram, início, objetivos, e materiais iniciais
(opcional). Senha padrão = `PrimeiroNome@CTP`, usuário = slug. Então, dentro de um
worktree em `deploy/cliente`:

1. `mkdir <slug>` e gere `<slug>/index.html` a partir de `_MODELO/index.html`,
   preenchendo o bloco CLIENTE. Aplique o azul no Entregue se o modelo ainda não
   tiver (`.status.entregue{color:#1E5AA8;} .status.entregue::before{background:#1E5AA8;}`).
2. `<slug>/.htaccess`:
   ```
   AuthType Basic
   AuthName "Central de Entregas — acesso restrito"
   AuthUserFile "/home/u346131448/public_html/cliente/<slug>/.htpasswd"
   Require valid-user
   <FilesMatch "^\.ht">
     Require all denied
   </FilesMatch>
   ```
3. `<slug>/.htpasswd`: `echo "<slug>:$(openssl passwd -apr1 'PrimeiroNome@CTP')"`.
4. `<slug>/save.php` (plano de ação, dados fora da pasta): veja o template em
   `references/templates.md`.
5. Commit + push. **Não precisa mexer na Hostinger** (a implantação única já pega).
6. Informe à Nathalia o link e o login (`<slug>` / `PrimeiroNome@CTP`).

## Receita 2 — Atualizar uma central existente

Dentro de um worktree em `deploy/cliente`, no `<slug>/index.html`:

- **Nova call:** insira `{ data, temas, url }` no **topo** de `CLIENTE.calls`.
- **Novo material (link do Google):** adicione um recurso na seção pedida
  (Diagnóstico → `diagnostico`; Revisão de Fundamentos → `fundamentos`;
  Planejamento/Plano → `plano-estrategico`).
- **Mudar status de etapa:** troque o `status` do bloco correspondente.
- **Página HTML secundária** (parecer, panorama, diagnóstico, etc.): crie
  `<slug>/<pagina>/index.html` e linke com `url` =
  `https://cliente.catapultadeideias.com.br/<slug>/<pagina>`. Ela herda o login
  da pasta do cliente automaticamente.

## Receita 3 — Material interativo que SALVA (canvas, painel de campanha)

Alguns materiais têm autosave via `save.php` (checklist, ROAS, canvas). O
`save.php` fica **na mesma pasta** do material (o HTML faz `fetch('save.php')`
relativo), MAS o arquivo de dados **tem que apontar para fora** da pasta
publicada, senão o deploy apaga o que o cliente preencher.

Ao receber um `save.php` de material, edite a linha do arquivo de dados para
`/home/u346131448/dados/<slug>-<material>.json` e garanta o `@mkdir`:

```php
$FILE = '/home/u346131448/dados/<slug>-<material>.json';   // fora da pasta
// ...
@mkdir(dirname($FILE), 0755, true);
file_put_contents($FILE, ...);
```

Depois avise a Nathalia que os dados ficam seguros a cada deploy, e que o teste
é: preencher algo no material, recarregar, e ver se continua lá.

## Ver `references/templates.md`

Contém os textos prontos (verbatim) do `save.php` do plano de ação, do `.htaccess`
de cliente e das regras do `.htaccess`/landing da raiz do subdomínio. Leia antes
de criar um cliente novo ou um novo `save.php`.
