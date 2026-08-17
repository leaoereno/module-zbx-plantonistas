# CLAUDE.md — module-zbx-plantonistas

Contexto, memória e instruções de trabalho deste projeto. Portável: serve como
knowledge de projeto no Claude.ai e como contexto de agente ao trabalhar neste
repositório. Atualizado em 2026-08-17.

---

## 1. Contexto

### O que é

Módulo frontend único para Zabbix 7.0 LTS que unifica dois módulos antigos:

- `module-zbx-escala-plantao` v3.0.1 (id `plantao`, ns `Modules\Plantao`) — escala mensal, telefones, histórico
- `module-zbx-repasse-plantao` v2.5.0 (id `turnos-noc-report`, ns `Modules\TurnosNocReport`) — relatório de repasse NOC, turnos por equipe

Resultado: **Plantonistas v4.0.0** — id `plantonistas`, namespace `Modules\Plantonistas`,
repo `leaoereno/module-zbx-plantonistas`. Rebranding completo (decisão do Rafael):
18 actions `plantonistas.*`, 8 tabelas `module_plantonistas_*`. De-para completo
de actions e tabelas está no README.md.

### Infraestrutura

| Item | Valor |
|---|---|
| Produção | `monitoracao.claroempresas.com.br` — frontends `lnxdczbxfront01` e `lnxdczbxfront02` atrás de F5 BIG-IP |
| Banco produção | MariaDB em `172.18.190.21`, database `zabbix`, user `zabbix` (sem privilégio PROCESS — mysqldump precisa de `--no-tablespaces`) |
| SO produção | RHEL/CentOS: usuário web `apache`, serviço `php-fpm.service` (sem sufixo de versão) |
| Homolog | `lnxdczbxhmg01` (Docker + Nginx) |
| Lab | `lab-zbx` — 192.168.0.151 (AlmaLinux, Apache, PHP 8.4, MariaDB) — **testar aqui primeiro** |
| Homelab | 192.168.0.150 (Docker; SMTP relay boky/postfix na porta 25) |
| Módulos no servidor | `/usr/share/zabbix/modules/<nome>/` |

Restrição crítica do F5: **bloqueia arquivos `.js` estáticos** — todo JS das views
é inline. Exceção herdada: `assets/js/chart.min.js` (gráficos do Repasse) é
estático; se gráfico não renderizar atrás do F5, é isso.

### Arquitetura do módulo

Menu **Plantão** (após Reports), 6 itens: Visão Geral, Escala, Histórico,
Telefones, Repasse Plantão, Gerenciar Turnos (este só role type >= 2).

Duas famílias de código coexistem de propósito (não unificar sem demanda):

- **Família escala** (`Plantao*.php`, `Phones*.php`): camada de DB nativa do
  Zabbix (`\DBselect`/`\DBfetch`/`\DBexecute`), views com `ob_start()` +
  `CHtmlPage`, CSS inline tema escuro, prefixos CSS `plt-`, `ov-`, `phn-`.
- **Família repasse** (`Turnos*.php`): mysqli direto via `getDb()` no trait
  `TurnosReportBase` (lê `$GLOBALS['DB']`), assets em `assets/` (css `rp-*`,
  FontAwesome local, Chart.js), tema claro com detecção de dark theme via JS.

Classes PHP e nomes de arquivos de asset mantiveram os nomes antigos
(`PlantaoList`, `TurnosShiftsView`, `turnos.report.css`) — só namespace,
actions, tabelas e paths mudaram. Manifest mapeia action→classe livremente.

`Module.php::init()` roda a cada request: migração idempotente de schema
(RENAME das tabelas antigas → novas, cria faltantes, ajusta colunas/índices)
e monta o menu. **Não existem hooks onInstall/onUninstall no Zabbix** — tudo
vive no init(), e o módulo **nunca dropa tabela com dados** (única exceção:
tabela nova VAZIA no caminho do RENAME de uma antiga com dados).

Actions AJAX (`*.save`, `*.delete`, `notes.*`, `usershift.save` do repasse)
usam `layout.javascript` + `view: null` e fazem `echo json_encode(...); die()`.
Actions de redirect da escala (`plantonistas.save/delete/import/export`,
`phones.save/export`) não têm view no manifest — respondem redirect/CSV.
`plantonistas.report.notes.get` é action morta (sem caller) mantida por segurança.

Cron: `scripts/cron_presence_tracker.php` popula `module_plantonistas_user_sessions`
a cada 5 min. Token via env `ZABBIX_API_TOKEN` (nunca versionar). Crontab
aponta pro caminho do módulo — mudou na unificação.

### Modelo de permissões por perfil

Checado via `getUserType()`/`CWebUser::getType()` (família escala) ou
`role.type` lido manualmente por `getUserRoleType()` (família repasse, que
usa mysqli direto e não tem acesso ao `CWebUser` nativo do mesmo jeito).
Constantes Zabbix: `USER_TYPE_ZABBIX_USER=1`, `USER_TYPE_ZABBIX_ADMIN=2`,
`USER_TYPE_SUPER_ADMIN=3`. Menu **Plantão** inteiro só aparece para type ≥ 1
(Guest não vê nada); item **Gerenciar Turnos** só para type ≥ 2.

| Tela | User (1) | Admin (2) | Super Admin (3) |
|---|---|---|---|
| Visão Geral / Escala / Histórico | Vê e edita só os próprios grupos | idem User | Todos os grupos |
| Telefones | Vê só quem tem o **mesmo grupo E o mesmo role** | idem User (por role) | Todos os usuários do sistema |
| Repasse Plantão (relatório) | Eventos seguem `rights` do Zabbix; MTTA só o próprio; Notas/Presença só do(s) próprio(s) grupo(s) | MTTA de todos; Notas/Presença do(s) próprio(s) grupo(s) | Sem filtro nenhum |
| Diário de Bordo (escrever) | Pode escrever nota | idem | idem |
| Gerenciar Turnos | **Sem acesso** (menu nem aparece; controller recusa) | Só as próprias equipes | Todas as equipes com ≥1 membro |

Pontos fora do padrão "grupo = visibilidade":
- **Telefones** exige grupo **e** role idênticos — um Admin e um User no
  mesmo grupo não se veem na lista de telefones um do outro. Não confirmado
  se é intencional.
- **Diário de Bordo / Presença** (família repasse) segmentam por
  `users_groups` compartilhado — segmentação própria do módulo, independente
  da tabela `rights` do Zabbix.
- **Eventos/alertas** do Repasse (KPIs, Top Hosts, MTTA) seguem `rights` do
  Zabbix quando configurada; sem `rights` por host, todo mundo vê tudo.

---

## 2. Memória (estado e decisões)

### Decisões tomadas e porquês

- **Rebranding total** (actions + tabelas) foi escolha explícita do Rafael,
  ciente de que quebra URLs salvas e exige migração — a alternativa
  conservadora (manter nomes) foi oferecida e recusada.
- **Migração por RENAME TABLE** (atômico, só metadados): dados de produção
  preservados sem cópia. Automática no init(), com `sql/migrate-from-old-modules.sql`
  (manual, opcional) e `sql/rollback-to-old-modules.sql` (reverso).
- `scripts/]` do repo antigo era lixo acidental (cópia truncada do cron) — excluído.
- `install.sh` ganhou guard: pergunta se o banco é novo antes de rodar schema.sql
  (rodar schema em banco com dados antigos criaria tabelas novas vazias).

### Causa raiz do bug "grupos não listam usuários" (Gerenciar Turnos)

O schema v2.5 do repasse **nunca rodou em produção**: `custom_shifts` e
`custom_user_shift` não existem lá. `listShiftsByGroup()` e `listUsersByGroup()`
(LEFT JOIN em `custom_user_shift`) falhavam e o catch devolvia `[]` em silêncio —
a tela mostrava "Nenhum usuário Zabbix neste grupo" para tudo.
**Confirmado em produção em 2026-08-14** (query no front02 contra 172.18.190.21):
existem 6 tabelas (`module_plantao_phones/schedule/history`, `custom_shift_notes`,
`custom_shift_reports`, `custom_user_sessions`); faltam as 2 do v2.5.
O v4 resolve: init() cria as duas que faltam e renomeia as 6 existentes.
Falhas de consulta agora saem no log com prefixo `[plantonistas]`, e a view de
turnos distingue "equipe sem analistas" de "consulta falhou".

### Correções de UX da tela Gerenciar Turnos (feitas no repasse, incluídas no v4)

Botões travam durante request AJAX (anti duplo clique); salvar (azul) e remover
(vermelho `--sev-disaster`) visualmente distintos; linha editada e não salva
marcada em amarelo (`rp-row-dirty`); salvar/criar/remover turno atualiza DOM
inline (sem `location.reload()`); equipes em abas (`rp-tabs`); turnos legados
(24h/Manhã/Tarde/Noite) exibidos como chips informativos.

### Turnos dinâmicos na tela Escala / Escalar Técnico (2026-08-14)

`module_plantonistas_schedule` ganhou a coluna `shift_id` (default 0 =
grupo sem turnos, comportamento idêntico ao de antes) e a unique key virou
`(usrgrpid, schedule_date, shift_id)`: 1 titular por grupo/dia sem turno,
1 titular por grupo/dia/turno em grupos com turnos cadastrados em
"Gerenciar Turnos". Migração idempotente em `Module::migrateColumns()`
(ADD COLUMN + troca de unique key, ADD antes do DROP pra nunca ficar sem
constraint), replicada em `sql/schema.sql` pra instalação nova.

- **Escala**: se o grupo tem turnos ativos, "Escalar Técnico" renderiza 1
  seletor por turno (rótulo = nome do turno) no lugar do par Técnico/Reserva
  único. Turno deixado em branco no formulário não altera o que já está
  salvo (dá pra editar 1 turno por vez sem reescalar os outros). Decisão do
  Rafael: **sem reserva por turno** — grupos com turnos só têm titular.
- Calendário (Escala) e cards (Visão Geral) empilham 1 entrada por turno
  preenchido, com o nome do turno como rótulo; Visão Geral distingue "Com
  cobertura" / "Cobertura parcial" (só parte dos turnos preenchida) / "Sem
  cobertura".
- `module_plantonistas_history` ganhou `shift_id`/`shift_name` (snapshot do
  nome no momento da alteração — sobrevive a rename/remoção do turno depois);
  tela Histórico ganhou coluna Turno. `PlantaoDelete` também loga o turno.
- **CSV import/export propositalmente NÃO mexidos** (decisão do Rafael) —
  continuam gravando/lendo só em modo legado (shift_id=0), mesmo em grupos
  com turnos. Consequência aceita: importar CSV num grupo com turnos cria
  uma entrada "sem turno" ao lado das entradas por turno (aparece no
  calendário sem rótulo, não é perdida); exportar um grupo com turnos só
  reflete a última entrada do dia lida do banco, não soma os turnos.

### Bug dos campos de turno "grandes demais" na Escala (2026-08-14)

`.plt-select-row` (largura ~300px) foi desenhada pra dentro de `.plt-form-row`,
que é flex **row** — ali `flex:0 1 300px` vira largura. O bloco novo por turno
(`.plt-shift-field`) é flex **column**; reaproveitar `.plt-select-row` dentro
dele faz o MESMO `300px` virar **altura** (flex-basis segue o eixo principal
do pai), e cada campo de turno inchava pra ~300px de altura. Fix: regra
escopada `.plt-shift-field .plt-select-row { flex:0 0 auto; width:300px; }`
restaura largura fixa sem depender do eixo do flex pai.

Investigado também o relato de que campos de técnico apareceriam em grupo
**sem** turno cadastrado: `PlantaoList::doAction()` já filtra `$shifts` por
`usrgrpid` e a view faz `if (!$has_shifts)` (par Técnico/Reserva único) /
`else` (1 campo por turno) — mutuamente exclusivos, sem caminho no código pra
os dois aparecerem juntos ou pro campo por turno aparecer sem turno
cadastrado. Não reproduzido por leitura de código; efeito provavelmente do
bug de altura acima (campo de ~300px lido como "não deveria estar aí").
Reabrir com print se persistir depois do fix de tamanho.

### Estado do deploy em produção (2026-08-14)

Feito: repo GitHub criado; clone no **front02**; tabelas de produção verificadas.
Não confirmado/pendente: backup dump concluído; clone + chown + restart php-fpm
no **front01**; chown/restart no front02; Scan directory; desabilitar os 2
módulos antigos; habilitar Plantonistas; atualizar crontab do presence tracker;
conferir roles (Modules → Plantonistas); validar as 6 telas; remover pastas
antigas após estabilizar. Runbook completo no README.md.

Atualização 2026-08-17: front01 rodando o código novo, tela de Repasse
validada (Presença voltou a listar, 16 analistas) e cron do presence tracker
recriado ali — ver "Cron de presença estava desabilitado". Continua pendente
conferir o `/etc/cron.d/` do **front02** (se o cron antigo estiver ativo lá,
desabilitar: o tracker deve rodar em um nó só).

Nota: o fix de UX também ficou não-commitado na working tree do repo antigo
`module-zbx-repasse-plantao` local — irrelevante após a unificação, mas o
histórico está no zip `module-zbx-repasse-plantao-commit-1dadacd.zip` se precisar.

### Filtro de usuário ativo/bloqueado (2026-08-14)

Critério adotado — **usuário aparece se tiver pelo menos 1 grupo com
`usrgrp.users_status=0`** (mesma regra que o Zabbix usa pra marcar alguém
como "Disabled" em Administração > Usuários; grupos mistos ativo+desabilitado
ainda contam como ativo). Implementado como
`AND EXISTS (SELECT 1 FROM users_groups ... JOIN usrgrp ... WHERE
users_status=0)` em 4 pontos: `PlantaoList` (seletor de técnico da Escala),
`PhonesList`/`PhonesExport` (tela e CSV de Telefones), `TurnosReportBase::
listUsersByGroup()` (analistas em Gerenciar Turnos).

Decisão consciente: **não** filtra pelo bloqueio temporário de login
(`users.attempt_failed`/`attempt_clock`) — é estado transitório de segurança
(conta trava minutos após senhas erradas), sem relação com elegibilidade pra
escala. `PlantaoImport` (CSV) não foi alterado — mesma decisão de não mexer
no import/export já registrada acima.

### Datas e horas em padrão brasileiro (auditoria 2026-08-14)

Auditoria em todo o módulo (`date()` PHP + `Date`/`toLocaleString` JS). Maior
parte já estava em `d/m/Y` (herdado da família escala). Gaps corrigidos,
todos na família repasse:
- `TurnosReportBase::queryNotes()`/`queryPresence()`: `created_at`/
  `first_seen`/`last_seen` vinham crus do banco (`Y-m-d H:i:s`) — agora
  `DATE_FORMAT(..., '%d/%m/%Y %H:%i')`. Ordenação usa coluna auxiliar
  (`created_sort`/`first_seen_sort`) com o valor original — ordenar pela
  string já formatada dá resultado cronologicamente errado.
- `plantonistas.report.view.php`: timestamp otimista via JS (nota inserida
  antes do reload) reconstruído em `dd/mm/aaaa HH:MM`.

Datas usadas só como chave interna (nunca exibidas — heatmap do calendário,
`$today_str` da Escala) continuam em `Y-m-d` de propósito; não é gap pt-BR.

### Diário de Bordo — sem expiração automática (2026-08-14)

Confirmado por grep no repo inteiro: não existe `DELETE`/TTL/cron de limpeza
para `module_plantonistas_shift_notes`. As únicas limpezas automáticas do
módulo são `module_plantonistas_user_sessions` (cron, 7 dias) e os vínculos
`module_plantonistas_user_shift` (quando o turno é removido). Diário de Bordo
é histórico permanente — cresce sem limite. Ver Backlog.

### Cores dos botões de navegação do Repasse (2026-08-14)

"Gerenciar Turnos" e "Voltar ao Relatório" usam a mesma classe `.rp-nh-btn` —
1 fix resolveu os 2 lugares reportados + o botão "Gerar PDF" (mesma classe).
Fundo trocado de branco 15% translúcido (quase invisível sobre o header
escuro) para `var(--rp-blue)` sólido (mesma cor de `.rp-btn-primary`), com
borda sutil e sombra. Já respeita dark theme (`--rp-blue` já tem override).
Botão de reload (ícone só) não foi mexido — tinha estilo inline próprio.

**Regressão do mesmo dia**: o fundo sólido ficou certo, mas o texto continuou
azul (só ficava legível no `:hover`). Causa: `.rp-nh-btn` é aplicada em `<a>`,
e o tema nativo do Zabbix define cor de link com especificidade maior que uma
classe simples — o `color:#fff` da classe perdia a disputa de cascata em
repouso (no `:hover` não havia regra nativa concorrente, por isso só ali
ficava legível). Mesmo problema que `.plt-nav a.btn.btn-alt` já tinha
resolvido com `!important` (`views/plantonistas.list.php`). Fix: `!important`
em `background`/`color` de `.rp-nh-btn` e `.rp-nh-btn:hover`.

### Editor rico + menções no Diário de Bordo (2026-08-14)

Decisões tomadas com o Rafael (via perguntas de escopo) antes de implementar:
editor leve sem dependência nova (contenteditable + JS inline, sem vendorizar
biblioteca); notificação de menção como banner simples com link pra nota (sem
central de notificações); item escolhido vira link/chip limpo na nota (sem
manter texto de gatilho literal); os 3 tipos de menção entregues juntos, não
em etapas.

**Gatilhos trocados no mesmo dia**: sintaxe original era `[hostgroup]`/`[host]`/
`[user]` — o Rafael achou ruim (colchetes atrapalham digitação natural) e
pediu a troca por `@` (usuário), `_h` (host) e `_hg` (grupo de hosts), mais
próximo do que já se usa em Slack/Teams. `_h` é prefixo de `_hg` — resolvido
por ordem de alternância no regex (`_hg` testada antes de `_h`; ver comentário
em `plantonistas.report.view.php`), sem debounce. Ambíguo só no caso
`_hg<texto colado sem espaço>` (ex.: `_hgateway`): resolve como hostgroup com
busca "ateway", não host com busca "gateway" — edge case aceito, mesmo
espírito do edge case de sanitização abaixo. Só o regex de detecção no JS
mudou; o backend continua recebendo `type=hostgroup|host|user` sem alteração
nenhuma.

**Schema novo**: `module_plantonistas_shift_notes.notes_format` (`text` |
`html`, default `text` — distingue nota antiga de nota nova na exibição) e
tabela `module_plantonistas_mentions` (note_id, mentioned_userid, created_by,
is_read, created_at, read_at). Ambos migrados idempotente em
`Module::migrateColumns()`/`tableDdl()`, replicados em `sql/schema.sql`.

**Fluxo**: digitar `[hostgroup]`/`[host]`/`[user]` no editor abre um dropdown
(nova action `plantonistas.report.mentions.search`, GET com `type`+`q`) que
busca com a MESMA regra de permissão já usada no resto do módulo — não
inventei um modelo novo: hostgroup/host respeitam `rights` do Zabbix (igual
a `host_filter` de `resolveUserContext()`); user respeita grupo compartilhado
(igual a Notas/Presença) e exclui usuário com todos os grupos desabilitados
(igual ao filtro de usuário ativo). Selecionar um item substitui o texto
`[tipo]busca` por um `<a>` (hostgroup/host, linkando pra Monitoramento >
Problemas filtrado) ou `<span data-mention-userid>` (user, sem link).

**Sanitização**: `TurnosReportBase::sanitizeNoteHtml()`/`sanitizeNode()` —
allowlist de tags (`b,strong,i,em,u,ul,ol,li,br,p,div,a,span`) e atributos
por tag via `DOMDocument`, nunca regex. O servidor NUNCA confia no HTML do
cliente, mesmo vindo do próprio editor — dá pra postar direto no endpoint
sem passar pela UI. Testado com bypass aninhado (`<iframe><span onclick>`),
`javascript:`/`data:` em `href`, `<script>`: todos neutralizados (suite em
`test_sanitizer.php`, não versionada — rodar de novo se mexer nessa função).
Ponto de atenção arquitetural: a sanitização roda em **profundidade primeiro**
(sanitiza o filho antes de decidir promover/descartar a tag pai) — inverter
essa ordem reabre o bypass aninhado.

Menção extraída do HTML sanitizado (`<span data-mention-userid>`) grava 1
linha em `module_plantonistas_mentions` por userid válido (ativo + visível
ao autor — mesma regra de grupo compartilhado; menção a si mesmo é ignorada).
Menção inválida é descartada em silêncio, não derruba o save da nota.

`plantonistas.report.view.php` mostra o banner de menções pendentes (contador
no header + lista expansível), com "Ver nota" (marca como lida via
`sendBeacon`, não bloqueia a navegação) e "X" pra dispensar sem abrir.

**Notas antigas continuam funcionando** — `notes_format='text'` (default)
renderiza pelo caminho antigo (`nl2br(htmlspecialchars(...))`); só nota nova
(sempre `'html'`) usa o HTML sanitizado direto. `TurnosNotesGet.php`
(action morta, sem caller) não foi tocado.

Edge case conhecido e aceito: colar/digitar um `<` literal que NÃO veio de um
`contenteditable` real (ex.: POST manual direto no endpoint) pode truncar o
texto depois do `<` — o parser HTML do DOMDocument é mais permissivo que um
navegador nisso. Não afeta o uso normal (o navegador sempre serializa `<`
digitado como `&lt;` no `innerHTML`), só input adversarial/manual.

### Coluna Turno na Presença de Analistas (2026-08-17)

Tabela "Presença de Analistas" (Repasse) ganhou a coluna **Turno**, entre
Username e Primeira Atividade: nome + horário do turno vinculado ao usuário
em Gerenciar Turnos, no mesmo formato do seletor de turno do relatório
(`Diurno (07:00–19:00)`). Sem vínculo → "Sem turno" em itálico cinza
(classe `.rp-muted`, criada agora, com `var(--rp-text-muted)`).
`TurnosReportPdf` não renderiza essa tabela — não precisou de ajuste.

**Regressão do mesmo dia, e a lição que ficou**: a primeira versão buscou o
turno por `LEFT JOIN` dentro de `queryPresence()` e a tabela de presença
inteira voltou vazia, exibindo "Nenhum dado de presença. Execute o cron" —
mensagem enganosa, porque o cron não era o problema. Causa: `getDb()` liga
`MYSQLI_REPORT_STRICT`, então `prepare()` de query que referencia tabela
ausente **lança** `mysqli_sql_exception`, e as tabelas de turno podem não
existir no ambiente (schema v2.5 nunca rodou em produção — só o `init()` do
v4 as cria). Agravante: o `prepare()` estava **fora** do try, que só cobria
o `execute()` — a falha não era nem logada.

Regra que vale pro módulo inteiro: **informação acessória não entra por JOIN
na query do dado principal**. O turno virou query própria
(`attachUserShift()`, `IN` com userids já convertidos pra int) que anexa as
colunas às linhas de presença; se ela falhar, loga `[plantonistas]` e a
coluna mostra "Sem turno" — a presença continua na tela. `prepare()`/
`bind_param()` de `queryPresence()` passaram pra dentro do try com
`error_log`. Turno inativo (`active=0`) é exibido de propósito: o vínculo
existe, e esconder seria pior que mostrar um turno desativado.

Maioria dos analistas aparece como "Sem turno" porque o vínculo é um clique
por analista em Gerenciar Turnos (ver Backlog: salvar em massa).

### Cron de presença estava desabilitado desde a unificação (2026-08-17)

`/etc/cron.d/` do **front01** tinha só `zbx-repasse-plantao.disabled` — nome
com ponto é ignorado pelo cron.d, e ainda apontava pro script do módulo
antigo (que escreve em `custom_user_sessions`, tabela renomeada pelo v4).
Era a causa real da tabela vazia (a regressão do JOIN acima mascarou o
diagnóstico por um tempo).

Recriado como `/etc/cron.d/plantonistas-presence` derivando do arquivo antigo
por `sed` de `module-zbx-repasse-plantao` → `module-zbx-plantonistas`, o que
preserva credenciais sem redigitar e mantém o truque do `; sleep 150; php ...`
(roda a cada 2,5 min em vez de 5). As env `DB_*` no arquivo de cron são
**obrigatórias**: em CLI não existe `$GLOBALS['DB']`, e sem elas o script cai
no default `localhost`. `ZABBIX_URL` de produção é o endpoint do
`nessus.claroempresas.com.br:22443/services/zabbix/api/v1/api_jsonrpc.php`,
não a URL do frontend. Tracker deve rodar em **um** frontend só (grava no
banco compartilhado; nos dois duplica escrita e consumo da API).

Gap ainda aberto: `install.sh` escreve a linha do cron sem nenhuma env, então
o cron que ele gera nunca autentica (`CRITICAL: ZABBIX_API_TOKEN não
configurado`) nem acha o banco. Ver Backlog.

### Nome duplicado no rastreador de presença (2026-08-17)

Analistas apareciam como "Rafael Rafael Leao Ereno" / "Erica Erica Felix de
Oliveira": vários cadastros do Zabbix têm o nome completo no campo `surname`
e só o primeiro nome em `name`, e o cron concatenava os dois às cegas.
Novo `buildFullName()` em `cron_presence_tracker.php` devolve só o campo mais
completo quando um já contém o outro **nas bordas** — contenção no meio não
conta ("Ana" + "Mariana Costa" continua concatenando; exigir o espaço como
limite de palavra é o que evita esse falso positivo). Cobre também o caso
inverso (`name` já com o sobrenome) e comparação sem distinguir maiúscula.

O `UPDATE` da sessão existente passou a incluir `name` no SET (antes o nome
só era gravado no INSERT) — sem isso a sessão aberta hoje carregaria o nome
duplicado até a limpeza de 7 dias. Efeito colateral bom: renomeação no
cadastro do Zabbix agora se reflete na próxima execução do cron.

Correção é só de gravação: **nada reescreve as linhas já no banco**, elas se
corrigem sozinhas na próxima passada do cron (UPDATE) ou expiram em 7 dias.

### Dropdown de menções: itens em branco, corte e polimento (2026-08-17)

Três problemas na lista que abre ao digitar `@` no Diário de Bordo:

**1. Itens em branco no topo.** O label vinha de
`CONCAT(name,' ',surname)` cru — conta que só tem `username` (serviço,
integração) produzia label `' '`, e o `ORDER BY label` jogava justamente
esses para o começo da lista. Agora o SQL devolve `username`/`name`/`surname`
separados, o label é montado por `formatUserLabel()` (novo helper no trait,
mesma regra de bordas do `buildFullName()` do cron) com o **username como
reserva**, e o `ORDER BY` usa o nome efetivo. A duplicação da lógica entre
trait e cron é proposital: o cron roda em CLI sem carregar o módulo.
`formatUserLabel()` também passou a ser usado em `current_fullname`
(`TurnosReportView`, aparece no "Analista:" e no rodapé), no
`TurnosReportPdf` e no `TurnosNotesSave` — este último é o mais importante
porque `analyst_name` é **persistido**: ali o nome duplicado ficaria gravado
para sempre, sem cron que corrija depois.

**2. Lista cortada, "sem barra de rolagem".** O dropdown era
`position: absolute` dentro de `.rp-editor-wrap`, e `.rp-card` tem
`overflow: hidden` (necessário pro border-radius das tabelas) — tudo que
passava da borda do card era recortado, inclusive a scrollbar, dando a
impressão de que a lista tinha só os primeiros itens. Virou
`position: fixed` com coordenadas de viewport calculadas no JS, que **não**
sofre clipping de ancestral. Consequências que o fixed traz e estão
tratadas: `positionDropdown()` é chamado em `scroll` (com `capture: true`,
pra pegar scroll de container interno do Zabbix, não só o da janela) e em
`resize`, senão a lista ficaria parada enquanto o texto rola; inverte pra
cima (`.rp-flip-up`) quando não cabe embaixo; e prende nas bordas laterais.

**3. Polimento visual/UX.** Ícone por tipo (`fa-user`/`fa-server`/
`fa-layer-group`), cabeçalho sticky com o tipo e a contagem, username como
segunda linha do item (desambigua homônimo), animação de entrada de 0.14s
com `prefers-reduced-motion` respeitado, scrollbar fina, e navegação por
teclado: ↑/↓ circulares, Enter/Tab inserem, Esc fecha, item ativo espelhando
o hover (`.rp-mention-active`) com `scrollIntoView({block:'nearest'})`.
Enter sem item destacado continua fazendo quebra de linha no editor.

### Backlog conhecido

- Salvar vínculo analista→turno em massa (hoje é um clique por analista) —
  ganhou urgência: a coluna Turno da Presença mostra "Sem turno" pra quase
  todo mundo enquanto os vínculos não forem cadastrados.
- `install.sh` gera `/etc/cron.d/plantonistas-presence` sem as env
  `ZABBIX_API_TOKEN`/`ZABBIX_URL`/`DB_*` — o cron criado por ele não roda.
  Passar a perguntar e escrever no arquivo (com `chmod 600`, é credencial).
- Chart.js estático vs F5 (embutir inline se os gráficos falharem em produção).
- Limpeza opcional de `role_rule` órfãs dos módulos antigos (SQL no README).
- Unificação visual das duas famílias (escala dark × repasse claro) — só com demanda.
- CSV import/export da Escala não sabe de turnos (grava/lê sempre shift_id=0,
  modo legado) — decisão consciente do Rafael, ver seção "Turnos dinâmicos".
  Se precisar, adicionar coluna Turno nos dois sentidos depois.
- Diário de Bordo sem expiração — avaliar TTL/arquivamento se a tabela crescer muito.
- Conexão mysqli própria (`getDb()`) reconectando a cada request na família
  repasse (~10 arquivos) em vez de reusar `$GLOBALS['DB']` nativo do Zabbix.
- `getUserRoleType()`/`resolveUserContext()` duplicados entre `TurnosReportBase`
  e `TurnosNotesGet`/`TurnosNotesSave` (cada um com sua própria cópia).
- `PhonesSave` faz SELECT-then-UPDATE-or-INSERT em vez de
  `INSERT ... ON DUPLICATE KEY UPDATE` (pequena janela de corrida).
- Telefones: Admin/User só se veem dentro do mesmo `roleid` — confirmar se é
  intencional (ver "Modelo de permissões" em Contexto).
- Nome duplicado (`name` + `surname` concatenados às cegas) ainda existe nos
  `CONCAT` feitos em SQL: `queryShiftAnalysts()`, `queryMTTA()` e
  `listUsersByGroup()` no trait, e em toda a família escala (`PlantaoList`,
  `PlantaoExport`, `PhonesList`, `PhonesExport`, `PlantaoOverview`,
  `PlantaoHistory`). Corrigir exige trazer `name`/`surname` separados e
  montar o label em PHP com `formatUserLabel()` — feito só onde o defeito
  estava visível/persistido; o resto ficou pra quando incomodar.
- Editor rico do Diário de Bordo: sem suporte a colar imagem/anexo (só texto
  formatado + menções). Sem histórico de menções lidas (só as pendentes
  aparecem — decisão consciente, ver seção acima).

---

## 3. Instruções

### Regras de código (Zabbix 7.0 — sempre carregar a skill `zabbix-module-dev`)

- Toda action de página no manifest **precisa** de `"view"` explícita — sem ela
  a página renderiza em branco, sem erro e sem log (bug mais traiçoeiro).
- Funções globais de DB com `\` dentro de namespace: `\DBselect`, `\DBfetch`,
  `\DBexecute`, `\DBstart`/`\DBend` (nunca DBbegin/DBcommit), `\zbx_dbstr`
  (DBquote NÃO existe).
- Permissão: `$this->getUserType()` ou `CWebUser::getType()` — **nunca** SQL em
  `users.type` (coluna não existe; falha silenciosa vira "Acesso negado").
- AJAX: action sempre na query string (`zabbix.php?action=...`), nunca no body.
- JS inline nas views (F5). Emoji/FontAwesome local pra ícones internos; `zi-*` só no menu.
- MariaDB com `ONLY_FULL_GROUP_BY`: usar `MIN()`, não `ANY_VALUE()`.

### Convenções deste módulo

- Actions novas: prefixo `plantonistas.`; tabelas novas: `module_plantonistas_`;
  registrar no manifest e, se página, no menu do Module.php.
- Prefixo CSS por tela (`plt-`, `rp-`, `ov-`, `phn-`) — não misturar famílias.
- Texto de UI e mensagens em PT-BR; logs com prefixo `[plantonistas]`.
- Nunca engolir exceção de DB em silêncio: `error_log` + UI distinguindo
  "vazio de verdade" de "consulta falhou". Na família repasse o `prepare()`
  também tem que estar **dentro** do try — `getDb()` liga
  `MYSQLI_REPORT_STRICT` e ele lança exceção antes do `execute()`.
- Informação acessória (turno, rótulo, enfeite) não entra por JOIN na query
  do dado principal: se a tabela do enfeite não existir naquele ambiente, o
  dado principal desaparece inteiro. Query separada + fallback na UI.
- Migrações: sempre idempotentes dentro do `migrateSchema()`; nunca DROP de
  tabela com dados; RENAME/ALTER guardados por INFORMATION_SCHEMA.
- Diff mínimo: em refactors, não renomear classes/arquivos sem necessidade.
- Commits em PT-BR, prefixo `fix:`/`feat:`/`docs:`, corpo explicando o porquê.

### Deploy e debug

- Deploy sempre nos **dois** frontends: `git pull` + `chown -R apache:apache .`
  + `systemctl restart php-fpm` em cada um (LB serve código velho de nó esquecido).
- `git pull` como root deixa dono root → sempre chown depois.
- Erro 500 sem rastro: `catch_workers_output = yes` no pool
  (`/etc/php-fpm.d/zabbix.conf`) e tail no log do FPM.
- Tela em branco com menu ok = falta `"view"` no manifest. "Acesso negado"
  indevido = checkPermissions com query quebrada. Grupo "vazio" na tela de
  turnos = ver `[plantonistas]` no log antes de assumir que é dado.
- Testar no lab-zbx antes de produção; validar as 6 telas após qualquer deploy.
- Rollback da unificação: desabilitar Plantonistas ANTES de rodar
  `sql/rollback-to-old-modules.sql`, depois reabilitar os antigos (README).
