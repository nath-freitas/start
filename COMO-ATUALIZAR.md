# Como me mandar atualizações

Copie o modelo abaixo, preencha só o que precisa e me mande. Não precisa seguir
formato rígido — pode mandar solto que eu organizo. O importante é dizer **qual
cliente** e **o que entra**.

```
CLIENTE: jg   (ou o nome: Jorge Grimberg)

NOVA CALL:
- data: 2026-07-05
- temas: (resumo do que foi tratado)
- link da gravação: https://...

NOVO MATERIAL (entrega estratégica):
- em qual bloco: Diagnóstico | Revisão de Fundamentos | Plano Estratégico
- título: Parecer sobre a tese central
- tipo: documento | gravacao | pdf | planilha | apresentacao | link
- link: https://...

NOVA CAMPANHA:
- título:
- status: ativa | no-ar | em-breve
- link:

NOVO RELATÓRIO:
- período: Julho 2026
- link:

MUDANÇAS NO TOPO:
- faturamento / início / instagram / pasta de entregas: ...

OBJETIVOS (se mudar):
- ...
```

## Exemplos de mensagens curtas que funcionam

> "No jg, adiciona uma call de 05/07 sobre planejamento do lançamento, gravação: <link>"

> "Cria a central da Lu Viana (slug luviana), Instagram @luviana, e coloca o
> primeiro material no Diagnóstico: 'Parecer inicial', documento, link <link>"

> "Na central do jg, marca o Diagnóstico como entregue e adiciona o link do
> parecer: <link>"

## Tipos de material aceitos (campo `tipo`)

`documento` · `gravacao` · `pdf` · `planilha` · `apresentacao` · `link`
(cada um mostra um ícone diferente na página)

## Depois que eu te devolver o arquivo

1. Abra o Gerenciador de Arquivos → `public_html/cliente/<slug>/`
2. Suba o `index.html` novo, substituindo o antigo.
3. Pronto — atualiza na hora. (O `save.php` e a proteção de senha ficam intactos.)
