# Portal de Clientes

Site de áreas logadas por cliente, hospedado na Hostinger.
Cada cliente acessa **apenas a pasta dele**, protegida por usuário e senha.

## Como funciona (visão geral)

```
GOOGLE DRIVE          →   GITHUB (este repo)      →   HOSTINGER
rascunho dos HTML         versão final + ponte        site no ar com login
```

- O **rascunho/biblioteca** dos conteúdos fica no Google Drive.
- A **versão final** vem pra este repositório (uma subpasta por cliente).
- A Hostinger puxa deste repositório via **Git Deploy** e publica sozinha.
- Cada pasta de cliente é protegida por `.htaccess` + `.htpasswd` (login).

## Estrutura

```
.
├── index.html                  # página inicial pública (opcional)
├── .htaccess                   # regras gerais de segurança
├── clientes/
│   ├── _TEMPLATE/              # modelo para criar um cliente novo (NÃO publicar)
│   │   ├── .htaccess
│   │   └── index.html
│   └── cliente-exemplo/        # exemplo de área de cliente
│       ├── .htaccess
│       └── index.html
```

> O arquivo `.htpasswd` (com os usuários e senhas) **não fica neste repositório**
> por segurança. Ele é criado uma vez no servidor, fora da pasta pública.
> Veja `SETUP-HOSTINGER.md`.

## Adicionar um cliente novo

1. Copie a pasta `clientes/_TEMPLATE/` para `clientes/nome-do-cliente/`.
2. No `.htaccess` da nova pasta, troque `Require user _TEMPLATE_` por
   `Require user nome-do-cliente`.
3. Gere o usuário/senha e adicione ao `.htpasswd` do servidor
   (veja `SETUP-HOSTINGER.md`).
4. `git add . && git commit -m "novo cliente: nome-do-cliente" && git push`.
   A Hostinger publica automaticamente.
