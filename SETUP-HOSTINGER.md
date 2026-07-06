# Configuração na Hostinger — Portal em SUBDOMÍNIO (seguro)

> ⚠️ **REGRA DE OURO:** o deploy deste repositório **NUNCA** pode apontar para
> `public_html` (onde mora o WordPress principal). Ele aponta **somente** para a
> pasta dedicada do subdomínio `clientes.catapultadeideias.com.br`.
> O Git deploy apaga tudo que não faz parte do repositório dentro da pasta de
> destino — por isso a pasta de destino precisa ser exclusiva do portal.

---

## Passo 1 — Criar o subdomínio

1. hPanel → **Domínios** → **Subdomínios**.
2. Crie o subdomínio: `clientes` (ficará `clientes.catapultadeideias.com.br`).
3. A Hostinger cria uma **pasta dedicada** para ele. **Anote o caminho exato**
   que aparece — costuma ser algo como:
   `/home/u346131448/domains/clientes.catapultadeideias.com.br/public_html`

---

## Passo 2 — Conferir que a pasta do subdomínio está VAZIA

1. Abra o **Gerenciador de Arquivos** e vá até a pasta do subdomínio (o caminho
   do passo anterior).
2. Se houver algum arquivo de exemplo (ex.: `default.php`), pode apagar.
   A pasta precisa estar limpa antes do deploy.
3. Confirme que essa pasta **NÃO é** a `public_html` do WordPress.

---

## Passo 3 — Conectar o Git deploy NESTA pasta

1. hPanel → **Avançado** → **GitHub** (ou **Git**).
2. Repositório: `nath-freitas/start`
3. Branch: `claude/focused-fermi-737a10`
4. **Diretório de destino:** a pasta do subdomínio do Passo 1
   (ex.: `domains/clientes.catapultadeideias.com.br/public_html`).
   **NÃO** deixe em branco e **NÃO** use `public_html`.
5. Ative a **implantação automática**.
6. Clique em **Deploy**.

---

## Passo 4 — O arquivo de senhas (já feito)

O `.htpasswd` já está em `/home/u346131448/seguranca/.htpasswd` e é usado por
todos os clientes. Cada pasta de cliente tem um `.htaccess` que aponta para ele.

Conteúdo atual do `.htpasswd` (usuário : hash):

```
joy-alano:$apr1$bUZ9LnSH$Z7/nlbiV0b3Tcf4XqNbGj.
lu-viana:$apr1$HvYA8mBW$iqPKVOkyiZFYGR5sjUvQq1
camila-koszka:$apr1$ksP2wVt2$NfAcXTzKkjfYR4mauzVUw.
laisse-moreira:$apr1$6vJfvi5/$/PS4bo1ywXlt9Jkp728CP0
luana-lima:$apr1$81LG9oVG$1U29Wl2irMPebdXZoeL3i0
```

---

## Passo 5 — Testar

Abra no navegador (deve pedir usuário/senha):

- `https://clientes.catapultadeideias.com.br/joy-alano/` → `joy-alano` / `Joy@CTP`
- `https://clientes.catapultadeideias.com.br/lu-viana/` → `lu-viana` / `Lu@CTP`
- `https://clientes.catapultadeideias.com.br/camila-koszka/` → `camila-koszka` / `Camila@CTP`
- `https://clientes.catapultadeideias.com.br/laisse-moreira/` → `laisse-moreira` / `Laisse@CTP`
- `https://clientes.catapultadeideias.com.br/luana-lima/` → `luana-lima` / `Luana@CTP`

A página inicial `https://clientes.catapultadeideias.com.br/` é pública (só uma
tela de boas-vindas). O conteúdo protegido fica nas pastas de cada cliente.

---

## Dia a dia

1. Você joga os conteúdos no Google Drive (ou me pede direto).
2. Você me chama: "atualiza a área da Joy", "cria área pro cliente novo X", etc.
3. Eu edito, faço commit e push.
4. A Hostinger publica **só no subdomínio** — seu WordPress nunca é tocado.
