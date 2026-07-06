# Central de Entregas — Portal de Clientes

Cada cliente tem uma **Central de Entregas** (um `index.html` completo) publicada em:

```
cliente.catapultadeideias.com.br/<slug>/
```

Exemplo: `cliente.catapultadeideias.com.br/jg/` (Jorge Grimberg).

No servidor Hostinger, os arquivos ficam em `public_html/cliente/<slug>/` — a pasta
do subdomínio, **isolada** do WordPress principal.

## A ideia central

Todo o visual, login e funcionamento são **iguais para todos os clientes**.
A única coisa que muda por cliente é o bloco **`const CLIENTE = {...}`** no topo
do `index.html`. Atualizar um cliente = editar esse bloco.

```
.
├── _MODELO/index.html   # modelo (bloco CLIENTE em branco) para clientes novos
├── jg/index.html        # Jorge Grimberg
└── (um folder por cliente…)
```

## O que dá pra atualizar (campos do bloco CLIENTE)

| Seção | Campo | O que é |
|---|---|---|
| Topo | `nome`, `instagram`, `pastaEntregas`, `faturamento12m`, `inicio`, `duracaoDias` | Dados de cabeçalho |
| Objetivos | `objetivos: [...]` | Lista de objetivos do projeto |
| Entregas estratégicas | `estrategicas[].recursos[]` | Materiais/links dentro de Diagnóstico, Fundamentos, Plano |
| Calls | `calls: [...]` | Data + temas + link da gravação |
| Campanhas | `campanhas: [...]` | Briefings de campanha |
| Relatórios | `relatoriosAtivo`, `relatorios: []` | Relatórios periódicos |

> **Plano de ação** NÃO fica no `CLIENTE` — ele é editável online e salva no
> `save.php` (dado do servidor). Não mexemos nele nas atualizações.

## Rotina de atualização

1. Você me manda o cliente + o que adicionar (ver `COMO-ATUALIZAR.md`).
2. Eu edito o `CLIENTE` do `index.html` daquele cliente, faço commit (histórico/backup).
3. Eu te devolvo o `index.html` pronto.
4. Você sobe **só esse `index.html`** na pasta do cliente pelo Gerenciador de
   Arquivos (substituindo o antigo). A página atualiza na hora.

Assim nunca encostamos no `save.php`, na proteção de senha do hPanel, nem no
WordPress. Risco zero.
