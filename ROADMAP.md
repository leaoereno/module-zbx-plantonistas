# ROADMAP — module-zbx-plantonistas

Plano de atuação sobre as 6 issues abertas no GitHub. Base: v4.0.0.
Escrito em 2026-08-19.

---

## Visão geral

| # | Issue | Tipo | Esforço | Fase |
|---|---|---|---|---|
| ~~[#1](https://github.com/leaoereno/module-zbx-plantonistas/issues/1)~~ | ~~Suporte a PostgreSQL (v5.0)~~ — **feito** (2026-08-31): passos 1–6 no código e passo 7 homologado em produção (7 telas, 3 crons, schema e fechamento de turno) | Portabilidade | G | 1 e 3 |
| ~~[#3](https://github.com/leaoereno/module-zbx-plantonistas/issues/3)~~ | ~~CSRF desativado nas actions de escrita~~ — **feito** (2026-08-19) | Segurança | M | 2 |
| ~~[#4](https://github.com/leaoereno/module-zbx-plantonistas/issues/4)~~ | ~~Cron de presença: eliminar a API do Zabbix~~ — **feito** (2026-08-19) | Segurança + dívida | M | 2 |
| ~~[#5](https://github.com/leaoereno/module-zbx-plantonistas/issues/5)~~ | ~~Sincronizar escala com escalonamento do Zabbix~~ — **feito** (2026-08-19) | Funcionalidade | M/G | 4 |
| ~~[#2](https://github.com/leaoereno/module-zbx-plantonistas/issues/2)~~ | ~~"Fechar turno" (`shift_reports` é tabela morta)~~ — **feito** (2026-08-19) | Funcionalidade | M/G | 5 |
| ~~[#6](https://github.com/leaoereno/module-zbx-plantonistas/issues/6)~~ | ~~Menção só notifica dentro da tela do Repasse~~ — **feito** (2026-08-19) | Funcionalidade | M | 5 |

### Por que essa ordem

A issue #1 não é uma issue só — o **passo 1 dela (sair do `mysqli`, usar
`DBselect()`/`DBexecute()` do Zabbix) é infraestrutura que todo o resto usa.**
Fazer esse passo primeiro:

- destrava os passos 3 e 6 da própria #1 (metade do dialeto some junto com o driver);
- muda o arquivo central da #4 (`cron_presence_tracker.php`) **antes** de reescrevê-lo;
- evita reescrever duas vezes o `TurnosReportBase::doAction()`, que é a base das #2 e #6;
- fecha de brinde 3 itens do backlog do CLAUDE.md (conexão reconectando a cada
  request, `prepare()` fora do try, `getUserRoleType()` duplicado).

Fazer #2 ou #6 antes disso significa escrever código novo em `mysqli` e migrá-lo
logo depois. Por isso as funcionalidades ficam para o fim.

---

## Fase 1 — Fundação: matar o `mysqli` (issue #1, passo 1) — **CONCLUÍDA em 2026-08-19**

Resolvida por um **adaptador fino** (`actions/ZbxDb.php` + `ZbxDbStmt.php` +
`ZbxDbResult.php`) em vez da reescrita consulta a consulta: as ~50 consultas
continuam com `?` + `bind_param`, mas rodam sobre `\DBselect`/`\DBexecute`/
`\DBfetch`. O escape ficou num ponto único, testável, em vez de 50
interpolações manuais — cada uma delas uma chance de SQL injection.

Resultado: nenhuma conexão mysqli no frontend, nenhum uso de `MYSQLI_ASSOC`,
e o `mysqli_report()` global (que vazava para a conexão nativa do Zabbix)
eliminado. Decisões e as três diferenças de comportamento que o adaptador teve
que compensar estão no `CLAUDE.md`.

Pendente de campo: validar as 6 telas no lab — especialmente Repasse (notas,
presença, menções) e Gerenciar Turnos, que são as que mais consultam.

<details>
<summary>Plano original (mantido para referência)</summary>

**Objetivo:** zero mudança de comportamento visível. É refactor puro.

Hoje 12 arquivos abrem conexão própria:

```
actions/TurnosReportBase.php      actions/TurnosNotesGet.php
actions/TurnosReportView.php      actions/TurnosNotesSave.php
actions/TurnosReportPdf.php       actions/TurnosShiftsView.php
actions/TurnosMentionsSearch.php  actions/TurnosShiftsSave.php
actions/TurnosMentionsRead.php    actions/TurnosShiftsDelete.php
actions/TurnosUserShiftSave.php   scripts/cron_presence_tracker.php
```

O cron fica de fora desta fase (roda em CLI, sem `$GLOBALS['DB']` — vai na Fase 2/3).

### PRs sugeridos

1. **PR 1.1 — `TurnosReportBase` para `\DBselect`/`\DBexecute`.** É o arquivo maior
   e o que os outros herdam. Trocar `prepare()`/`bind_param()` por `zbx_dbstr()`
   com escape explícito. Manter `error_log('[plantonistas] ...')` em todo catch.
2. **PR 1.2 — actions de turnos** (`ShiftsView/Save/Delete`, `UserShiftSave`).
3. **PR 1.3 — actions de notas e menções** (`NotesSave`, `NotesGet`,
   `MentionsSearch`, `MentionsRead`), aproveitando para deduplicar
   `getUserRoleType()`/`resolveUserContext()` no trait.
4. **PR 1.4 — `TurnosReportPdf` e `TurnosReportView`**, e remoção do `getDb()`.

### Riscos e cuidados

- `getDb()` liga `MYSQLI_REPORT_STRICT`; `\DBselect` **não lança** — retorna
  `false`. Todo ponto que hoje depende de exceção precisa passar a checar retorno,
  senão o erro volta a ser silencioso (exatamente o que a regra do CLAUDE.md proíbe).
- Sem `bind_param`, toda interpolação vira `zbx_dbstr()` / cast `(int)`.
  Revisar 100% dos pontos — é aqui que se introduz SQL injection sem querer.
- A regra "informação acessória não entra por JOIN" continua valendo
  (`attachUserShift()` não vira JOIN durante o refactor).

### Critério de saída

As 6 telas validadas no lab-zbx + `grep -r mysqli actions/` sem resultado.

</details>

---

## Fase 2 — Segurança (issues #3 e #4) — **CONCLUÍDA em 2026-08-19**

As duas foram feitas antes da Fase 1, por serem as mais rápidas e por não
dependerem do refactor. Detalhe completo das decisões no `CLAUDE.md`
("Cron de presença sem API do Zabbix" e "CSRF ligado nas actions de escrita").

### #4 — Cron de presença sem API ✅

- [x] API JSON-RPC substituída por consulta local em `users`/`usrgrp`/`users_groups`.
      Saíram o `CURLOPT_SSL_VERIFYPEER => false`, o `ZABBIX_API_TOKEN`, o
      `ZABBIX_URL`, o `curl` e a dependência do endpoint.
- [x] `CREATE TABLE IF NOT EXISTS` removido do cron; `migrateColumns()` passou a
      consertar o que o cron antigo criou (coluna `ip`, `id INT` → `BIGINT
      UNSIGNED`, índices `idx_cus_*`).
- [x] `install.sh` não existe mais no repo — item de backlog reescrito.
- [x] Bônus: três defeitos antigos corrigidos (fuso PHP × banco duplicando
      presença à noite, nome em branco de conta de serviço, nome longo
      descartando a linha).

Pendente de campo: rodar o script à mão no lab e conferir a tela de Presença;
tirar `ZABBIX_URL`/`ZABBIX_API_TOKEN` do `/etc/cron.d/` e revogar o token.

### #3 — CSRF nas actions de escrita ✅

Cobriu **10** actions, não 8: entraram também `plantonistas.import` e
`plantonistas.report.mentions.read`, que a issue não listava mas escrevem.

- [x] Token nos forms de Escala, Telefones, Turnos e Repasse (um por action —
      em módulo o Zabbix valida contra a action completa, não contra o prefixo).
- [x] `disableCsrfValidation()` removido só das 10 de escrita; as 11 de leitura
      mantêm, porque em GET a validação **falha** em vez de ser dispensada.
- [x] "Remover" da Escala deixou de ser link GET e virou POST em form oculto.
- [x] Guarda de `content-type` no JS das telas AJAX — falha de CSRF responde
      HTML, não JSON, e sem isso o erro é indecifrável.

Pendente de campo: validar as 6 telas, exercitando salvar, remover, importar e
escrever nota em cada uma.

---

## Fase 3 — PostgreSQL de fato: v5.0 (issue #1, passos 2 a 7)

**Passo 3 (armadilhas semânticas) — FEITO em 2026-08-19.** Antes de traduzir
sintaxe, foram corrigidos os pontos que rodariam nos dois bancos devolvendo
resultado **diferente** — os únicos que não seriam pegos por um teste no lab
PG, porque não geram erro. Todos continuam corretos no MySQL, então já valem
em produção hoje. Detalhe no `CLAUDE.md`, seção "PostgreSQL: as armadilhas
semânticas primeiro". Junto foram feitos o passo 0 (`Module::isPgsql()`) e o
passo 4 da introspecção (`STATISTICS` → `pg_indexes`, `DATABASE()` →
`current_schema()`, `COLUMN_TYPE` → `DATA_TYPE`).

**O que falta**, na ordem sugerida pelo levantamento:

| Passo | Trabalho | Observação |
|---|---|---|
| 1 | DDL das 9 tabelas | Hoje **duplicado** em `Module::tableDdl()` e `sql/schema.sql`. O certo é gerar os dois de uma descrição única, senão a divergência MySQL/PG vira permanente. Decisões a travar: `TINYINT(1)`→`SMALLINT` (não `BOOLEAN`, que quebraria 10 comparações `= 1`), `BIGINT UNSIGNED`→`BIGINT`, `AUTO_INCREMENT`→identity, `KEY` inline→`CREATE INDEX`, `ON UPDATE CURRENT_TIMESTAMP`→trigger |
| 2 | `migrateColumns()` | `AFTER`, `MODIFY COLUMN`, `ADD/DROP INDEX`. Metade das guardas existe para consertar o que o antigo CREATE TABLE do cron deixou — artefato histórico só de MySQL, vale isolar atrás de `if (!isPgsql())` em vez de traduzir |
| 4 | Data/hora | `DATE_FORMAT` (14×), `FROM_UNIXTIME` (5×), `TIMESTAMPDIFF` (3×), `CURDATE`, `INTERVAL`. **Decidir o fuso junto**: `to_timestamp()` usa o TimeZone da sessão PG, e fuso já causou erro de um dia três vezes neste módulo |
| 5 | Escrita | `ON DUPLICATE KEY UPDATE` → `ON CONFLICT` (2×) e `LAST_INSERT_ID()` → `lastval()` no `ZbxDb` |
| ~~7~~ | ~~Os 2 crons~~ — **feito**: `scripts/CliDb.php` (PDO), dialeto pela env `DB_TYPE` | |
| 8 | `sql/queries.sql` | ✅ Dividido em `queries.mysql.sql` e `queries.pgsql.sql` |

Duas armadilhas registradas que ainda não morderam: o parser de `?` do
`ZbxDbStmt` trata `\` como escape dentro de literal, o que não vale no PG com
`standard_conforming_strings` (nenhuma consulta atual tem `\` em literal, mas
uma futura desalinharia todos os placeholders); e o `ZbxDbStmt::execute()`
roteia por `^(SELECT|SHOW|...)`, então um `INSERT ... RETURNING` — a saída
natural para o `insert_id` no PG — cairia no `DBexecute` e perderia o
resultado.

<details>
<summary>Plano original</summary>

Só começa com a Fase 1 fechada.

| Passo | Trabalho |
|---|---|
| 2 | `sql/schema.mysql.sql` + `sql/schema.pgsql.sql`; `init()` escolhe por `$DB['TYPE']`. Sem `UNSIGNED`, `AUTO_INCREMENT` → `BIGSERIAL`, sem `ENGINE`/`CHARSET` |
| 3 | `IFNULL`→`COALESCE`, `DATE_FORMAT`→`to_char`, `FROM_UNIXTIME`→`to_timestamp`, `ON DUPLICATE KEY UPDATE`→`ON CONFLICT ... DO UPDATE`, crases fora |
| 4 | `INFORMATION_SCHEMA.STATISTICS` → `pg_indexes`. `TABLES`/`COLUMNS` já é padrão SQL |
| 5 | `RENAME TABLE a TO b` → `ALTER TABLE a RENAME TO b` (migração e rollback) |
| 6 | Cron em PDO com DSN a partir de `DB_TYPE` no arquivo de cron |
| 7 | Homologação em lab PostgreSQL |

### Achados do levantamento (concreto, para não redescobrir)

Depois da Fase 1, sobra dialeto **também na família escala**, que a issue não
detalha — são 7 pontos, todos triviais:

```
actions/PlantaoDelete.php:44     DATE_FORMAT(s.schedule_date,'%Y-%m-%d')
actions/PlantaoExport.php:57     DATE_FORMAT(s.schedule_date,'%Y-%m-%d')
actions/PlantaoList.php:109      DATE_FORMAT(s.schedule_date,'%Y-%m-%d')
actions/PlantaoList.php:113      IFNULL(s.userid_reserva,'')
actions/PlantaoOverview.php:68   IFNULL(s.userid_reserva,0)
actions/PhonesImport.php:148     ON DUPLICATE KEY UPDATE
actions/TurnosReportBase.php     10 ocorrências (as pt-BR de exibição)
```

Detalhe útil: `schedule_date` já é `DATE`. Os três `DATE_FORMAT(...,'%Y-%m-%d')`
são só cast para string — dá para trocar por `CAST(... AS CHAR)`/`::text` ou
formatar em PHP, resolvendo os dois bancos sem função de dialeto nenhuma.

Já os `DATE_FORMAT(..., '%d/%m/%Y %H:%i')` do `TurnosReportBase` são exibição
pt-BR e **precisam** virar `to_char(..., 'DD/MM/YYYY HH24:MI')` no PG — mantendo
a coluna auxiliar de ordenação com o valor cru (auditoria de 2026-08-14).

### Estratégia sugerida para o dialeto

Um helper único no trait (ex.: `sqlDateFormat()`, `sqlIfNull()`, `sqlUpsert()`)
que decide por `$DB['TYPE']`, em vez de `if` espalhado pelas queries. Facilita a
próxima auditoria e evita que uma query nova nasça só-MySQL.

</details>

### Critério de saída da v5.0

Lab PostgreSQL: as 6 telas abertas, um ciclo completo do cron de presença,
importação e exportação de CSV (Escala e Telefones), e a migração das tabelas
antigas testada nos dois bancos. Só então bump para `5.0.0` no manifest e README.

---

## Fase 4 — Escala vira operacional (issue #5) — **CONCLUÍDA em 2026-08-19**

`scripts/cron_sync_oncall.php`, no padrão já validado pelo presence tracker (e
sem API, porque a Fase 2 removeu essa dependência).

Decisões batidas com o Rafael:

- [x] **Um grupo por equipe** (`Plantonista de Hoje - NOC`) — cada equipe cuida
      de hosts diferentes e precisa de Ação própria.
- [x] **5 minutos.**
- [x] **Dia sem cobertura mantém quem está no grupo** — esvaziar deixaria alerta
      sem destinatário de madrugada.
- [x] Um frontend só.

Decisões técnicas tomadas no caminho (detalhe no `CLAUDE.md`): só o titular
entra; o script nunca cria grupo; `users_groups.id` é reservado pela tabela
`ids` como o Zabbix faz; INSERT antes do DELETE para o grupo nunca ficar vazio
na virada; grupo de destino desabilitado é recusado, porque incluir o
plantonista ali desabilitaria a conta dele no Zabbix inteiro.

Pendente de campo: criar os grupos na UI, agendar o cron e rodar com
`DRY_RUN=1` antes de valer. Passo a passo no README.

---

## Fase 5 — Funcionalidades sobre a base nova (issues #2 e #6)

### #2 — Fechar turno ✅ **feito em 2026-08-19**

Implementado como **documento separado** (decisão do Rafael): o botão grava o
snapshot e o PDF o renderiza via `report_id`; a tela segue consultando ao vivo.
A leitura exige que os grupos do autor caibam nos do leitor, e o MTTA é
reaplicado com o papel do LEITOR — a restrição de MTTA é por papel, e nenhum
filtro de grupo a cobre. Detalhe no `CLAUDE.md`.

Pendente de campo: conferir `SHOW CREATE TABLE module_plantonistas_shift_reports`
em produção (a tabela nunca teve linha escrita; se `report_json` estiver como
`TEXT`, o `migrateColumns()` promove para `LONGTEXT` no primeiro request) e
confirmar `php -v` nos dois frontends — o piso do módulo é PHP 8.0.

<details>
<summary>Plano original</summary>

Entrega três coisas de uma vez: auditoria (repasse imutável, imune ao housekeeper),
performance (1 leitura de JSON no lugar de ~8 queries contra `events`) e diff entre
turnos. Depende do payload do `TurnosReportBase::doAction()` já migrado.

Pontos: nova action `plantonistas.report.close` (AJAX, `layout.javascript` +
`view: null`); `TurnosReportView` checa snapshot de `(data, turno, grupo)` antes
de rodar as queries; reabrir exige Admin+ e fica registrado.

#### Questão em aberto: o snapshot congela a visão de UMA pessoa

O `$data_pack` que a issue propõe serializar **não é o mesmo para todo mundo**.
Ele nasce de `resolveUserContext()` e varia em três eixos que o módulo inteiro
foi construído para respeitar: eventos e Top Hosts seguem o `host_filter`
derivado da tabela `rights`; MTTA é restrito ao próprio usuário quando role
type = 1; Notas e Presença são segmentadas por grupo compartilhado.

Servir a um segundo usuário o JSON gravado por um primeiro **fura essa
segmentação** — um User veria o MTTA de todos e as notas de grupos a que não
pertence, porque quem fechou o turno foi um Super Admin. É a única parte da
issue que não é só trabalho: é uma decisão de produto. Três saídas:

1. **Snapshot por contexto**: chavear por grupo e só servir a quem pertence ao
   mesmo grupo E tem o mesmo role type. Preserva a regra, mas multiplica
   snapshots e complica o "diff entre turnos".
2. **Snapshot como documento separado**: fechar gera um registro imutável
   consultável/exportável (o repasse formal do turno), e a tela continua
   consultando ao vivo. Elimina o risco e entrega auditoria e diff — mas perde
   o ganho de performance, que era um dos três motivos da issue.
3. **Snapshot só para Admin+**: quem fecha e quem lê são Admin ou Super Admin;
   User continua sempre na consulta ao vivo. Simples, mas User é justamente
   quem mais abre o Repasse.

Decidir isso antes de codar — refazer depois significa migrar dados já gravados.

*(Decidido: opção 2, documento separado.)*

</details>

### #6 — Menção via media type ✅ **feito em 2026-08-19**

Implementado por `alert.send`, com token de Super Admin na env
`PLANTONISTAS_ALERT_TOKEN` e a janela de notificação replicada em PHP,
avaliada no fuso do destinatário. Sem token, não notifica e nada quebra.
Detalhe e as armadilhas de webhook no `CLAUDE.md`.

O envio saiu do save da nota e virou **fila por cron**
(`scripts/cron_notify_mentions.php` + `scripts/ZbxServerClient.php`); o
`actions/ZbxAlertSender.php` da primeira versão foi removido.

Pendente de campo: gerar o token, agendar o cron em UM frontend (com o módulo
já aberto uma vez, para a coluna `notified_at` existir) e configurar a URL do
frontend em Administração → Geral (sem ela o link do e-mail vai relativo, de
propósito).

<details>
<summary>Plano original e levantamento</summary>

Enfileirar o alerta no mesmo ponto em que `recordMentions()` grava a linha.
Falha no envio **não pode derrubar o save da nota** — mesma decisão já tomada
para menção inválida. Respeitar a janela de notificação do usuário (não mandar
e-mail às 4h para quem só recebe em horário comercial).

A mecânica serve depois para "amanhã é seu plantão" / "seu turno começa em 1h",
o que a torna mais barata se feita **depois** da #5.

#### Levantamento de 2026-08-19: como notificar, de fato

A nota que circulava aqui — "a tabela `alerts` não pode ser usada por módulo,
o jeito é `exec()` + `curl` no relay SMTP" — está **errada nas duas metades**.
Conferido no fonte do Zabbix 7.0:

- **Inserir em `alerts` realmente não serve**, mas não pelo motivo que se
  dizia. `userid` e `mediatypeid` são anuláveis; quem inviabiliza são
  `actionid` e `eventid`, **NOT NULL com FK ON DELETE CASCADE** — seria preciso
  "emprestar" uma Ação e um evento reais, que o housekeeper apaga um dia,
  levando a linha junto. Pior: o `alertid` é alocado por um **contador em
  memória do server**, não pela tabela `ids` que o frontend usa — dois
  alocadores para a mesma coluna, a mesma classe de incidente do
  `role_rule`/`fcorr`.
- **Existe via oficial frontend→server**: o request de socket `alert.send`,
  exposto por `CZabbixServer::testMediaType()` — é o que o botão "Test" de
  Alertas → Tipos de mídia usa. Não grava em `alerts`, não cria task, é
  síncrono e devolve erro e log de debug. Um módulo pode instanciar
  `\CZabbixServer`.
- Não existe task de "enviar mensagem" (`ZBX_TM_TASK_*` não tem nada disso) e a
  API JSON-RPC só expõe `alert.get` — não há `alert.create`.

**Duas consequências que mudam o escopo da issue:**

1. **`alert.send` exige Super Admin**, validado no *server*
   (`trapper_process_alert_send()`), aceitando session id de 32 chars ou token
   de API de 64. Ou seja: a issue passa a exigir guardar um **token de Super
   Admin** na configuração do módulo — logo depois de a issue #4 ter removido
   exatamente um token de API de circulação. É decisão do Rafael, não técnica.
2. **A janela de notificação não vem de graça.** Quem avalia `media.period`,
   `severity` e `active` é o escalonador, ao criar a linha em `alerts`
   (`add_message_alert()`); o consumidor não reavalia, e o `alert.send` recebe
   `sendto`/`mediatypeid` prontos. Indo por essa via, o módulo tem que
   replicar a regra de período em PHP.

A alternativa sem token é gerar um evento de verdade (item trapper via
`history.push` + trigger + Ação) e deixar o escalonador fazer tudo — aí
período, severidade e escalonamento funcionam sozinhos, ao custo de a menção
virar "problema" na tela de Problemas e de configurar item/trigger/Ação.

*(Decidido: token de Super Admin.)*

</details>

---

## Regras que valem em todas as fases

- Testar no **lab-zbx** antes de produção; validar as 6 telas após qualquer deploy.
- Deploy nos **dois** frontends: `git pull` + `chown -R apache:apache .` +
  `systemctl restart php-fpm`.
- Migrações idempotentes no `migrateSchema()`; nunca DROP de tabela com dados.
- Nunca engolir exceção de DB: `error_log('[plantonistas] ...')` + UI distinguindo
  "vazio de verdade" de "consulta falhou".
- Commits em PT-BR (`fix:`/`feat:`/`docs:`), corpo explicando o porquê.
- Ao fechar cada issue, registrar a decisão e o porquê no `CLAUDE.md` — é o que
  evita redescobrir a mesma causa raiz daqui a três meses.
