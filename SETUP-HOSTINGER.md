# Configuração na Hostinger (uma vez só)

Você faz isto **uma única vez**. Depois, é só me chamar pra manter os arquivos.

---

## Passo 1 — Conectar o GitHub à Hostinger (Git Deploy)

1. Entre no **hPanel** da Hostinger.
2. Vá em **Sites → (seu site) → Avançado → GitHub** (ou "Git").
3. Conecte sua conta do GitHub e selecione o repositório **`nath-freitas/start`**.
4. Em "branch", escolha a branch que vamos publicar (ex.: `main`).
5. Em "diretório", aponte para **`public_html`**.
6. Ative o **deploy automático** (ou "auto deployment"), se houver a opção.

A partir daí, todo `push` para a branch publica o site sozinho.

> Se a sua versão do painel não tiver Git, dá pra usar **FTP** como alternativa —
> me avise que eu te explico.

---

## Passo 2 — Criar o arquivo de senhas (`.htpasswd`)

Esse arquivo guarda os usuários e senhas dos clientes. Ele **não fica no GitHub**
por segurança e deve ficar **fora da pasta pública** (`public_html`).

1. No hPanel, abra o **Gerenciador de Arquivos**.
2. Na raiz da sua conta (um nível **acima** de `public_html`), crie uma pasta
   chamada `seguranca`.
3. Dentro dela, crie um arquivo chamado `.htpasswd`.
4. Descubra o **caminho absoluto** dessa pasta. Costuma ser algo como:
   `/home/u123456789/seguranca/.htpasswd`
   (o `u123456789` é o seu usuário Hostinger — aparece no painel).

---

## Passo 3 — Ajustar o caminho nos `.htaccess`

Nos arquivos `.htaccess` de cada cliente existe esta linha:

```
AuthUserFile "/home/SEU_USUARIO_HOSTINGER/seguranca/.htpasswd"
```

Troque `SEU_USUARIO_HOSTINGER` pelo seu usuário real (ex.: `u123456789`).
**Me passe esse usuário que eu faço essa troca em todos os arquivos de uma vez.**

---

## Passo 4 — Cadastrar usuários e senhas

Para cada cliente, é preciso uma linha no `.htpasswd` no formato:

```
nome-do-cliente:HASH_DA_SENHA
```

O `HASH_DA_SENHA` é a senha criptografada (nunca a senha em texto puro).

- **Opção fácil:** me diga o nome do cliente e a senha desejada que eu **gero o
  hash** pra você colar no `.htpasswd`.
- **Opção visual:** alguns painéis Hostinger têm "Proteção por senha de
  diretórios", que cria o `.htpasswd` por você na interface.

### Exemplo já incluso neste repo

Há um `cliente-exemplo` pronto. Para testá-lo, adicione esta linha ao seu
`.htpasswd` (usuário: `cliente-exemplo`, senha: `troque-me-123`):

```
cliente-exemplo:$apr1$.idsm3sb$ImnjEqtZwWXz8pdG31zuF1
```

> ⚠️ Troque essa senha de exemplo antes de usar pra valer.

---

## Pronto!

Depois disso, o fluxo do dia a dia é:

1. Você joga os conteúdos no Google Drive (ou me pede direto).
2. Você me chama: *"atualiza a área do cliente X"*, *"cria área pro cliente Y"*, etc.
3. Eu edito, padronizo, faço commit e push.
4. A Hostinger publica sozinha. 🎉
