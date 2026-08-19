# module-zbx-plantonistas

Módulo único de gestão de plantonistas para Zabbix 7.0 LTS. Unifica os antigos
`module-zbx-escala-plantao` (v3.0.1) e `module-zbx-repasse-plantao` (v2.5.0)
em um só módulo, menu e repositório.

**Versão:** 4.0.0 · **Autor:** Rafael M. A. Leão Ereno (MALE)
Forks de origem: [pandradee/zabbix-escala-de-plantao](https://github.com/pandradee/zabbix-escala-de-plantao) e [JohnnyIver/zabbix-report-module](https://github.com/JohnnyIver/zabbix-report-module)

## ⚠️ Banco de dados: MySQL / MariaDB (por enquanto)

**O módulo funciona — e é homologado em produção — apenas com backend
MySQL 5.7+ / MySQL 8.0 / MariaDB 10.x.** Em Zabbix rodando sobre
**PostgreSQL o módulo NÃO funciona hoje** (as telas vão falhar na criação
das tabelas e nas consultas do Repasse). Suporte a PostgreSQL está no
roadmap — ver [Roadmap](#roadmap).

Não é limitação do Zabbix, é do módulo: ele fala MySQL direto em quatro
pontos.

| O que | Onde | Por que trava no PostgreSQL |
|---|---|---|
| DDL com `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`, `AUTO_INCREMENT`, `BIGINT UNSIGNED` | `Module.php` (init/provisionamento), `sql/schema.sql` | Sintaxe inexistente no PG (`BIGSERIAL`/`GENERATED AS IDENTITY`, sem `UNSIGNED`, sem engine/charset por tabela) |
| `RENAME TABLE a TO b` na migração dos módulos antigos | `Module.php`, `sql/migrate-*.sql`, `sql/rollback-*.sql` | No PG é `ALTER TABLE ... RENAME TO` |
| Introspecção por `INFORMATION_SCHEMA.STATISTICS` (checagem de índices) | `Module.php`, `scripts/cron_presence_tracker.php` | `STATISTICS` é exclusiva do MySQL; no PG seria `pg_indexes` / `pg_class` |
| Conexão `mysqli` própria (fora do `DBselect`/`DBexecute` do Zabbix) + `ON DUPLICATE KEY UPDATE`, `DATE_FORMAT()`, `IFNULL()`, `FROM_UNIXTIME()`, crases | `actions/TurnosReportBase.php`, `TurnosNotesGet.php`, `TurnosNotesSave.php`, `scripts/cron_presence_tracker.php`, `actions/PhonesImport.php` | Driver e dialeto diferentes: `ON CONFLICT`, `to_char()`, `COALESCE()`, `to_timestamp()`, aspas duplas |

Resumindo: **se o seu Zabbix está em MySQL/MariaDB, pode instalar. Se está em
PostgreSQL, aguarde a v5.**

## Telas

Tudo fica no menu **Plantão** (após Reports):

| Item de menu | Action | O que faz |
|---|---|---|
| Item de menu | Action | Perfil mínimo | O que faz |
|---|---|---|---|
| Visão Geral | `plantonistas.overview` | User | Quem está de plantão hoje, por grupo (por turno, se o grupo tiver turnos cadastrados) |
| Escala | `plantonistas.list` | Admin | Calendário mensal; escalar por turno (se o grupo tiver turnos em "Gerenciar Turnos") ou titular/reserva único (grupos sem turno); import/export CSV-XLSX (sempre no modo titular/reserva, mesmo em grupos com turnos) |
| Histórico | `plantonistas.history` | Admin | Log de alterações da escala |
| Telefones | `plantonistas.phones.list` | Admin | Telefone de contato por usuário, com máscara brasileira; export CSV e importação em massa (`plantonistas.phones.import`) |
| Repasse Plantão | `plantonistas.report.view` | User | Relatório de repasse NOC (eventos, MTTA, presença, diário de bordo com editor rico e menções `@`usuário/`_h`host/`_hg`grupo de hosts, PDF) |
| Gerenciar Turnos | `plantonistas.shifts.view` | Admin | Turnos por equipe + vínculo analista→turno |

Usuário do tipo **User (1)** enxerga apenas **Visão Geral** e **Repasse
Plantão**; as demais telas são **Admin (2)+**, bloqueadas tanto no menu
quanto no `checkPermissions()` de cada action (esconder o item do menu não
basta — a action continua acessível pela URL). Guest não vê o menu.

A segmentação por equipe continua a mesma dos módulos antigos: grupos de
usuário do Zabbix (`users_groups`), e `rights` para visibilidade de eventos
no Repasse.

## De-para completo (v4 renomeou tudo)

**Actions / URLs** — links salvos com os nomes antigos param de funcionar:

| Antiga | Nova |
|---|---|
| `plantao.overview` | `plantonistas.overview` |
| `plantao.list` | `plantonistas.list` |
| `plantao.save` / `plantao.delete` | `plantonistas.save` / `plantonistas.delete` |
| `plantao.history` | `plantonistas.history` |
| `plantao.export` / `plantao.import` | `plantonistas.export` / `plantonistas.import` |
| `phones.list` / `phones.export` / `phones.save` | `plantonistas.phones.list` / `.export` / `.save` |
| `turnos.report.view` | `plantonistas.report.view` |
| `turnos.report.notes.save` / `.get` | `plantonistas.report.notes.save` / `.get` |
| `turnos.report.pdf` | `plantonistas.report.pdf` |
| `turnos.shifts.view` / `.save` / `.delete` | `plantonistas.shifts.view` / `.save` / `.delete` |
| `turnos.usershift.save` | `plantonistas.usershift.save` |

**Tabelas** — renomeadas automaticamente pelo módulo (RENAME TABLE, atômico,
sem cópia de dados; dados 100% preservados):

| Antiga | Nova |
|---|---|
| `module_plantao_phones` | `module_plantonistas_phones` |
| `module_plantao_schedule` | `module_plantonistas_schedule` |
| `module_plantao_history` | `module_plantonistas_history` |
| `custom_shifts` | `module_plantonistas_shifts` |
| `custom_user_shift` | `module_plantonistas_user_shift` |
| `custom_shift_notes` | `module_plantonistas_shift_notes` |
| `custom_user_sessions` | `module_plantonistas_user_sessions` |
| `custom_shift_reports` | `module_plantonistas_shift_reports` |

## Migração em produção (frontends lnxdczbxfront01/02 atrás do F5)

Faça na janela de menor movimento. Passo a passo:

**0. Backup** — dumpa só as tabelas que existirem (nem todas existem em todo
ambiente: se o schema v2.5 do repasse nunca rodou, `custom_shifts` e
`custom_user_shift` não estão lá — o módulo novo cria as que faltarem).
Ajuste `-h` para o host do banco; pede a senha duas vezes:

```bash
TABS=$(mysql -hHOST_DB -uzabbix -p -N zabbix -e "SELECT GROUP_CONCAT(TABLE_NAME SEPARATOR ' ') FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='zabbix' AND TABLE_NAME IN ('module_plantao_phones','module_plantao_schedule','module_plantao_history','custom_shifts','custom_user_shift','custom_shift_notes','custom_user_sessions','custom_shift_reports')") && \
mysqldump -hHOST_DB -uzabbix -p --single-transaction --no-tablespaces --set-gtid-purged=OFF zabbix $TABS \
  > backup-pre-plantonistas-$(date +%Y%m%d-%H%M).sql
```

`--no-tablespaces` evita o erro de privilégio PROCESS; `--single-transaction`
garante dump consistente sem travar as tabelas.

**1. Deploy do módulo novo — NOS DOIS frontends:**

```bash
cd /usr/share/zabbix/modules
git clone https://github.com/leaoereno/module-zbx-plantonistas.git
chown -R apache:apache module-zbx-plantonistas
systemctl restart php-fpm
```

Não remova os módulos antigos ainda — eles são o rollback instantâneo.

**2. Troca no Zabbix UI** (Administration → General → Modules):

1. **Scan directory** — "Plantonistas" aparece desabilitado.
2. **Desabilite** "Escala de Plantão" e "Relatório Repasse de Plantão"
   (obrigatório antes de habilitar o novo — os três não podem coexistir
   habilitados: o menu Plantão duplicaria).
3. **Habilite** "Plantonistas". Na primeira carga de página o módulo
   renomeia as 8 tabelas antigas sozinho (idempotente; se algo falhar,
   sai no log do PHP-FPM com prefixo `[plantonistas]`).

**3. Roles** (Administration → User roles): para cada role que usava os
módulos antigos, confira na seção Modules se "Plantonistas" está permitido.
Roles com "Default access to new modules" desmarcado precisam de liberação
manual.

> ⚠️ Se salvar o papel falhar com **"Não é possível atualizar a função do
> usuário"** e `Duplicate entry '<N>' for key 'role_rule.PRIMARY'`, o
> problema não é o módulo: é a tabela `ids` do Zabbix atrasada em relação ao
> `MAX(role_ruleid)` (acontece quando alguém insere `role_rule` por SQL
> direto sem atualizar `ids`). Correção no banco:
>
> ```sql
> SELECT MAX(role_ruleid) FROM role_rule;
> SELECT * FROM ids WHERE table_name = 'role_rule';
>
> UPDATE ids SET nextid = (SELECT MAX(role_ruleid) FROM role_rule)
>  WHERE table_name = 'role_rule' AND field_name = 'role_ruleid';
> ```

**4. Crontab do presence tracker** — o caminho mudou. Onde estiver o cron
antigo (ex.: `/etc/cron.d/turnos-presence`), o jeito mais seguro é derivar do
arquivo existente, sem redigitar credencial:

```bash
sed 's|module-zbx-repasse-plantao|module-zbx-plantonistas|g' \
  /etc/cron.d/<arquivo-antigo> > /etc/cron.d/plantonistas-presence
chmod 644 /etc/cron.d/plantonistas-presence
```

Atenção a três coisas que já causaram tabela de presença vazia em produção:

- **Nome do arquivo em `/etc/cron.d/` não pode ter ponto** — o cron ignora
  `zbx-repasse-plantao.disabled` silenciosamente (é assim que se desativa).
- **As variáveis `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` são
  obrigatórias no arquivo de cron**: em CLI não existe `$GLOBALS['DB']`, e sem
  elas o script tenta `localhost`. São as **únicas** env necessárias — desde a
  v5 o script lê tudo do banco e não chama mais a API do Zabbix, então
  `ZABBIX_API_TOKEN` e `ZABBIX_URL` podem sair do arquivo de cron (e o token
  ser revogado no Zabbix).
- **Rodar em UM frontend só** — grava no banco compartilhado; nos dois
  duplica escrita sem ganho nenhum.

O script **não cria tabela**: se `module_plantonistas_user_sessions` não
existir, ele encerra pedindo que o módulo seja habilitado uma vez no frontend
(é o `Module::init()` que provisiona e migra o schema).

Teste manual antes de esperar o cron (as aspas simples do arquivo protegem o
`$` da senha):

```bash
set -a; source <(grep -E "^DB_" /etc/cron.d/plantonistas-presence); set +a
php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_presence_tracker.php
```

**5. Validação** — abrir cada tela uma vez (Visão Geral, Escala, Histórico,
Telefones, Repasse Plantão, Gerenciar Turnos), com `catch_workers_output = yes`
no pool e `tail -f` no log do PHP-FPM. Conferir se a escala do mês e as notas
do diário de bordo estão lá (estarão — as tabelas são as mesmas, só de nome
novo).

## Escalonamento: mandar o alerta para quem está de plantão

`scripts/cron_sync_oncall.php` mantém, para cada equipe, um grupo de usuários
do Zabbix contendo **só o plantonista do turno corrente**. A Ação nativa do
Zabbix escala para esse grupo — o módulo não reimplementa notificação.

**1. Criar os grupos**, um por equipe, em Usuários → Grupos de utilizadores.
O nome segue a convenção `Plantonista de Hoje - <nome da equipe>`:

| Equipe (grupo que aparece na Escala) | Grupo a criar |
|---|---|
| `NOC` | `Plantonista de Hoje - NOC` |
| `Redes` | `Plantonista de Hoje - Redes` |

Criar **vazio, com Status = Habilitado** e sem permissões de host. O cron
cuida dos membros. O prefixo pode ser trocado pela env `ONCALL_GROUP_PREFIX`
(o `" - "` é acrescentado se você não puser um separador).

⚠️ **O grupo precisa estar Habilitado.** O status de um usuário no Zabbix é
`MAX(users_status)` sobre todos os grupos dele: se este grupo estiver como
Desabilitado, incluir o plantonista nele **desabilita a conta dele** — ele
para de logar, some das telas do próprio módulo e deixa de ser notificado, que
é o oposto do que se quer. O script recusa o grupo nesse caso, com aviso no
log.

**O script nunca cria grupo** — criar exige reservar `usrgrpid` na tabela `ids`
do Zabbix, e escrever ID na mão em tabela do core já custou caro aqui (ver a
seção da tabela `ids` no CLAUDE.md).

**2. Agendar**, no mesmo frontend do rastreador de presença:

```bash
cat > /etc/cron.d/plantonistas-oncall <<'EOF'
DB_HOST=172.18.190.21
DB_NAME=zabbix
DB_USER=zabbix
DB_PASS=<senha>
*/5 * * * * apache php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_sync_oncall.php >> /var/log/plantonistas-oncall.log 2>&1
EOF
chmod 600 /etc/cron.d/plantonistas-oncall
```

`chmod 600` porque o arquivo tem senha. **Um frontend só**: em dois, duas
execuções simultâneas disputam a mesma reserva de ID.

**3. Conferir antes de valer**, sem gravar nada:

```bash
set -a; source <(grep -E "^DB_" /etc/cron.d/plantonistas-oncall); set +a
DRY_RUN=1 php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_sync_oncall.php
```

**4. Apontar a Ação** (Alertas → Ações) para o grupo `Plantonista de Hoje - …`
em vez da lista fixa de destinatários.

Dois comportamentos que valem saber:

- **Dia ou turno sem ninguém escalado não esvazia o grupo** — quem estava
  continua até alguém ser escalado. Esvaziar significaria alerta sem
  destinatário justamente de madrugada. Sai um `INFO:` no log toda vez.
- **Só o titular entra.** O reserva (que só existe em grupo sem turnos) fica
  de fora: reserva não está de plantão, está disponível.
- A virada de turno leva até 5 minutos para refletir no grupo, mais o config
  sync do Zabbix server (~1 min). E um escalonamento já em andamento
  re-resolve o grupo a cada passo, então o destinatário pode trocar no meio de
  uma escalada — é o comportamento nativo do Zabbix, não do módulo.
- O script sai com código **1** se alguma equipe falhou (grupo inexistente,
  plantonista removido do Zabbix, turnos ilegíveis). Dá para monitorar o cron
  por isso; o log traz `WARN:` com o motivo em cada caso.

**6. Limpeza** (alguns dias depois, com tudo estável) — nos dois frontends:

```bash
rm -rf /usr/share/zabbix/modules/module-zbx-escala-plantao
rm -rf /usr/share/zabbix/modules/module-zbx-repasse-plantao
```

Depois Scan directory de novo (os dois somem da lista). Limpeza opcional de
lixo no banco:

```sql
DELETE FROM role_rule WHERE value_str LIKE 'plantao.%'
   OR value_str LIKE 'phones.%' OR value_str LIKE 'turnos.%';
```

## Notificar menção do Diário de Bordo (opcional)

Quando alguém é mencionado com `@` numa nota, o módulo pode avisar pelo media
type que a pessoa já tem no Zabbix (e-mail, Telegram, webhook), além do banner
na tela. **É opcional**: sem a configuração abaixo, nada muda e nada quebra —
o banner continua funcionando.

A via usada é o request `alert.send` do Zabbix server, a mesma do botão "Test"
em Alertas → Tipos de mídia. Ela **exige Super Admin**, então precisa de um
token de API:

**1. Gerar o token** — Usuários → Tokens de API → Criar, com um usuário Super
Admin. Guarde o valor: ele só aparece uma vez.

**2. Colocar no pool do PHP-FPM**, nos dois frontends
(`/etc/php-fpm.d/zabbix.conf`):

```ini
env[PLANTONISTAS_ALERT_TOKEN] = "<token de 64 caracteres>"
```

Depois `systemctl restart php-fpm`. O arquivo tem credencial — confira as
permissões. Prefira `env[...]` no pool a `fastcgi_param`: como `fastcgi_param`,
o token aparece em `$_SERVER` e pode sair em dump de debug.

**3. Configurar a URL do frontend** em Administração → Geral → GUI. Sem ela o
link no corpo do e-mail vai **relativo**, de propósito: montar a URL a partir
do cabeçalho `Host` da requisição deixaria quem escreve a nota apontar o link
para um domínio próprio, e o colega receberia esse link pelo canal legítimo do
Zabbix.

O que o módulo respeita, por conta própria, porque o `alert.send` não respeita:
a mídia precisa estar **habilitada** e dentro do **período** configurado no
cadastro do usuário — avaliado no fuso horário **do destinatário**. A
severidade da mídia é ignorada (menção não tem severidade).

Limites conhecidos: o envio acontece durante o save da nota, com timeout curto
(2s/5s) e **teto de 5 envios por nota** — o que passar disso fica só no banner.
Media type do tipo "Script" não é suportado.

## Rollback

Enquanto as pastas antigas existirem nos frontends, o rollback é rápido:

1. Zabbix UI: desabilitar "Plantonistas" (primeiro! senão o init() dele
   desfaz o passo 2 na próxima carga de página).
2. Rodar `sql/rollback-to-old-modules.sql` (RENAMEs reversos, instantâneo,
   dados gravados durante a vigência do v4 são mantidos).
3. Zabbix UI: reabilitar os dois módulos antigos.
4. Restaurar a linha antiga do crontab.

## Instalação nova (lab / ambiente limpo)

Pré-requisito: Zabbix 7.0 LTS com backend **MySQL 5.7+ / 8.0 ou MariaDB
10.x**. PostgreSQL ainda não é suportado (ver [Roadmap](#roadmap)).

```bash
cd /usr/share/zabbix/modules
git clone https://github.com/leaoereno/module-zbx-plantonistas.git
chown -R apache:apache module-zbx-plantonistas   # Ubuntu: www-data
mysql -uzabbix -p zabbix < module-zbx-plantonistas/sql/schema.sql   # opcional: o módulo cria sozinho
```

Zabbix UI → Administration → General → Modules → Scan directory → habilitar
"Plantonistas". O `scripts/install.sh` interativo também funciona (Docker,
all-in-one ou segmentado) e pergunta se o banco é novo antes de rodar o
schema.

## Roadmap

**v5.0 — suporte a PostgreSQL.** O objetivo é o mesmo código rodar nos dois
backends, detectando o tipo de banco em tempo de execução (`$DB['TYPE']`, que
o Zabbix já expõe em `zabbix.conf.php`). O caminho planejado:

1. **Sair do `mysqli` e passar tudo pelo `DBselect()`/`DBexecute()` do
   Zabbix** — é a camada que já é agnóstica de banco. Hoje quatro arquivos
   abrem conexão própria; esse é o pré-requisito de todo o resto.
2. **Separar o DDL por dialeto** — `sql/schema.mysql.sql` e
   `sql/schema.pgsql.sql`, com o `init()` do `Module.php` escolhendo o
   conjunto certo. Sem `UNSIGNED`, `AUTO_INCREMENT` vira `BIGSERIAL`, sem
   `ENGINE`/`CHARSET`.
3. **Trocar as funções específicas de dialeto** por equivalentes ou por um
   pequeno helper: `IFNULL` → `COALESCE`, `DATE_FORMAT` → `to_char`,
   `FROM_UNIXTIME` → `to_timestamp`, `ON DUPLICATE KEY UPDATE` →
   `ON CONFLICT ... DO UPDATE`, crases → aspas duplas (ou nada).
4. **Reescrever a introspecção** — `INFORMATION_SCHEMA.STATISTICS` (índices)
   vira `pg_indexes` no PG; `INFORMATION_SCHEMA.TABLES`/`COLUMNS` já é padrão
   SQL e funciona nos dois.
5. **Migração/rollback em PostgreSQL** — `RENAME TABLE` vira
   `ALTER TABLE ... RENAME TO`. Sem custo de cópia de dados nos dois casos.
6. **`scripts/cron_presence_tracker.php`** — CLI, sem `$GLOBALS['DB']`; vai
   precisar de PDO com DSN montado a partir de `DB_TYPE` no arquivo de cron.
7. **Homologação em lab PostgreSQL** antes de qualquer release, com as seis
   telas abertas e o cron de presença rodando um ciclo completo.

Sem data fechada. Enquanto isso, o requisito de MySQL/MariaDB continua
valendo e está documentado no topo deste README.

## Notas de operação

- **F5 BIG-IP bloqueia `.js` estático.** Todo o JS das telas é inline nas
  views. Os arquivos em `assets/js/` são o Chart.js (usado só pelo Repasse;
  se o gráfico não carregar atrás do F5, é isso) e um stub exigido pelo
  manifest.
- **Sempre atualize e reinicie o php-fpm NOS DOIS frontends** — o F5 serve
  código velho de nó não atualizado de forma intermitente.
- `git pull` como root deixa arquivos com dono root: rode
  `chown -R apache:apache .` depois.
- Erro 500 sem rastro: habilite `catch_workers_output = yes` no pool
  (`/etc/php-fpm.d/zabbix.conf`) e acompanhe o log do FPM.
- O módulo não tem onInstall/onUninstall (o Zabbix nunca chama esses hooks);
  todo o provisionamento/migração é idempotente dentro do `init()`, e o
  módulo nunca dropa tabela com dados.
