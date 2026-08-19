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
a cada 5 min, lendo tudo direto do banco (env `DB_*`, obrigatórias em CLI).
Não usa mais a API do Zabbix nem token — ver "Cron de presença sem API".
Crontab aponta pro caminho do módulo — mudou na unificação.

### Modelo de permissões por perfil

Checado via `getUserType()`/`CWebUser::getType()` (família escala) ou
`role.type` lido manualmente por `getUserRoleType()` (família repasse, que
usa mysqli direto e não tem acesso ao `CWebUser` nativo do mesmo jeito).
Constantes Zabbix: `USER_TYPE_ZABBIX_USER=1`, `USER_TYPE_ZABBIX_ADMIN=2`,
`USER_TYPE_SUPER_ADMIN=3`. Menu **Plantão** aparece a partir de type ≥ 1
(Guest não vê nada), mas **User (1) só enxerga dois itens: Visão Geral e
Repasse Plantão**. Escala, Histórico, Telefones e Gerenciar Turnos são
Admin (2)+ — commit `9959722`, ver abaixo.

| Tela | User (1) | Admin (2) | Super Admin (3) |
|---|---|---|---|
| Visão Geral | Vê só os próprios grupos | idem User | Todos os grupos |
| Escala / Histórico | **Sem acesso** (menu não aparece; `checkPermissions()` recusa) | Vê e edita só os próprios grupos | Todos os grupos |
| Telefones | **Sem acesso** (idem) | Vê só quem tem o **mesmo grupo E o mesmo role** | Todos os usuários habilitados do sistema |
| Repasse Plantão (relatório) | Eventos seguem `rights` do Zabbix; MTTA só o próprio; Notas/Presença só do(s) próprio(s) grupo(s) | MTTA de todos; Notas/Presença do(s) próprio(s) grupo(s) | Sem filtro nenhum |
| Diário de Bordo (escrever) | Pode escrever nota | idem | idem |
| Gerenciar Turnos | **Sem acesso** (idem) | Só as próprias equipes | Todas as equipes com ≥1 membro |

**Restrição de User (1) a Visão Geral + Repasse — commit `9959722`
(2026-08-17).** Antes disso o User via o menu Plantão inteiro e abria todas
as telas. Duas camadas foram necessárias, e a segunda é a que importa:
`Module.php:53-55` monta o submenu condicional ao tipo (Escala e Telefones
com `setAliases()` das actions filhas, pra o item continuar marcado durante
save/delete/export/import), **mas esconder o item do menu não basta — a
action continua acessível pela URL**. Por isso o `checkPermissions()` de
`PlantaoList/Save/Delete/History/Export/Import` e de
`PhonesList/Save/Export/Import` passou de `USER_TYPE_ZABBIX_USER` para
`USER_TYPE_ZABBIX_ADMIN`. Os AJAX de turnos (`shifts.save`, `shifts.delete`,
`usershift.save`) validavam só `!isGuest()` — qualquer usuário autenticado
gravava turno por POST direto — e agora exigem Admin também.

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
  preservados sem cópia. Automática no init(), com `sql/migrate-from-old-modules.<banco>.sql`
  (manual, opcional) e `sql/rollback-to-old-modules.<banco>.sql` (reverso).
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
constraint), replicada em `sql/schema.mysql.sql` pra instalação nova.

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

### Filtro de usuário ativo/bloqueado (2026-08-14, **corrigido em 2026-08-17**)

⚠️ A versão de 2026-08-14 registrava aqui que "usuário aparece se tiver pelo
menos 1 grupo com `users_status=0`" era "a mesma regra que o Zabbix usa".
**Isso estava errado** — a regra era o inverso da do Zabbix. Ver a seção
"Regra de usuário desabilitado estava invertida" abaixo. O critério vigente
está descrito lá; `PlantaoImport` (CSV) continua sem filtro, mesma decisão de
não mexer no import/export.

Decisão consciente que **continua valendo**: escala/telefones/turnos **não**
filtram pelo bloqueio de login (`users.attempt_failed`/`attempt_clock`) — é
estado transitório de segurança (conta trava minutos após senhas erradas),
sem relação com elegibilidade pra escala. A única tela que filtra bloqueio é
a busca de menção (`@`), por pedido explícito do Rafael.

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
`Module::migrateColumns()`/`tableDdl()`, replicados em `sql/schema.mysql.sql`.

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
no default `localhost`. Tracker deve rodar em **um** frontend só (grava no
banco compartilhado; nos dois duplica escrita).

Na época, `ZABBIX_URL`/`ZABBIX_API_TOKEN` também eram obrigatórias — a de
produção apontava pro endpoint `nessus.claroempresas.com.br:22443/services/
zabbix/api/v1/api_jsonrpc.php`, não pra URL do frontend. As duas deixaram de
existir na v5 (seção abaixo); podem sair do `/etc/cron.d/` e o token pode ser
revogado no Zabbix.

### Cron de presença sem API do Zabbix (2026-08-19, issue #4)

O script autenticava por `Authorization: Bearer <token>` com
`CURLOPT_SSL_VERIFYPEER`/`VERIFYHOST` em `false` — credencial viajando por
canal não verificado. E a API **nunca foi necessária**: a presença de verdade
sempre veio de leitura direta da tabela `sessions`; a API só buscava
`username`/`name`/`surname`/`usrgrps`, que estão em `users`/`usrgrp`/
`users_groups`, no mesmo banco em que o script já estava conectado.

Agora são três consultas locais, e a ordem foi **invertida de propósito**:
primeiro as sessões ativas, depois os dados só dos userids encontrados. Antes
puxava a base inteira de usuários da API para indexar em memória e descartar
quase tudo.

- **Usuário habilitado** usa a mesma cláusula do resto do módulo (nenhum grupo
  com `users_status=1` + `roleid` preenchido), não o `filter gui_access` que a
  chamada de API usava. Sessão de usuário desabilitado é ignorada, como antes.
- **`noc_context` virou query própria**, não JOIN — regra do módulo: se o dado
  acessório falhar, a presença continua sendo gravada (WARN no log, contexto
  nulo). O descarte de grupo de sistema passou a ser por flag
  (`users_status`/`gui_access`), que funciona em instalação traduzida; a lista
  de nomes em inglês ficou só como reserva para o caso do grupo `Guests`.
- Falha ao gravar **uma** linha não derruba mais a execução inteira (`continue`
  com WARN), mesma decisão já tomada para menção inválida no Diário de Bordo.

**O `CREATE TABLE IF NOT EXISTS` do cron foi removido.** Ele divergia do DDL
oficial (`id INT` contra `BIGINT UNSIGNED`, `name VARCHAR(255)` contra `128`,
sem a coluna `ip`, índices `idx_userid`/`idx_lastaccess` contra `idx_cus_*`).
Onde o cron rodou antes do primeiro request com o módulo habilitado,
`existingTables()` via a tabela como pronta e **nunca a corrigia** — e o
`ALTER ... ADD COLUMN noc_context ... AFTER ip` do `migrateColumns()` falhava,
porque `ip` não existia. Provisionamento agora vive só no `Module.php`; sem a
tabela, o cron encerra dizendo para habilitar o módulo uma vez.

`migrateColumns()` conserta o que o cron antigo criou: adiciona `ip` **antes**
do bloco de `noc_context`, promove `id INT` → `BIGINT UNSIGNED`, `userid` para
unsigned, e cria os índices `idx_cus_*` que faltam. Duas decisões conscientes:
os índices antigos do cron **não** são dropados (redundantes, mas dropar índice
em tabela grande trava escrita) e `name VARCHAR(255)` **não** é reduzido para
128 (encurtar coluna trunca dado em silêncio; 255 acomoda 128).

Para detectar `id INT` foi preciso trazer `COLUMN_TYPE` no SELECT de
`INFORMATION_SCHEMA.COLUMNS` — `CHARACTER_MAXIMUM_LENGTH` é NULL em coluna
numérica e não distingue INT de BIGINT.

**Três defeitos antigos corrigidos na mesma passada** (achados na revisão):

- **Fuso do PHP × fuso do banco.** `session_start`/`lastaccess` sempre foram
  gerados por `date()` em `America/Sao_Paulo`, mas o `DATE(session_start) =
  CURDATE()` e o `DATE_SUB(NOW(), INTERVAL 7 DAY)` eram avaliados no fuso do
  MariaDB, que roda em outro host. Com o banco em UTC, das 21h em diante o
  `CURDATE()` já é amanhã: a linha gravada às 21h05 nunca casava e o script
  inseria uma sessão nova **a cada ciclo**, duplicando presença justamente no
  turno da noite. Agora os dois valores são calculados em PHP e vão como
  parâmetro. É o mesmo bug de fuso do heatmap do Repasse, em outro lugar —
  vale procurar por `CURDATE()`/`NOW()` antes de assumir que não há mais.
- **Nome em branco para conta de serviço.** `buildFullName()` devolvia `''`
  quando `name` e `surname` estavam os dois vazios, e a view imprime o campo
  cru. Ganhou o `$username` como reserva, igual ao `formatUserLabel()` do
  trait (o comentário já dizia que espelhava; agora espelha de verdade).
- **Nome longo descartava a linha.** `users.name` e `users.surname` são
  VARCHAR(100) cada; o concatenado chega a 201, contra o VARCHAR(128) da
  tabela de presença. Com `sql_mode` STRICT o INSERT lançava "Data too long"
  e aquele analista simplesmente não era registrado. Agora trunca em 128, como
  o `noc_context` já fazia em 50.

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

### Regra de usuário desabilitado estava invertida (2026-08-17)

Pesquisa na doc do Zabbix 7.0 + código do frontend, motivada pelo pedido de
não listar usuário desativado na busca de menção. O que se descobriu:

**O Zabbix resolve o status com `MAX(usrgrp.users_status)`** — pertencer a
**qualquer** grupo desabilitado já desabilita a pessoa
(`CUser::getAccess()` faz `MAX(g.users_status)`; `addUserGroupFields()` barra
o login se qualquer grupo vier desabilitado; a coluna Status da lista de
usuários mostra "Disabled"). A doc não é explícita sobre grupo misto ("Status
… depending on the one set for the whole user group"), o código é.

O módulo fazia o **oposto**: `EXISTS (grupo com users_status=0)`, isto é,
"tem pelo menos 1 grupo ativo → aparece". Consequência: analista em grupo
misto (membro de "NOC" **e** de um grupo tipo "Desligados") continuava
aparecendo e sendo escalável no módulo enquanto o Zabbix já o mostrava como
Disabled. Corrigido nos 5 pontos por decisão do Rafael (a alternativa de
corrigir só a menção foi oferecida e recusada):
`TurnosReportBase::enabledUserClause()` novo, usado pela busca de menção e
por `listUsersByGroup()`, e a mesma cláusula escrita à mão em `PlantaoList`
(seletor de técnico), `PhonesList` e `PhonesExport`.

A cláusula também exige `roleid` preenchido — a lista do Zabbix mostra
"Disabled" para usuário sem role. Usuário **sem nenhum grupo** conta como
habilitado, igual ao Zabbix (que inicializa `users_status = 0` e só mostra um
ícone de aviso "User does not have user groups").

**Bloqueado por tentativas de login** (`notBlockedUserClause()`, só na busca
de menção): `attempt_failed >= config.login_attempts`, mesmo critério da
coluna "Login: Blocked" da lista de usuários — que não olha tempo nenhum.
Vale saber a assimetria: o bloqueio *efetivo* de login expira sozinho depois
de `config.login_block` (default 30s), mas `attempt_failed` só zera quando a
pessoa loga ou um Super Admin usa Unblock. Então alguém pode aparecer
"Blocked" no Zabbix (e sumir da busca de menção) já podendo logar. É o
comportamento que casa com o que se vê na tela do Zabbix, e foi a escolha
consciente. `config.login_block` é string com sufixo (`30s`, `5m`) e
precisaria de conversão em PHP — motivo extra pra não usar a janela de tempo.

### Busca de menção por nome completo (2026-08-17)

O `@` só achava pelo campo isolado (`username`/`name`/`surname`), então
buscar "Rafael Leao" não achava `name='Rafael'` + `surname='Leao Ereno'` —
nome que atravessa os dois campos nunca casa num LIKE campo por campo. E o
regex do gatilho parava no primeiro espaço, ou seja: era **impossível**
digitar nome completo. Ninguém procura colega por login (`z148534`), procura
por "Rafael Leão Ereno".

Agora a query quebra o texto digitado em **termos** e exige que cada termo
apareça em algum de `username`, `name`, `surname` ou no nome completo
concatenado. Sendo condições AND independentes, a ordem digitada não importa
("ereno rafael" acha) e dá pra misturar nome + login. Teto de 5 termos.

O gatilho no JS aceita até 5 palavras (era 1). Escolher um teto baixo é pior
que um alto: a lista fecharia na cara de quem digitou o nome inteiro
("Marcos de Queiroz Pastrolin Junior" tem 5 palavras). O que evita o dropdown
perseguir uma frase é outra coisa: busca **com espaço** e zero resultado
fecha a lista, sinal de que virou texto comum. Sem espaço, mantém "Nenhum
resultado" — ali ainda é uma busca em andamento.

### 2ª auditoria de datas/horas pt-BR (2026-08-17)

Revarredura do módulo inteiro (`date()`/`DATE_FORMAT`/`->format()` no PHP,
`Date`/`toISOString`/`toLocale` no JS, e todo `<?= ... ?>` de campo de data
nas views). A auditoria de 2026-08-14 tinha coberto o que vinha do banco; o
que faltava era a data que **transita pela URL** e era ecoada crua na tela:

- Cabeçalho do Repasse (`rp-nh-sub`) e o "**Data:**" do formulário do Diário
  de Bordo imprimiam `$date` direto — `2026-08-17` na cara do usuário.
- `TurnosReportPdf`: mesmo gap no subtítulo e no `<title>`.

Helpers novos, um por camada (a view não usa o trait): `rp_dateBr()` em
`plantonistas.report.view.php` e `TurnosReportBase::formatDateBr()`.

No `<title>` do PDF a data vai com **hífen** (`17-08-2026`), não barra: o
`<title>` é o nome de arquivo que o navegador sugere ao salvar/imprimir em
PDF, e barra não é caractere válido em nome de arquivo. Continua dia-mês-ano.

**Bug de fuso achado de brinde (heatmap do Repasse)**: a chave dos 30 dias
saía de `d.toISOString().slice(0,10)`, que converte pra **UTC** — em UTC-3,
das 21h em diante devolvia a data de amanhã, e o calendário inteiro
(contagem, célula de "hoje", link do relatório) deslizava um dia. Ou seja:
quebrava justamente durante o turno da noite. Agora a chave é montada com
`getFullYear/getMonth/getDate` (componentes locais). Verificado com TZ
America/Sao_Paulo às 9h/18h/20h/21h/22h/23h.

**Y-m-d que é protocolo e NÃO deve ser "corrigido"** (comentado no código pra
não cair na próxima auditoria): `value` do `input type="date"` (a spec do HTML
exige ISO — mudar quebra o filtro), `from`/`to` da URL do `problem.view`
nativo, `date=` dos links do módulo, a const JS `NOTE_DATE` (volta pro
backend no save da nota), as chaves internas (`$today_str`, chave do heatmap)
e o timestamp dos logs do cron.

Conferido e já correto: Histórico (`d/m/Y` e `d/m/Y H:i`), Visão Geral
(`d/m/Y` + dia da semana em pt-BR), Escala (nomes de mês em pt-BR), Gerenciar
Turnos (`HH:MM` 24h), notas e presença (`DATE_FORMAT` pt-BR com coluna
auxiliar de ordenação), e o CSV da Escala — que exporta `d/m/Y` com `;` e BOM
UTF-8 (Excel pt-BR) e cujo import aceita `d/m/Y`, `d-m-Y`, `Y-m-d` e serial
do Excel, com `d/m/Y` testado ANTES de qualquer outro formato (nunca
interpreta 03/04 como 4 de março).

### "Não é possível atualizar a função do usuário" ao habilitar o módulo (2026-08-17)

Salvar um papel (Usuários → Papéis de utilizador) com o módulo marcado
falhava com `Duplicate entry '4182' for key 'role_rule.PRIMARY'` em
`CRole::updateRules() → DB::insertBatch()`.

**Não é bug do módulo** — o Plantonistas não escreve em `role_rule` (só o
README tem um DELETE opcional de regras órfãs). É a tabela `ids` do Zabbix
fora de sincronia: `role_rule.role_ruleid` **não** é auto-increment, o
Zabbix reserva IDs via `DB::reserveIds()`, que lê `ids.nextid` e usa
`nextid + 1` em diante. Se alguém insere linhas em `role_rule` por SQL
direto (o velho `MAX(id)+1`) sem atualizar `ids`, o contador fica atrasado e
todo INSERT novo colide.

Números reais de produção em 2026-08-17: `ids.nextid = 4181` contra
`MAX(role_ruleid) = 4418` — 237 IDs de atraso. As linhas 4182–4194 eram do
módulo **`fcorr`** (`fcorr.list`, `fcorr.save`, … nos roleid 3 e 8),
inseridas na mão. O Plantonistas só foi o próximo a tentar usar a faixa.

Diagnóstico e correção (no banco `172.18.190.21`, não nos frontends — `ids`
é compartilhada):

```sql
SELECT MAX(role_ruleid) FROM role_rule;
SELECT * FROM ids WHERE table_name = 'role_rule';

UPDATE ids SET nextid = (SELECT MAX(role_ruleid) FROM role_rule)
 WHERE table_name = 'role_rule' AND field_name = 'role_ruleid';
```

`nextid` guarda o **último** ID usado, então igualar ao `MAX` é o valor
certo. Se a linha em `ids` não existir, o Zabbix a recria a partir do
`MAX` — aí a colisão teria outra origem.

Auditoria das outras tabelas (o hábito de inserir `role_rule` na mão
provavelmente atingiu mais coisa). São ~124 linhas em `ids`, então roda-se tudo
de uma vez com prepared statement, direto no cliente `mysql`:

```sql
SET SESSION group_concat_max_len = 1000000;

SET @sql = (SELECT CONCAT(
  'SELECT * FROM (',
  GROUP_CONCAT(CONCAT('SELECT ''',i.table_name,''' AS tbl,',i.nextid,
                      ' AS nextid,MAX(',i.field_name,') AS max_real FROM ',i.table_name)
               SEPARATOR ' UNION ALL '),
  ') t WHERE max_real > nextid ORDER BY max_real - nextid DESC'
) FROM ids i
  JOIN information_schema.columns c
    ON c.table_schema = DATABASE()
   AND c.table_name  = i.table_name
   AND c.column_name = i.field_name);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
```

**O JOIN com `information_schema` não é enfeite**: a tabela `ids` de produção
carrega linhas órfãs de versões antigas do Zabbix — `applications`,
`application_discovery`, `application_prototype`, `application_template`,
`items_applications`, `item_application_prototype`, `screens`,
`screens_items`, `screen_user`, `screen_usrgrp`, `valuemaps` (applications e
screens saíram do schema no 5.4). Sem o filtro, o `PREPARE` morre inteiro com
`Table 'zabbix.application_discovery' doesn't exist`. Essas linhas são
inofensivas (nenhum código do 7.0 as consulta) — resíduo de upgrade. Listá-las:
mesmo JOIN, com `LEFT JOIN ... WHERE c.table_name IS NULL`.

Só retorna tabela com o contador atrasado; o conserto é o mesmo `UPDATE ids`
trocando `table_name`/`field_name`. Ler o resultado com duas ressalvas:
`nextid` **maior** que o `MAX` é normal (ID reservado e não usado, ou
housekeeper que apagou linhas) e nem aparece nessa listagem; e se aparecer
tabela grande do server (`items`, `hosts`, `triggers`, `events`), tratar em
janela — ali quem reserva ID também é o Zabbix server, não só o frontend.

Lição pra qualquer módulo futuro: liberar action de módulo por SQL direto em
`role_rule` **exige** acertar `ids` na mesma transação. Preferir marcar o
módulo pela UI (Papéis de utilizador), que é o caminho que usa
`reserveIds()` corretamente.

### Máscara de telefone e importação em massa (2026-08-17)

`module_plantonistas_phones.phone` guarda **só dígitos** (quem salva já roda
`preg_replace('/\D/','')`), então máscara é assunto de exibição. Novo trait
`PhonesFormat` (`phoneDigits()` + `formatPhoneBr()`) é o único lugar que sabe
formatar, usado por `PhonesList`, `PhonesExport` e `PhonesImport`:

| dígitos | saída | caso |
|---|---|---|
| 11 iniciando com 0 | `0800 777-1234` | não geográfico |
| 11 | `(11) 98765-4321` | celular com DDD |
| 10 | `(11) 3456-7890` | fixo com DDD |
| 9 / 8 | `98765-4321` / `3456-7890` | sem DDD |
| resto | cru | ramal, +55 colado, cadastro errado |

O ramo do `0` existe porque **DDD brasileiro vai de 11 a 99, nunca começa com
0** — sem ele, `08007771234` sairia como `(08) 00777-1234`. Fora dos padrões
conhecidos devolve cru de propósito: parênteses em número sem DDD produz um
telefone com cara de certo e conteúdo errado.

A view exibe `phone_fmt` (montado no controller, view sem regra de negócio).
O `phnMask()` do JS espelha o PHP linha a linha — se as duas regras
divergirem, a linha muda de formato ao recarregar a página.

**Dois defeitos corrigidos no caminho**, ambos no `phnMask()` que já existia:

1. Ele rodava no `oninput` e assumia DDD sempre, então quem digitasse ramal de
   5 dígitos via o número virar `(12) 345` no meio da digitação. Agora roda no
   `onblur` — dá pra digitar em paz.
2. Ele fazia `substring(0, 11)` nos dígitos. Como o `value` do input é o que
   vai no submit, um número com `+55` colado (13 dígitos) seria **gravado
   mutilado**. O truncamento saiu; fora dos padrões o campo devolve os dígitos
   inteiros, igual ao PHP.

**Importação em massa** (`plantonistas.phones.import` → `PhonesImport`): lê o
MESMO CSV que a exportação gera (`Usuario;Nome;Telefone`), para o fluxo ser
exportar → editar no Excel → reimportar. Detalhes decididos com o Rafael:

- Casa por `users.username` (único, não muda com correção de cadastro); a
  coluna Nome é ignorada — existe só para quem preenche a planilha se situar.
- Cabeçalho detectado por sinônimos (`usuario/username/login/user` e
  `telefone/celular/fone/phone/contato/ramal`), com e sem acento, e separador
  detectado entre `;`, TAB e `,`. Aceita planilha de 2 colunas.
- **Telefone vazio NÃO apaga** o cadastro: o CSV traz todos os usuários, a
  maioria sem telefone, e um round-trip parcial limparia a base. Conta as
  linhas ignoradas no resultado. Para remover, limpa-se o campo na tela.
- Permissão: a mesma regra da edição individual (Super Admin altera qualquer
  um; os demais só quem compartilha grupo **e** tem o mesmo papel). Linha fora
  do alcance é recusada e reportada, nunca aplicada em silêncio.
- `INSERT ... ON DUPLICATE KEY UPDATE` (userid é PK) — nasceu sem a janela de
  corrida do SELECT-then-INSERT que o `PhonesSave` ainda tem (ver Backlog).
- Limites: 4 a 15 dígitos. O piso de 4 aceita ramal curto; o teto barra lixo.

**A exportação passou a gravar o telefone com máscara** por causa do Excel:
telefone só-dígitos é lido como número, o que come zero à esquerda e vira
notação científica em campo longo. A importação remove a máscara de volta. E
se ainda assim chegar `9,87654E+10`, a linha é **recusada com aviso** em vez de
limpar os não-dígitos — isso produziria um número plausível e errado, que
ninguém notaria depois.

Upload via base64 em campo hidden, mesmo padrão do import da Escala (a rota do
Zabbix nessa action não trata multipart). Como as outras actions de redirect do
módulo, `phones.import` não tem `"view"` no manifest — responde redirect.

### CSRF ligado nas actions de escrita (2026-08-19, issue #3)

Todas as 21 actions chamavam `disableCsrfValidation()`. Nas 10 que escrevem no
banco isso deixava qualquer página aberta por um Admin logado disparar
`plantonistas.delete` ou apagar um turno sem clique consciente.

**A regra que decide tudo**: `CController::checkCsrfToken()` começa com
`if (!isRequestMethod('post')) return false;` — em GET a validação não é
dispensada, ela **falha**. Então `disableCsrfValidation()` continua obrigatório
em toda action alcançável por GET (as 11 de leitura, incluindo os exports em
CSV e o PDF); tirar de lá derruba a tela na hora.

**Em módulo o token é por action COMPLETA.** O `checkCsrfToken()` tem um ramo
só para classes em `Modules\`: confere contra `$this->action` inteiro. O core
agrupa pelo primeiro segmento (o token `host` serve para `host.edit`,
`host.delete`…) — aqui não: `plantonistas.save` e `plantonistas.delete` têm
tokens diferentes. Cada view recebe um token por action que ela chama.

Onde o token entra, por tela:

| Tela | Como |
|---|---|
| Escala | hidden nos forms de salvar e de remover; `PLT_CSRF_IMPORT` no form de import montado em JS |
| Telefones | hidden no form de salvar; `PHN_CSRF_IMPORT` no form de import |
| Gerenciar Turnos | mapa `CSRF_TOKENS[action]` dentro do helper `post()` — um ponto só para as 3 actions |
| Repasse | `CSRF_NOTES_SAVE` no fetch da nota; `CSRF_MENTIONS_READ` no fetch e no `sendBeacon` (que envia `URLSearchParams` como form-urlencoded, então o token chega no `$_POST` igual) |

**"Remover" da Escala deixou de ser link GET.** Era
`<a href="zabbix.php?action=plantonistas.delete&scheduleid=N">` — com CSRF
ligado nunca passaria, e um link GET que apaga dado é exatamente o vetor da
issue. Virou submit de um form oculto (`plt-del-form`), com o id preenchido por
`pltDelete()`. Consequência aceita: URL de remoção salva em favorito passa a
responder "Acesso negado".

Dois defeitos corrigidos junto, nesse mesmo botão:

- **Nome com apóstrofo quebrava o `onclick`.** O rótulo era interpolado como
  `addslashes(htmlspecialchars($label))` dentro do atributo. Do PHP 8.1 em
  diante `htmlspecialchars()` usa `ENT_QUOTES` por padrão, então a aspa já
  virou `&#039;` e o `addslashes()` não acha mais nada para escapar — o
  navegador decodifica a entidade ao parsear o atributo e o JS recebe
  `pltDelete(1,'Sant'Ana')`, sintaxe inválida. O handler era descartado
  inteiro: o clique não removia, não fazia `stopPropagation` e ainda
  selecionava o dia. Agora o nome vai em `data-label` e o clique é tratado por
  um listener **na fase de captura** — precisa ser captura porque o `<td>` tem
  `onclick` inline, que roda no bubbling e selecionaria o dia antes.
- Duplo clique disparava dois POSTs, e o segundo respondia "Entrada não
  encontrada" logo depois de uma remoção bem-sucedida. Guarda `pltDeleting`.

**Modo de falha novo, que vale conhecer antes de debugar**: token inválido
lança `CAccessDeniedException` e o Zabbix responde a página **HTML** "Acesso
negado" — inclusive para action AJAX com `layout.javascript`, que cai no
`default` do `ZBase::denyPageAccess()`. Sem tratamento, o `r.json()` do fetch
estoura erro de parse e o usuário vê "erro de conexão". Por isso o `post()` de
Turnos e o fetch da nota checam `content-type` antes e devolvem uma mensagem
dizendo para recarregar a página. **Nada disso vai para log nenhum** — não há
`error_log` nesse caminho do Zabbix. E a causa mais provável no dia a dia não é
ataque: é a aba deixada aberta o turno inteiro, com a sessão expirada, porque o
token deriva do `secret` da sessão.

Os forms de POST nativo (salvar escala, salvar telefone, os dois imports) não
têm como exibir mensagem amigável — caem na página "Acesso negado" do Zabbix e
o conteúdo digitado se perde. Aceito por ora; a alternativa seria converter
esses saves em AJAX.

### Fim da conexão mysqli própria — ZbxDb (2026-08-19, issue #1 passo 1)

A família repasse abria uma conexão **mysqli própria a cada request**
(`getDb()`, lendo credencial de `$GLOBALS['DB']`), paralela à do Zabbix. Isso
custava uma conexão por request e deixava metade do módulo fora da única
camada do Zabbix que é agnóstica de banco — o que trava o PostgreSQL.

**Escolha de rota**: em vez de reescrever as ~50 consultas à mão (50 chances de
esquecer um `zbx_dbstr()` e criar SQL injection), entrou um adaptador fino em
`actions/ZbxDb.php` + `ZbxDbStmt.php` + `ZbxDbResult.php`. Ele implementa a
fatia da API do mysqli que o módulo usa — `prepare`/`query`/`close`/`error`/
`insert_id`, `bind_param`/`execute`/`get_result`, `fetch_all`/`fetch_assoc`/
`fetch_row` — por cima de `\DBselect`/`\DBexecute`/`\DBfetch`. Nenhuma consulta
foi reescrita; só o `getDb()` e os type hints `\mysqli $db` → `ZbxDb $db`.

O escape ficou num lugar só (`ZbxDbStmt::escape()`), com o valor entrando pelo
tipo declarado no `bind_param` — `i` vira cast int, `s` vai por `\zbx_dbstr()`,
`null` vira `NULL` literal. O parser de `?` respeita literal entre aspas
(inclusive `''` e escape com barra), porque `str_replace` trocaria o caractere
errado numa consulta futura que tivesse `?` dentro de string.

Autoload confirmado no fonte do Zabbix 7.0: `CAutoloader` resolve
`Modules\Plantonistas\Actions\ZbxDb` para `actions/ZbxDb.php` na pasta do
módulo. Classe auxiliar **não** precisa estar no manifest — é o mesmo caminho
que já carrega os traits `TurnosReportBase` e `PhonesFormat`.

**Três diferenças de comportamento que o adaptador teve que compensar** — e que
valem para qualquer código novo aqui:

- **`\DBselect`/`\DBexecute` não lançam**, devolvem `false`. Todo o módulo foi
  escrito contra `MYSQLI_REPORT_STRICT`, com try/catch em volta das consultas.
  Por isso o adaptador **lança `\RuntimeException`** quando a camada devolve
  false: sem isso, consulta quebrada voltaria a virar array vazio em silêncio,
  que é o defeito que já custou o diagnóstico de "grupos não listam usuários".
- **`\DBfetch()` converte NULL na string `'0'`** por padrão (`$convertNulls`).
  As três leituras passam `false`. Nenhum ponto quebraria hoje, mas o primeiro
  `=== null` ou `?? 'text'` escrito depois quebraria sem avisar.
- **`prepare()` não valida mais a consulta no servidor** — só monta a string. O
  mysqli validava e lançava ali; agora o erro nasce no `execute()`. Isso já
  tinha deixado o fallback de schema do `TurnosNotesSave` inalcançável (o
  `execute()` estava fora do try) — corrigido movendo o `execute()` para dentro
  de cada ramo. Regra: `prepare`, `bind_param` e `execute` ficam **juntos** no
  mesmo try.

**Erro de consulta ia virar banner vermelho na tela, com o SQL inteiro.** O
`trigger_error` do `DBselect` é capturado pelo `zbx_err_handler` do frontend e
vira mensagem na página. `ZbxDb::silenced()` troca o handler durante a chamada
e restaura depois — o erro continua chegando como exceção e como
`error_log('[plantonistas] …')`, mas não polui a tela do dado principal nem
expõe SQL na UI.

**Ganho colateral**: o `getDb()` antigo chamava
`mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT)`, configuração
**global do processo**, que passava a valer também para a conexão nativa do
Zabbix no resto do request. Isso acabou.

O que **não** mudou: o SQL continua MySQL-only (`DATE_FORMAT`,
`FROM_UNIXTIME`, `IF()`, `TIMESTAMPDIFF`, `INTERVAL`, `ON DUPLICATE KEY
UPDATE`, e o `LAST_INSERT_ID()` do `insert_id`). O adaptador tira o bloqueio da
conexão paralela, não o do dialeto — esse é o passo 3 da issue #1.

### Escala alimenta o escalonamento do Zabbix (2026-08-19, issue #5)

`scripts/cron_sync_oncall.php` resolve o turno corrente de cada equipe, acha o
titular na escala e sincroniza um grupo de usuários do Zabbix com essa única
pessoa. A partir daí a **Ação nativa** escala para o grupo — o módulo não
reimplementa media type, mensagem nem escalonamento. É o que tira a Escala da
condição de tela de consulta.

Decisões do Rafael, todas com a alternativa oferecida e recusada:

- **Um grupo por equipe** (`Plantonista de Hoje - NOC`), não um grupo único:
  cada equipe cuida de hosts diferentes e precisa de Ação própria.
- **Dia sem cobertura mantém quem está no grupo.** Esvaziar deixaria alerta
  sem destinatário de madrugada; fallback exigiria manter mais um grupo. O
  preço é a pessoa receber alerta fora do turno dela — por isso sai `INFO:` no
  log toda vez que isso acontece.
- **5 minutos.** A virada de turno leva até um ciclo para refletir.

Decisões técnicas que não foram perguntadas:

- **Só o titular entra no grupo.** Reserva não está de plantão, está
  disponível.
- **O script não cria grupo de usuário.** Criar exigiria reservar `usrgrpid`
  na tabela `ids`, e o incidente do `role_rule`/`fcorr` (seção acima) mostra o
  estrago de escrever ID na mão em tabela do core. Grupo ausente → WARN e a
  equipe é pulada.
- **`users_groups.id` NÃO é auto-increment** — `reserveIds()` replica o
  `DB::reserveIds()` do Zabbix (SELECT ... FOR UPDATE em `ids`, UPDATE do
  contador, transação). `MAX(id)+1` aqui repetiria exatamente a colisão que
  impediu de salvar papel de usuário em produção.
- **Turno que vira o dia pertence à data em que começou**: às 02h, quem está
  de plantão é o escalado de *ontem*. `currentShiftDate()` espelha o
  `computeCustomShiftBounds()` do trait — duplicação proposital, o cron roda em
  CLI sem carregar o módulo. Errar isso escala a pessoa errada exatamente na
  madrugada.
- **Turnos que não cobrem 24h deixam buraco** (ex.: só diurno cadastrado). No
  intervalo descoberto não há turno corrente e o grupo fica como está — mexer
  seria chute.
- **INSERT antes do DELETE**, de propósito. Na virada de turno o caminho é
  sempre "sai o antigo, entra o novo"; com o DELETE primeiro o grupo fica sem
  ninguém entre as duas escritas — e, se o INSERT falhasse, ficaria vazio até
  o ciclo seguinte: 5 minutos de Ação sem destinatário, exatamente o que a
  decisão de "não esvaziar" existe para evitar. Invertido, o pior caso é o
  grupo ficar com duas pessoas por um ciclo. Não há transação em volta porque
  `reserveIds()` abre a sua própria, e em MySQL `START TRANSACTION` comita
  implicitamente a que estiver aberta — envolver tudo numa transação externa
  comitaria o DELETE em silêncio.
- **Grupo de destino com Status = Desabilitado é recusado.** O status de um
  usuário no Zabbix é `MAX(usrgrp.users_status)` sobre todos os grupos dele:
  pôr o plantonista num grupo desabilitado desabilitaria a conta — ele pararia
  de logar, sumiria das telas do próprio módulo (`enabledUserClause()`) e
  deixaria de ser notificado, que é o oposto do objetivo. Erro plausível, já
  que é um grupo "técnico" que ninguém pretende usar para login, e o sintoma
  apareceria longe da causa.
- **Falha ao ler os turnos pula a equipe, não cai no modo legado.** Turno não é
  dado acessório: é o que decide quem está de plantão. Com `$shifts = []` o
  script sincronizaria a entrada `shift_id = 0` — que existe mesmo em grupo com
  turnos, porque o import CSV grava sempre em modo legado — e escalaria a
  pessoa errada, com um `OK:` no log dizendo que deu certo.
- **Plantonista é validado antes** (existe + habilitado): `schedule.userid` não
  tem FK para `users`, mas `users_groups.userid` tem.
- Guarda contra o grupo de destino ser o próprio grupo da equipe (prefixo mal
  configurado esvaziaria a equipe inteira) e aviso quando dois turnos se
  sobrepõem — sem ele, um plantonista seria ignorado todo dia sem rastro.
- `DRY_RUN=1` mostra o que faria sem gravar. Falha numa equipe não impede as
  outras, mas o script sai com código 1 se houve erro — dá o que monitorar.

### Fechar turno — o repasse vira documento (2026-08-19, issue #2)

`module_plantonistas_shift_reports` era tabela morta: criada, migrada,
documentada e com **zero leitura e zero escrita**. Agora o botão "Fechar turno"
no Repasse grava um snapshot JSON do relatório nela, e o PDF renderiza esse
snapshot (`plantonistas.report.pdf&report_id=N`) — sem tela nova, reaproveitando
a navegação por data/turno que já existe.

**A decisão que rege tudo (Rafael): o fechamento é DOCUMENTO SEPARADO, não
cache da tela.** A tela continua consultando ao vivo para todos. O motivo é de
segurança, não de gosto: o payload do repasse varia por usuário — eventos e Top
Hosts pelo `host_filter` derivado de `rights`, MTTA restrito ao próprio usuário
quando role type = 1, Notas e Presença por grupo compartilhado. Usar o snapshot
como cache serviria a um usuário o que outro enxergava. Das três saídas
avaliadas (snapshot por contexto, documento separado, snapshot só para Admin+),
o documento é a única que não abre mão de nenhuma regra — o preço é abrir mão
do ganho de performance, que era um dos três motivos da issue.

**Quem lê o documento** (`canReadSnapshot()`): Super Admin lê tudo; fechamento
feito por Super Admin só Super Admin lê (o snapshot dele é sem filtro nenhum);
nos demais casos os grupos do autor têm que **caber** nos do leitor. Compartilhar
UM grupo não basta — autor em `[NOC, Redes]` e leitor só em `[NOC]` passaria, e
o leitor veria as notas dos analistas de Redes. Com os mesmos grupos, os
`rights` de host também coincidem, e é isso que sustenta a equivalência.

**MTTA é a exceção que nenhum filtro de grupo cobre**, e quase passou batido: a
restrição é por **papel**, não por grupo. Um Admin do NOC fecha o turno (MTTA de
todos gravado), um User do NOC abre o documento, passa no teste de grupo — e
veria a tabela inteira. Por isso `TurnosReportPdf` **reaplica**
`restrictMttaByRole()` com o papel do LEITOR e recalcula o MTTA global sobre o
resultado; usar o `global_mtta` do snapshot devolveria o agregado de todos no
KPI.

Outras decisões:

- **Append-only**: fechar de novo cria outra linha, nunca sobrescreve. O
  histórico fica imutável por construção e "refazer" é rastreável sem coluna de
  auditoria. Refechar exige Admin+; `countClosedReports()` roda **sem** filtro
  de visibilidade de propósito — com filtro, um User refecharia só porque não
  enxerga o fechamento anterior.
- `generated_at` é gerado em **PHP**, não com `NOW()`: o banco roda em outro
  host e pode estar em UTC, e num documento cuja razão de ser é o carimbo de
  hora, "congelado às 21:05" para um fechamento das 18:05 é defeito grave.
- A mensagem de erro do fechamento é **fixa**: o `RuntimeException` do `ZbxDb`
  carrega o SQL inteiro, com o JSON do snapshot dentro, e SQL não vai para a UI.
- `migrateColumns()` promove `report_json` para `LONGTEXT` se o schema antigo
  tiver deixado `TEXT`. A tabela nunca teve uma linha escrita até agora, então
  as colunas dela nunca foram exercitadas em produção — com `TEXT` (64 KB) e
  sem `sql_mode` STRICT o snapshot truncaria em silêncio, e a leitura devolveria
  "fechamento não encontrado" por JSON inválido.

**Armadilha de PHP que quase entrou** (achada na revisão): a versão do formato
do snapshot nasceu como `private const` **dentro do trait**. Constante em trait
só existe do **PHP 8.2** em diante; em 8.0/8.1 é erro de *compilação* ("Traits
cannot have constants") — e o `TurnosReportBase` é usado por 11 classes, ou
seja, o Repasse inteiro e Gerenciar Turnos cairiam em 500. O lab roda 8.4 e o
erro passaria no teste para explodir em produção, onde RHEL entrega 8.0/8.1 com
facilidade. Virou método (`snapshotVersion()`). **Regra: nada de `const` em
trait neste módulo enquanto o piso for PHP 8.0.**

Limitação conhecida: dois usuários fechando ao mesmo tempo passam ambos pela
checagem e gravam dois documentos, driblando o "refechar exige Admin+". O
append-only torna o estrago barato; fechar de verdade exigiria `SELECT ... FOR
UPDATE`.

### Menção notifica pelo media type do usuário (2026-08-19, issue #6)

A menção só existia no banner da tela do Repasse: quem fosse mencionado às 3h e
estivesse em outra tela (ou nem logado) não ficava sabendo. Agora o módulo
avisa pelo media type que a pessoa já configurou.

**A premissa que estava errada.** A nota interna dizia que "a tabela `alerts`
não pode ser usada por módulo, o jeito é `exec()` + `curl` no relay SMTP".
Conferido no fonte do Zabbix 7.0, está errado nas duas metades:

- Inserir em `alerts` realmente não serve, mas por outro motivo: `actionid` e
  `eventid` são NOT NULL com FK ON DELETE CASCADE (seria preciso emprestar uma
  Ação e um evento reais, que o housekeeper apaga depois levando a linha
  junto), e o `alertid` é alocado por um **contador em memória do server**, não
  pela tabela `ids` do frontend — dois alocadores para a mesma coluna, a mesma
  classe de incidente do `role_rule`/`fcorr`.
- Existe via oficial frontend→server: o request de socket **`alert.send`**,
  exposto por `CZabbixServer::testMediaType()` — o que o botão "Test" de Tipos
  de mídia usa. Não grava em `alerts`, não cria task, é síncrono.

Não há task de "enviar mensagem" (`ZBX_TM_TASK_*` não tem nada disso) e a API
JSON-RPC só expõe `alert.get`.

**As duas contrapartidas, e como estão tratadas** (`actions/ZbxAlertSender.php`,
isolado nos moldes do `ZbxDb` — a rota é interna do Zabbix e pode mudar):

- **Exige Super Admin**, validado no server. Como quem escreve a nota é
  analista, a sessão dele não serve: é um token de API na env
  `PLANTONISTAS_ALERT_TOKEN`. **Sem token o módulo não notifica e nada
  quebra** — o banner continua. É por isso que `token-ausente` não vai para o
  log: é o estado normal de quem não ligou o recurso.
- **A janela de notificação não vem de graça**: quem avalia `media.period` e
  `active` é o escalonador, ao inserir em `alerts`; o `alert.send` recebe
  `sendto`/`mediatypeid` prontos e manda. O filtro foi replicado em PHP
  (`matchesPeriod()`), e é avaliado no **fuso do destinatário** (`users.timezone`)
  — no frontend o fuso do PHP é o do usuário logado, ou seja o AUTOR, e usá-lo
  deslocaria a janela de um colega em outro fuso. Terceiro lugar em que esse
  mesmo bug de fuso apareceu.

`media.severity` é ignorado de propósito: é bitmask de severidade de trigger, e
uma menção não tem severidade.

**O que a revisão pegou e valeu a implementação inteira:**

- **Webhook exige mapa `nome => valor`**, não lista de `{name, value}` — no
  formato errado o server não acha parâmetro nenhum e o webhook roda vazio. E
  as macros `{ALERT.SENDTO}`/`{ALERT.SUBJECT}`/`{ALERT.MESSAGE}` **não são
  expandidas** por esta rota (na tela de teste é o operador que digita o valor
  real no lugar da macro), então a substituição é nossa — sem ela o Telegram
  receberia literalmente `{ALERT.SENDTO}` como destinatário.
- **O envio síncrono podia travar o save da nota por minutos.** Cada envio é um
  socket novo, e o timeout default do teste de media type é **65s** — pensado
  para quem clicou em "Test" e sabe que vai esperar. Uma nota mencionando 10
  pessoas com 2 mídias seriam 20 sockets em série. Agora são timeouts próprios
  (2s/5s) e teto de 5 envios por save; o que passar fica só no banner. A fila
  por cron seria melhor ainda — está no backlog.
- **Link do e-mail montado com `$_SERVER['HTTP_HOST']` era vetor de phishing**:
  o Host é controlado por quem faz a requisição, então quem escreve a nota
  poderia mandar um link para domínio próprio *pelo canal legítimo do Zabbix*.
  Sem a URL configurada em Administração → Geral, o link vai **relativo**.
- Media type "Script" (type 1) ficou de fora: o `alert.send` espera os
  parâmetros dele em outro formato (lista plana, sem sendto/subject), e mandar
  errado falharia só no log.

### PostgreSQL: as armadilhas semânticas primeiro (2026-08-19, issue #1)

O levantamento completo do que é MySQL-only está no `ROADMAP.md` (Fase 3). O
que foi feito nesta primeira passada é o subconjunto que **também conserta ou
protege o MySQL de hoje** — nenhuma dessas mudanças espera por PostgreSQL para
valer a pena, e todas continuam corretas no MySQL.

O critério: erro de sintaxe o PG acusa na hora e a gente descobre no primeiro
teste. Perigoso é o que roda nos dois bancos e devolve resultado **diferente**.

- **Chaves do `INFORMATION_SCHEMA` em maiúsculo** era a pior de todas. O MySQL
  devolve `TABLE_NAME`, o PostgreSQL devolve `table_name`. Lendo em maiúsculo,
  no PG `existingTables()` devolveria `[]` e `migrateColumns()` cairia no
  `isset()` falso: **o RENAME das tabelas antigas nunca aconteceria e nenhuma
  migração de coluna rodaria** — sem erro, sem log, com as telas abrindo
  normalmente. Agora tudo passa por `array_change_key_case($row, CASE_LOWER)`
  antes de ser usado.
- **Expressão booleana crua em SELECT** (`(NOT EXISTS (...) AND ...) AS
  habilitado`, no `cron_sync_oncall`): MySQL devolve `1`/`0`, PostgreSQL
  devolve `t`/`f`, e `(int)'t'` é `0`. Todo plantonista viraria "desabilitado",
  toda equipe seria pulada e o log mandaria investigar um cadastro correto.
  Virou `CASE WHEN ... THEN 1 ELSE 0 END`.
- **Comparação de texto**: o MySQL com collation `_ci` ignora caixa; o PG não.
  Afetava a busca de menção (`@rafael` deixaria de achar `Rafael` — a feature
  ficaria inútil, com cara de bug intermitente), as exclusões de `guest` e
  `api_`, e o `usrgrp.name = ?` do cron de escalonamento, que passaria a dizer
  que um grupo visível na tela "não existe". Tudo com `LOWER()` dos dois lados
  agora — e no cron também `TRIM()`, porque nome de grupo é digitado à mão.
- **`SELECT DISTINCT` com `ORDER BY` de expressão fora da lista de seleção**: o
  MySQL aceita, o PG recusa. A expressão virou a coluna `sort_label`.
- **`IFNULL(bigint, '')`** não é troca mecânica por `COALESCE`: o PG recusa
  misturar `BIGINT` com `''`. Virou `COALESCE(..., 0)`, que mantém o contrato
  com a view (que testa por truthiness, e `'0'` é falsy em PHP) e alinha com o
  `PlantaoOverview`, que já usava `0`.
- `IF()` → `CASE WHEN`, e `DATABASE()` → `current_schema()` no ramo PG.
- `INFORMATION_SCHEMA.STATISTICS` não existe no PG: a leitura de índices passou
  a escolher entre ela e `pg_indexes`. E a coluna de tipo passou a ser
  `COLUMN_TYPE` (MySQL, traz o `unsigned`) ou `DATA_TYPE` (padrão), conforme o
  banco — no PG `unsigned` não existe, então aquele ramo de correção
  simplesmente não tem o que fazer.

**DDL com fonte única** (mesma passada): as 9 tabelas eram descritas duas
vezes, em MySQL puro — no `Module::tableDdl()` e no `sql/schema.mysql.sql`. Agora
`Schema.php` descreve cada tabela UMA vez, em tipos abstratos, e gera o DDL do
banco em uso; `sql/schema.mysql.sql` e `sql/schema.pgsql.sql` são **gerados**
por `scripts/gen_schema.php` e não devem ser editados à mão. Mapeamentos
decididos: `TINYINT(1)` → **`SMALLINT`** e não `BOOLEAN` (o código compara
`active = 1` em 10 lugares, e no PG `boolean = integer` é erro);
`BIGINT UNSIGNED` → `BIGINT` (o PG não tem unsigned, e o ganho não paga a
divergência — a migração que promovia `userid` para unsigned saiu junto, senão
toda instalação MySQL nova faria um ALTER inútil desfazendo o próprio CREATE);
`AUTO_INCREMENT` → `BIGSERIAL`; `KEY` inline → `CREATE INDEX` (só no PG:
`CREATE INDEX IF NOT EXISTS` **não existe no MySQL 8.0**, então lá o índice
continua inline, que é o que já rodava). `ON UPDATE CURRENT_TIMESTAMP` não tem
equivalente de coluna no PG e exigiria trigger — como as duas colunas assim
(`updated_at` de `shifts` e de `user_shift`) não são lidas por tela nenhuma,
no PG elas ficam sem auto-atualização, e isso está registrado no `Schema.php`.

**Regra para daqui em diante**: `Module::isPgsql()` (lê `$DB['TYPE']`) é o
único lugar que decide dialeto. Tabela nova ou coluna nova entra no
`Schema.php`, nunca em SQL escrito à mão nos dois lugares. Consulta nova não usa `IF()`, `IFNULL`,
`DATE_FORMAT`, `FROM_UNIXTIME`, `TIMESTAMPDIFF` nem devolve booleano cru — e
comparação de texto com valor digitado por gente leva `LOWER()`.

**Funções de dialeto num lugar só** (2026-08-19): `actions/SqlFn.php` gera
`DATE_FORMAT`/`to_char`, `FROM_UNIXTIME`/`to_timestamp`,
`TIMESTAMPDIFF`/`EXTRACT(EPOCH…)`, `INTERVAL` e o upsert
(`ON DUPLICATE KEY UPDATE`/`ON CONFLICT`) conforme o banco. As ~20 consultas
que usavam essas funções passaram a chamar o helper — nenhuma tem `if` de
dialeto dentro. O `insert_id` do `ZbxDb` escolhe entre `LAST_INSERT_ID()` e
`lastval()`.

Duas armadilhas que o helper documenta porque custariam caro:

- No `to_char` do PostgreSQL, **`HH` é relógio de 12 horas** — usá-lo faria as
  14h virarem `02` em silêncio. O certo é `HH24`.
- O módulo converte epoch somando o offset do PHP (`ev.clock + $tzOffset`) e só
  então formatando, o que **pressupõe banco em UTC**. A premissa não é nova; o
  que é novo é estar escrita. No ramo PG ela virou explícita com
  `AT TIME ZONE 'UTC'`, senão o `to_timestamp()` seria renderizado no fuso da
  sessão e o valor mudaria conforme a configuração do servidor.

**Migrações de coluna por dialeto** (2026-08-19): `migrateColumns()` passou a
montar todo o DDL por helper — `renameTableSql`, `addColumnSql`, `addIndexSql`,
`dropIndexSql`, `dropUniqueSql`, `addUniqueSql`, `modifyTypeSql`. Quatro coisas
que a revisão salvou:

- **`DROP INDEX` não remove UNIQUE no PostgreSQL.** Lá o `uniq_group_day` é
  CONSTRAINT (criado pelo próprio módulo), e o banco recusa: "cannot drop index
  … because constraint … requires it". Como `\DBexecute()` não lança, o
  try/catch do `init()` não pegaria nada — o erro sairia como banner vermelho
  com o SQL inteiro, **a cada carga de página**, porque a migração nunca
  concluiria. Daí o `dropUniqueSql()` separado.
- **`AFTER coluna` não existe no PG** (lá coluna não tem ordem) e `MODIFY
  COLUMN` vira dois statements (`ALTER COLUMN … TYPE` + `SET NOT NULL`) — por
  isso `modifyTypeSql()` e `addColumnSql()` devolvem **lista**, executada por
  `execAll()`.
- **O `COMMENT` da coluna É emitido no PG**, como `COMMENT ON COLUMN`. Não é
  enfeite: o `Schema.php` já emite numa instalação do zero, e se a migração não
  emitisse, dois ambientes PG teriam catálogos diferentes — a divergência
  silenciosa que o `Schema` existe para evitar.
- **`NOW()` do PG é `timestamptz`**, e as colunas são `TIMESTAMP` sem fuso: a
  conversão usaria o `TimeZone` da sessão, que ninguém configura no frontend.
  Com o servidor em UTC e o cron gravando em `America/Sao_Paulo`, o "online nos
  últimos 15 min" erraria por 3 h. `SqlFn::now()` devolve `LOCALTIMESTAMP` no
  PG — quarto lugar do módulo em que fuso ia morder.

**Os arquivos `sql/*.sql` são gerados** (`scripts/gen_schema.php` para os
schemas). Uma versão anterior do gerador tinha um bug que omitia 17 índices e
os 8 comentários de tabela — e o `install.sh` executa esses arquivos quando o
operador responde "banco novo", enquanto o `Module::init()` **não conserta
depois** (`existingTables()` vê a tabela pronta). Regenerar sempre que o
`Schema.php` mudar; nunca editar à mão.

O que **falta** para rodar em PG está no ROADMAP: os dois crons, que rodam em
CLI com mysqli e precisam de PDO. E nada disso passou por um lab PostgreSQL.

### Backlog conhecido

- Salvar vínculo analista→turno em massa (hoje é um clique por analista) —
  ganhou urgência: a coluna Turno da Presença mostra "Sem turno" pra quase
  todo mundo enquanto os vínculos não forem cadastrados.
- ~~`install.sh` gerava `/etc/cron.d/plantonistas-presence` sem env nenhuma~~ —
  **corrigido em 2026-08-19**: os três blocos de cron do `scripts/install.sh`
  (docker, RHEL e Ubuntu) passaram a escrever `DB_HOST`/`DB_NAME`/`DB_USER`/
  `DB_PASS` no arquivo, com `chmod 600` em vez de 644, porque ali tem senha.
  (Correção de registro: uma versão anterior desta nota dizia que o
  `install.sh` não existia mais no repo. Existe — está em `scripts/`, não na
  raiz, que foi onde procurei.)
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
  `INSERT ... ON DUPLICATE KEY UPDATE` (pequena janela de corrida) — o
  `PhonesImport` novo já usa a forma certa, dá pra copiar de lá.
- `readCsv()` duplicado entre `PlantaoImport` e `PhonesImport` (~20 linhas,
  detecção de separador e BOM). Extrair pra trait se aparecer um terceiro.
- Importação de telefones aceita só CSV; o leitor de XLSX existe, mas está
  privado no `PlantaoImport`. Extrair se pedirem planilha nativa.
- Telefones: Admin/User só se veem dentro do mesmo `roleid` — confirmar se é
  intencional (ver "Modelo de permissões" em Contexto).
- Nome duplicado (`name` + `surname` concatenados às cegas) ainda existe nos
  `CONCAT` feitos em SQL: `queryShiftAnalysts()`, `queryMTTA()` e
  `listUsersByGroup()` no trait, e em toda a família escala (`PlantaoList`,
  `PlantaoExport`, `PhonesList`, `PhonesExport`, `PlantaoOverview`,
  `PlantaoHistory`). Corrigir exige trazer `name`/`surname` separados e
  montar o label em PHP com `formatUserLabel()` — feito só onde o defeito
  estava visível/persistido; o resto ficou pra quando incomodar.
- Notificação de menção é síncrona, dentro do save da nota (teto de 5 envios,
  timeouts de 2s/5s). O certo seria fila + cron, como o presence tracker: o
  CLI não tem ninguém esperando, e aí caem o teto e os timeouts curtos. Exige
  reimplementar o socket do `alert.send` sem `CZabbixServer` (classe de
  frontend, indisponível em CLI) e uma coluna `notified_at` em
  `module_plantonistas_mentions`.
- Notificação de menção sem dedupe: 10 menções à mesma pessoa = 10 avisos. Dá
  para suprimir quem está online usando `module_plantonistas_user_sessions`,
  que já existe.
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
- Action nova que **escreve** no banco: nada de `disableCsrfValidation()`; o
  caller tem que ser POST e mandar `_csrf_token` gerado com
  `\CCsrfTokenHelper::get('<action completa>')`. Action alcançável por **GET**
  (página, filtro, export, PDF) mantém `disableCsrfValidation()` — em GET a
  validação falha, não é dispensada.
- Nunca engolir exceção de DB em silêncio: `error_log` + UI distinguindo
  "vazio de verdade" de "consulta falhou". Na família repasse, `prepare()`,
  `bind_param()` e `execute()` ficam **juntos dentro** do try — quem lança
  agora é o `execute()` (ver "Fim da conexão mysqli própria").
- Consulta nova na família repasse passa pelo `ZbxDb` (`$db->prepare(...)` com
  `?` + `bind_param`), nunca por conexão própria nem por SQL interpolado à mão.
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
  `sql/rollback-to-old-modules.<banco>.sql`, depois reabilitar os antigos (README).
