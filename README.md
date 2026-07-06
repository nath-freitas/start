# Portal de Clientes

Site de áreas logadas por cliente, hospedado num **subdomínio isolado** da
Hostinger: `clientes.catapultadeideias.com.br`.
Cada cliente acessa **apenas a pasta dele**, protegida por usuário e senha.

> ⚠️ **REGRA DE OURO:** este repositório é publicado **somente** na pasta do
> subdomínio `clientes.catapultadeideias.com.br` — **NUNCA** em `public_html`
> (onde mora o WordPress principal). Ver `SETUP-HOSTINGER.md`.

## Como funciona (visão geral)

```
GOOGLE DRIVE          →   GITHUB (este repo)      →   HOSTINGER (subdomínio)
rascunho dos HTML         versão final + ponte        clientes.catapultadeideias.com.br
```

- O **rascunho/biblioteca** dos conteúdos fica no Google Drive.
- A **versão final** vem pra este repositório (uma pasta por cliente).
- A Hostinger puxa deste repositório via **Git Deploy** e publica no subdomínio.
- Cada pasta de cliente é protegida por `.htaccess` + `.htpasswd` (login).

## Estrutura

```
.
├── index.html              # página inicial pública do portal
├── .htaccess               # regras gerais de segurança
├── _TEMPLATE/              # modelo para criar cliente novo (fica bloqueado)
│   ├── .htaccess
│   └── index.html
├── joy-alano/              # → clientes.catapultadeideias.com.br/joy-alano/
├── lu-viana/
├── camila-koszka/
├── laisse-moreira/
└── luana-lima/
```

> O `.htpasswd` (usuários e senhas) **não fica neste repositório**. Ele vive no
> servidor, fora da pasta pública: `/home/u346131448/seguranca/.htpasswd`.

## Adicionar um cliente novo

1. Copie a pasta `_TEMPLATE/` para `nome-do-cliente/`.
2. No `.htaccess` da nova pasta, troque `Require user _TEMPLATE_` por
   `Require user nome-do-cliente`.
3. Adicione a linha do cliente ao `.htpasswd` do servidor (peça o hash).
4. `git add . && git commit -m "novo cliente: nome-do-cliente" && git push`.
   A Hostinger publica automaticamente **no subdomínio**.

## Clientes atuais

| Cliente | Usuário | Link |
|---|---|---|
| Joy Alano | `joy-alano` | `/joy-alano/` |
| Lu Viana | `lu-viana` | `/lu-viana/` |
| Camila Koszka | `camila-koszka` | `/camila-koszka/` |
| Laisse Moreira | `laisse-moreira` | `/laisse-moreira/` |
| Luana Lima | `luana-lima` | `/luana-lima/` |
