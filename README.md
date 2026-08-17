# module-zbx-plantonistas

Módulo único de gestão de plantonistas para Zabbix 7.0 LTS. Unifica os antigos
`module-zbx-escala-plantao` (v3.0.1) e `module-zbx-repasse-plantao` (v2.5.0)
em um só módulo, menu e repositório.

**Versão:** 4.0.0 · **Autor:** Rafael M. A. Leão Ereno (MALE)
Forks de origem: [pandradee/zabbix-escala-de-plantao](https://github.com/pandradee/zabbix-escala-de-plantao) e [JohnnyIver/zabbix-report-module](https://github.com/JohnnyIver/zabbix-report-module)

## Telas

Tudo fica no menu **Plantão** (após Reports):

| Item de menu | Action | O que faz |
|---|---|---|
| Visão Geral | `plantonistas.overview` | Quem está de plantão hoje, por grupo (por turno, se o grupo tiver turnos cadastrados) |
| Escala | `plantonistas.list` | Calendário mensal; escalar por turno (se o grupo tiver turnos em "Gerenciar Turnos") ou titular/reserva único (grupos sem turno); import/export CSV-XLSX (sempre no modo titular/reserva, mesmo em grupos com turnos) |
| Histórico | `plantonistas.history` | Log de alterações da escala |
| Telefones | `plantonistas.phones.list` | Telefone de contato por usuário |
| Repasse Plantão | `plantonistas.report.view` | Relatório de repasse NOC (eventos, MTTA, presença, diário de bordo com editor rico e menções `@`usuário/`_h`host/`_hg`grupo de hosts, PDF) |
| Gerenciar Turnos | `plantonistas.shifts.view` | Turnos por equipe + vínculo analista→turno (só Admin/Super Admin) |

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
  elas o script tenta `localhost`. `ZABBIX_API_TOKEN` e `ZABBIX_URL` idem —
  `ZABBIX_URL` é o endpoint da API, não a URL do frontend.
- **Rodar em UM frontend só** — grava no banco compartilhado; nos dois
  duplica escrita e consumo da API.

Teste manual antes de esperar o cron (as aspas simples do arquivo protegem o
`$` da senha):

```bash
set -a; source <(grep -E "^(ZABBIX|DB)_" /etc/cron.d/plantonistas-presence); set +a
php /usr/share/zabbix/modules/module-zbx-plantonistas/scripts/cron_presence_tracker.php
```

**5. Validação** — abrir cada tela uma vez (Visão Geral, Escala, Histórico,
Telefones, Repasse Plantão, Gerenciar Turnos), com `catch_workers_output = yes`
no pool e `tail -f` no log do PHP-FPM. Conferir se a escala do mês e as notas
do diário de bordo estão lá (estarão — as tabelas são as mesmas, só de nome
novo).

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

## Rollback

Enquanto as pastas antigas existirem nos frontends, o rollback é rápido:

1. Zabbix UI: desabilitar "Plantonistas" (primeiro! senão o init() dele
   desfaz o passo 2 na próxima carga de página).
2. Rodar `sql/rollback-to-old-modules.sql` (RENAMEs reversos, instantâneo,
   dados gravados durante a vigência do v4 são mantidos).
3. Zabbix UI: reabilitar os dois módulos antigos.
4. Restaurar a linha antiga do crontab.

## Instalação nova (lab / ambiente limpo)

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
