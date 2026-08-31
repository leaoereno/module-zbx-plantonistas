# Checklist de homologação — lab-zbx (192.168.0.151)

Tudo que sobrou para validar antes de produção. Marque conforme for passando.
Cada item diz **o que fazer**, **o que esperar** e, quando o teste é de uma
correção específica, **o que estava errado antes** — para você saber o que
está olhando.

> Nada aqui exige editar código. Se algum item falhar, o que interessa é o
> arquivo, a tela e a mensagem exata: com isso o conserto é direto.

---

## 0. Deploy no lab

```bash
cd /usr/share/zabbix/modules/module-zbx-plantonistas
git pull
chown -R apache:apache .
systemctl restart php-fpm
```

Depois, **abra qualquer tela do menu Plantão uma vez**. É esse primeiro
carregamento que roda o `Module::init()` e cria/migra as colunas novas
(`notified_at` na tabela de menções, entre outras). Vários itens abaixo
dependem disso.

- [ ] `git pull` sem conflito
- [ ] `systemctl status php-fpm` ativo
- [ ] menu **Plantão** aparece com 7 itens

---

## 1. Sintaxe PHP

Os 47 arquivos `.php` já passaram por um parser configurado no nível **PHP
8.0**, que é o piso do módulo. Rode assim mesmo no lab — é o `php` de verdade,
com as extensões do ambiente:

```bash
cd /usr/share/zabbix/modules/module-zbx-plantonistas
find . -name '*.php' -not -path './.git/*' -print0 \
  | xargs -0 -n1 php -d display_errors=1 -l 2>&1 \
  | grep -v 'No syntax errors'
```

O `2>&1` importa: sem ele, se o `php` não estiver no PATH a saída de erro some
e o teste "passa" com stdout vazio — que é justamente o critério de aprovação.

- [ ] saída **vazia** (qualquer linha impressa é um erro para trazer)

> ⚠️ **Este teste NÃO prova compatibilidade com PHP 8.0.** O lab roda 8.4, e o
> `php -l` valida contra a sintaxe do interpretador que executa. Construção que
> só existe de 8.1/8.2 em diante — `const` em trait, `enum`, `readonly`,
> `never` — passa limpa aqui e explode em produção, onde o RHEL entrega 8.0/8.1.
> Já aconteceu neste módulo uma vez (ver "Fechar turno" no CLAUDE.md).
>
> Se houver Docker no lab, este roda contra o piso de verdade:
>
> ```bash
> docker run --rm -v "$PWD:/src" -w /src php:8.0-cli \
>   sh -c "find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l" 2>&1 \
>   | grep -v 'No syntax errors'
> ```
>
> - [ ] (opcional, mas é o que vale) saída vazia no container 8.0

---

## 2. As 7 telas, nos DOIS temas

O visual foi unificado nesta rodada: as telas seguem o tema ativo do Zabbix em
vez de terem cor fixa. **Teste no tema claro e no escuro** (Perfil do usuário →
Tema). O tema claro é o padrão do Zabbix e é onde estava o risco — a família
Escala nunca tinha sido vista nele.

Em cada tela, olhe: texto legível, borda visível, nada branco-no-branco.

- [ ] Visão Geral — claro / escuro
- [ ] Escala — claro / escuro
- [ ] Histórico — claro / escuro
- [ ] Telefones — claro / escuro
- [ ] Repasse Plantão — claro / escuro
- [ ] Gerenciar Turnos — claro / escuro

Pontos que mudaram de cor e valem um olhar específico:

- [ ] **Abas de grupo** (Escala e Histórico), aba inativa — deve ser legível,
      não cinza-claro sobre cinza-claro
- [ ] **Badge "não cadastrado"** em Telefones — era quase invisível
- [ ] **"sem telefone"**, **"Nenhum histórico registrado"** e o **"—"** das
      células — texto auxiliar, mas é conteúdo
- [ ] **Modais** de importar (Escala e Telefones) — não podem ficar brancos no
      tema escuro
- [ ] **Painel de menções** (botão `@` no Repasse) — no tema escuro era caixa
      branca com texto branco

---

## 3. Escrita: salvar, remover, importar

O caminho de gravação foi reescrito: as actions respondem JSON quando chamadas
pela tela, e a página não recarrega mais em caso de erro.

### Escala

- [ ] Selecionar dias no calendário, escolher técnico, **Salvar** → mensagem
      verde e o calendário atualizado
- [ ] Grupo **com turnos** cadastrados: salvar preenchendo só um turno → os
      outros turnos do dia continuam como estavam
- [ ] **Remover** um plantão (de um dia que não é hoje) → some do calendário
- [ ] Remover o plantão de **hoje** → recusa com mensagem clara
- [ ] Salvar sem selecionar dia → mensagem de erro **na própria tela**, com o
      técnico ainda selecionado (antes recarregava e perdia tudo)

### Telefones

- [ ] Salvar um telefone → volta com a máscara aplicada
- [ ] Limpar o campo e salvar → telefone removido
- [ ] Um **Admin** tentar salvar telefone de um **Super Admin** → recusa
      ("papel mais alto que o seu"). Guarda nova; ver a nota no fim

### Importação (as duas telas)

- [ ] Importar CSV válido → mensagem verde e dados na tela
- [ ] Importar CSV com uma linha errada de propósito (usuário inexistente) →
      **as linhas boas entram** e o aviso aparece. Este é o ponto: importação
      com aviso precisa recarregar a tela mostrando o que entrou, não parecer
      que falhou inteira
- [ ] Importar arquivo com cabeçalho irreconhecível → erro claro, e o campo de
      arquivo é **limpo** (reenviar o mesmo daria o mesmo erro)
- [ ] Importar um **.xlsx** em Telefones (é novidade — antes só CSV)
- [ ] Importar um **.xls** antigo → recusa dizendo para salvar como .xlsx
      (nas **duas** telas: a guarda existia só em Telefones e foi replicada
      para a Escala nesta rodada, junto com o `.XLSX` maiúsculo do Windows)

---

## 4. CSV da Escala com turnos

O arquivo ganhou a coluna **Turno** como 3ª coluna.

- [ ] Exportar um grupo **com turnos** → o arquivo traz uma linha por turno por
      dia, com o nome do turno preenchido
- [ ] Reimportar esse mesmo arquivo sem editar → nada duplica, nada some
      (round-trip limpo)
- [ ] Exportar um grupo **sem turnos** → coluna Turno vazia, resto igual a antes
- [ ] Importar um arquivo **antigo** (7 colunas, sem Turno) num grupo que tem
      turnos → importa em modo legado e **avisa** quantas linhas ficaram sem
      turno
- [ ] Desativar um turno que tem escala gravada e exportar → a entrada aparece
      como `Nome (inativo)`, não some

---

## 5. Sessão expirada não pode perder o que foi digitado

Este é o teste da correção mais chata de reproduzir. Duas formas:

**A (mais fácil)** — abra a Escala, preencha tudo, e num **outro navegador ou
aba anônima** faça logout/login com o mesmo usuário para invalidar a sessão.
Volte na aba original e salve.

**B** — deixe a aba aberta além do tempo de sessão do Zabbix
(Administração → Geral → GUI → *Session timeout*, se estiver curto no lab).

- [ ] Escala: aparece aviso pedindo F5 e **o que foi preenchido continua lá**
- [ ] Telefones: idem, o número digitado continua no campo
- [ ] Remover na Escala: aparece **alert** (a mensagem embaixo ficaria fora da
      tela)
- [ ] Importar: o modal continua aberto

---

## 6. Repasse Plantão

- [ ] Relatório abre com KPIs, tabelas e os **dois gráficos**
- [ ] Simular o F5 bloqueando o Chart.js (DevTools → Network → bloquear a URL
      de `chart.min.js`, ou renomear o arquivo no servidor) → aparece a
      mensagem "Gráficos indisponíveis", e o botão "mudar formato" some junto

      Se os gráficos não aparecerem, a tela agora **diz isso** em vez de ficar
      em branco. A correção é ligar no pool do PHP-FPM
      (`/etc/php-fpm.d/zabbix.conf`) e reiniciar:

      ```ini
      env[PLANTONISTAS_CHART_INLINE] = 1
      ```

- [ ] Escrever nota no Diário de Bordo → aparece na lista
- [ ] Mencionar alguém com `@` → dropdown busca por **nome completo**
      ("Rafael Leão Ereno", não só o login)
- [ ] **Colar texto copiado do Excel** na nota → o texto entra normalmente.
      Este é o teste que importa: uma versão anterior bloqueava a colagem de
      planilha inteira, porque o Excel manda um bitmap junto com o texto
- [ ] **Colar um print** (imagem) → bloqueado com aviso, a nota não fica com
      imagem fantasma
- [ ] **Arrastar um arquivo** para o editor → bloqueado, e a página não abre o
      arquivo por cima da nota
- [ ] Botão `@` no cabeçalho → abre o painel de menções (pendentes e lidas)
- [ ] Clicar "Ver nota" numa menção → vai para a nota **e a menção deixa de
      aparecer como pendente ao recarregar**. Antes ela nunca era marcada como
      lida — o banner voltava para sempre
- [ ] **Fechar turno** → grava e o PDF do fechamento abre
- [ ] Fechar o mesmo turno de novo como **User (1)** → recusado (só Admin+)
- [ ] Gerar PDF do relatório ao vivo

### Coluna Ações + Alarmes em Tratativas/Resolvidos (novo, 2026-08-28)

Precisa de um turno com atividade real: pelo menos 1 problema com ACK simples,
1 com mensagem/comentário, 1 com mudança de severidade, 1 fechado manualmente
("Fechar problema" no update do Zabbix) e, se der, 1 notificação disparada por
uma Ação configurada — pra ver os 5 tipos de chip de uma vez.

- [ ] **Alertas Herdados** e **Alertas Sem ACK** ganharam a coluna **Ações**,
      com um chip por evento (ou "—" se não teve nenhuma)
- [ ] Passar o mouse num chip mostra quem fez, quando, e mensagem/severidade
      no tooltip
- [ ] Problema com ACK + mensagem + fechar **no mesmo clique** (update
      combinado no Zabbix) aparece como **3 chips separados** na mesma célula
- [ ] Nova tabela **Alarmes em Tratativas**: só alarmes abertos no turno que
      JÁ têm alguma ação — o mesmo alarme não pode aparecer também em
      "Sem ACK"
- [ ] Nova tabela **Alarmes Resolvidos**: só alarmes resolvidos DENTRO da
      janela do turno, com MTTR preenchido e "Resolvido por" mostrando o nome
      de quem fechou manualmente ou "Automático" quando ninguém fechou
- [ ] Os 2 KPIs novos (**Em Tratativas**, **Resolvidos**) no topo da tela
      levam para as tabelas certas ao clicar
- [ ] PDF do relatório ao vivo mostra as 2 tabelas novas e a coluna Ações
- [ ] **Fechar turno** de novo → o PDF do fechamento também mostra as 2
      tabelas novas e a coluna Ações (não só o relatório ao vivo)
- [ ] Abrir um fechamento **antigo** (de antes desta atualização, se houver
      algum) → não quebra; as 2 tabelas novas aparecem vazias

### Correções de bug da 5.2.1 (Repasse quebrado + lupa errada)

A 5.2.0 subiu com um bug que quebrava a tela inteira — este item confirma que
o conserto pegou.

- [ ] Abrir o Repasse → **MTTA por Hora** e **Distribuição por Severidade**
      renderizam normalmente (não ficam em branco)
- [ ] **Volume de Alertas — Últimos 30 Dias** (heatmap) aparece preenchido,
      não vazio
- [ ] Rolar até o fim da página → **nenhum texto solto/código aparecendo**
      abaixo do rodapé
- [ ] Ver código-fonte da página (Ctrl+U) → só um `</script>` de fechamento
      por bloco de script, nenhum meio de comentário cortando a tag
- [ ] Clicar na **lupa** ("Ver no Zabbix") em qualquer linha de Herdados, Sem
      ACK, Em Tratativas ou Resolvidos → abre **Detalhes do evento**
      (`tr_events.php`) do alarme clicado, não a lista geral de Alarmes
- [ ] Os links de **Host** e de **Problema** (colunas separadas, não a lupa)
      continuam abrindo a lista geral filtrada por nome — isso não mudou, é
      esperado

### Lista de repasses abertos/fechados (novo, 2026-08-31)

Tela `plantonistas.report.list`. Nada aqui foi exercitado contra banco real —
as duas consultas dependem de `ZbxDb` e não entram na suíte de testes puros.

- [ ] Menu **Plantão → Repasses (abertos/fechados)** abre; o botão
      **Repasses** no cabeçalho do Repasse leva ao mesmo lugar
- [ ] Turno com nota no Diário de Bordo e **sem** fechamento aparece como
      **Aberto**, com a barra âmbar na primeira coluna
- [ ] Fechar esse turno e recarregar a lista → a linha vira **Fechado**, e
      some da contagem de abertos
- [ ] **Fechar o mesmo turno duas vezes** (exige Admin+) → duas linhas, a de
      cima como `fechamento 2 de 2` e a de baixo `fechamento 1 de 2`. Esta é a
      razão de a tela existir: antes só o último era alcançável
- [ ] Turno **cadastrado** (id numérico) e turno **legado** (24h/manhã/tarde/
      noite) no mesmo período → nenhum aparece duas vezes, uma como aberto e
      outra como fechado. É onde a divergência de `shift_name` entre as duas
      tabelas apareceria
- [ ] **Renomear um turno** que já tem notas → a lista mostra UMA linha, com a
      contagem de notas somada (não duas linhas com a contagem partida)
- [ ] Filtro de datas e atalhos **7d / 30d / 90d** batem com o período pedido;
      repetir o teste **depois das 21h** (fuso: `toISOString` deslizaria um dia)
- [ ] Data inicial **maior** que a final → a tela corrige a ordem em vez de
      voltar vazia
- [ ] Filtro **Só fechados** / **Só abertos** / **Todos**
- [ ] Clicar em **Abrir documento** → documento congelado, com a barra
      **Lista de repasses** + **Baixar PDF**
- [ ] **Baixar PDF** abre a impressão do navegador, e na pré-visualização a
      barra de botões **não** aparece no papel
- [ ] Clicar em **Ao vivo** / **Abrir repasse** → Repasse na data e turno certos
- [ ] Com **usuário não Super Admin**: um fechamento feito por alguém de outro
      grupo **não** aparece na lista (mesma regra que já esconde o PDF)
- [ ] Nos **dois temas** (claro e escuro): pills, barra âmbar e botões legíveis

---

## 7. Gerenciar Turnos

- [ ] Criar turno, editar, remover
- [ ] **Aplicar turno a vários analistas de uma vez** (barra de seleção) →
      todos salvam
- [ ] Criar um turno e **aplicá-lo em massa na mesma visita**, sem recarregar
      → o turno novo aparece no seletor da barra
- [ ] Equipe **sem turno** cadastrado → a barra de seleção fica escondida
- [ ] Criar o primeiro turno de uma equipe → a barra **aparece** sem precisar
      de F5
- [ ] Voltar ao Repasse → coluna **Turno** na tabela de Presença preenchida

---

## 8. Perfis de acesso

Faça login com um usuário de cada tipo.

- [ ] **User (1)**: menu Plantão mostra só **Visão Geral** e **Repasse**
- [ ] User (1) colando a URL `zabbix.php?action=plantonistas.list` → **Acesso
      negado** (esconder o menu não basta; a action tem que recusar)
- [ ] **Admin (2)**: vê as 7 telas, e em Telefones enxerga os **analistas
      (User)** do grupo dele. Este era o bug: com o filtro por papel, o Admin
      só via outros Admins — justamente escondendo quem se liga às 3h
- [ ] **Super Admin (3)**: vê tudo

---

## 9. Os três crons

Todos em **dry-run** primeiro. Rode **como root** (os arquivos em
`/etc/cron.d/` são `chmod 600`), carregando as env sem redigitar a senha:

```bash
while IFS='=' read -r k v; do export "$k=$v"; done \
  < <(grep -E '^(DB_|ZBX_|ONCALL_|PLANTONISTAS_)' /etc/cron.d/<arquivo>)
```

> Não use `set -a; source <(grep ...)`. O cron lê o arquivo **literalmente**
> depois do `=`; o bash não. Uma senha com `$`, espaço ou crase seria expandida
> — no melhor caso chega truncada e o sintoma vira "falha de conexão", mandando
> investigar o banco em vez do comando; no pior, executa. O laço acima atribui
> sem interpretar nada.
>
> O `ONCALL_` no filtro não é enfeite: o `cron_sync_oncall.php` lê
> `ONCALL_GROUP_PREFIX`, e sem ele o dry-run procuraria o prefixo default e
> reportaria "grupo inexistente" para um cadastro correto.

### Presença

- [ ] `php scripts/cron_presence_tracker.php` roda sem erro
- [ ] tabela de Presença no Repasse lista os analistas
- [ ] nome **não** sai duplicado ("Rafael Rafael Leao Ereno")

### Escalonamento (novo)

- [ ] criar o grupo de destino na UI: `Plantonista de Hoje - <equipe>`
- [ ] `DRY_RUN=1 php scripts/cron_sync_oncall.php` → mostra quem entraria
- [ ] sem DRY_RUN → o grupo fica com **uma** pessoa, a escalada do turno
- [ ] apontar uma Ação do Zabbix para esse grupo e disparar um alerta de teste

### Menções (novo, opcional)

Precisa de token de API de Super Admin na env `PLANTONISTAS_ALERT_TOKEN`.
**Sem token não notifica e nada quebra** — o banner continua.

- [ ] o módulo foi aberto uma vez **antes** de agendar (a coluna `notified_at`
      nasce ali; agendado antes, o cron falha a cada minuto)
- [ ] `DRY_RUN=1 php scripts/cron_notify_mentions.php` → lista quem receberia
- [ ] mencionar alguém e rodar sem DRY_RUN → chega o aviso pelo media type
- [ ] mencionar a **mesma pessoa 3 vezes** → chega **um** aviso, não três
- [ ] mencionar alguém que está **logado** → não recebe e-mail (o banner basta)

---

## 10. PostgreSQL (se houver instância)

Nada disso jamais rodou contra um PG de verdade. Se não houver instância no
lab, **registre como não homologado** e siga com MySQL — é o estado honesto,
e o README já diz isso.

- [ ] instalação nova em PG cria as 9 tabelas — **não** pelo `scripts/install.sh`,
      que só tem caminho MySQL, e sim direto:

      ```bash
      psql -h <host> -U zabbix -d zabbix -f sql/schema.pgsql.sql
      ```

      (ou deixe o próprio `Module::init()` criar, abrindo uma tela com o
      módulo habilitado — é o caminho que a instalação normal usa)
- [ ] as 7 telas abrem
- [ ] salvar, remover e importar funcionam
- [ ] os dois crons rodam com `DB_TYPE=pgsql`
- [ ] "Fechar turno" funciona (usa `pg_try_advisory_lock`, que é caminho
      diferente do MySQL)
- [ ] `sql/queries.pgsql.sql` roda no psql

---

## Se algo falhar

O que ajuda a consertar rápido:

1. **Tela em branco com o menu OK** → falta `"view"` no manifest para aquela
   action. É o bug mais traiçoeiro do Zabbix: não gera erro nem log.
2. **"Acesso negado" indevido** → `checkPermissions()` com consulta quebrada.
3. **Grupo "vazio" em Gerenciar Turnos** → procure `[plantonistas]` no log do
   PHP-FPM **antes** de concluir que é falta de dado. A tela distingue "equipe
   sem analistas" de "consulta falhou", mas o motivo só está no log.
4. **Erro 500 sem rastro** → `catch_workers_output = yes` no pool
   (`/etc/php-fpm.d/zabbix.conf`) e acompanhe o log do FPM.

Traga o **arquivo, a tela e a mensagem exata**.
