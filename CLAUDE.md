# CLAUDE.md — module-zbx-plantonistas

Contexto, memória e instruções de trabalho deste projeto. Portável: serve como
knowledge de projeto no Claude.ai e como contexto de agente ao trabalhar neste
repositório. Atualizado em 2026-08-14.

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

### Estado do deploy em produção (2026-08-14)

Feito: repo GitHub criado; clone no **front02**; tabelas de produção verificadas.
Não confirmado/pendente: backup dump concluído; clone + chown + restart php-fpm
no **front01**; chown/restart no front02; Scan directory; desabilitar os 2
módulos antigos; habilitar Plantonistas; atualizar crontab do presence tracker;
conferir roles (Modules → Plantonistas); validar as 6 telas; remover pastas
antigas após estabilizar. Runbook completo no README.md.

Nota: o fix de UX também ficou não-commitado na working tree do repo antigo
`module-zbx-repasse-plantao` local — irrelevante após a unificação, mas o
histórico está no zip `module-zbx-repasse-plantao-commit-1dadacd.zip` se precisar.

### Backlog conhecido

- Salvar vínculo analista→turno em massa (hoje é um clique por analista).
- Chart.js estático vs F5 (embutir inline se os gráficos falharem em produção).
- Limpeza opcional de `role_rule` órfãs dos módulos antigos (SQL no README).
- Unificação visual das duas famílias (escala dark × repasse claro) — só com demanda.

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
  "vazio de verdade" de "consulta falhou".
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
