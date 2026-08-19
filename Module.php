<?php declare(strict_types = 1);

namespace Modules\Plantonistas;

use Zabbix\Core\CModule,
    APP,
    CMenuItem,
    CMenu,
    CWebUser;

/**
 * Module Plantonistas v4.0.0
 *
 * Unificação dos antigos module-zbx-escala-plantao (id "plantao") e
 * module-zbx-repasse-plantao (id "turnos-noc-report") em um módulo único.
 *
 * Migração de dados: na primeira carga com o módulo habilitado, init()
 * renomeia as tabelas antigas para os nomes novos via RENAME TABLE
 * (operação atômica de metadados no MariaDB — sem cópia de dados).
 * Tudo idempotente: rodar a cada request não tem efeito depois de migrado.
 *
 * Não existe hook onInstall/onUninstall no core do Zabbix (CModuleManager
 * só chama init()) — por isso o provisionamento inteiro vive aqui, e este
 * módulo NUNCA dropa tabela com dados.
 */
class Module extends CModule {

    private const TABLE_RENAMES = [
        'module_plantao_phones'   => 'module_plantonistas_phones',
        'module_plantao_schedule' => 'module_plantonistas_schedule',
        'module_plantao_history'  => 'module_plantonistas_history',
        'custom_shifts'           => 'module_plantonistas_shifts',
        'custom_user_shift'       => 'module_plantonistas_user_shift',
        'custom_shift_notes'      => 'module_plantonistas_shift_notes',
        'custom_user_sessions'    => 'module_plantonistas_user_sessions',
        'custom_shift_reports'    => 'module_plantonistas_shift_reports',
    ];

    public function init(): void {
        try {
            $this->migrateSchema();
        } catch (\Throwable $e) {
            // Nunca derrubar o frontend por causa de provisionamento.
            error_log('[plantonistas] migrateSchema falhou: ' . $e->getMessage());
        }

        $user_type = CWebUser::getType();

        if ($user_type < USER_TYPE_ZABBIX_USER) {
            return;
        }

        // User (1) enxerga apenas Visão Geral e Repasse Plantão.
        // Escala, Histórico, Telefones e Gerenciar Turnos são Admin (2)+.
        $is_admin = ($user_type >= USER_TYPE_ZABBIX_ADMIN);

        try {
            $submenu = (new CMenu())
                ->add((new CMenuItem(_('Visão Geral')))->setAction('plantonistas.overview'));

            if ($is_admin) {
                $submenu
                    ->add((new CMenuItem(_('Escala')))
                        ->setAction('plantonistas.list')
                        ->setAliases([
                            'plantonistas.save',
                            'plantonistas.delete',
                            'plantonistas.export',
                            'plantonistas.import',
                        ])
                    )
                    ->add((new CMenuItem(_('Histórico')))->setAction('plantonistas.history'))
                    ->add((new CMenuItem(_('Telefones')))
                        ->setAction('plantonistas.phones.list')
                        ->setAliases([
                            'plantonistas.phones.save',
                            'plantonistas.phones.export',
                            'plantonistas.phones.import',
                        ])
                    );
            }

            $submenu
                ->add((new CMenuItem(_('Repasse Plantão')))
                    ->setAction('plantonistas.report.view')
                    ->setAliases([
                        'plantonistas.report.notes.save',
                        'plantonistas.report.notes.get',
                        'plantonistas.report.mentions.search',
                        'plantonistas.report.mentions.read',
                        'plantonistas.report.pdf',
                    ])
                );

            // Gerenciar Turnos só para Admin (2) / Super Admin (3).
            if ($is_admin) {
                $submenu->add((new CMenuItem(_('Gerenciar Turnos')))
                    ->setAction('plantonistas.shifts.view')
                    ->setAliases([
                        'plantonistas.shifts.save',
                        'plantonistas.shifts.delete',
                        'plantonistas.usershift.save',
                    ])
                );
            }

            $menu = APP::Component()->get('menu.main');
            $menu->insertAfter(_('Reports'),
                (new CMenuItem(_('Plantão')))
                    ->setIcon('zi-calendar-check')
                    ->setSubMenu($submenu)
            );
        } catch (\Throwable $e) {
            // Contexto sem UI (CLI/setup) — segue sem menu.
        }
    }

    // ── Migração idempotente ────────────────────────────────────────────────

    private function migrateSchema(): void {
        $exists = $this->existingTables(array_merge(
            array_keys(self::TABLE_RENAMES),
            array_values(self::TABLE_RENAMES)
        ));

        // 1. Renomear tabelas dos módulos antigos (migração v3/v2.5 → v4).
        foreach (self::TABLE_RENAMES as $old => $new) {
            $hasOld = isset($exists[$old]);
            $hasNew = isset($exists[$new]);

            if ($hasOld && !$hasNew) {
                \DBexecute($this->renameTableSql($old, $new));
                $exists[$new] = true;
                unset($exists[$old]);
            }
            elseif ($hasOld && $hasNew) {
                // Cenário de schema rodado antes da migração: a tabela nova
                // existe vazia enquanto a antiga tem os dados. Só nesse caso a
                // nova (vazia) é descartada para o RENAME acontecer. Tabela com
                // qualquer linha NUNCA é dropada.
                $newHasRows = (bool) \DBfetch(\DBselect('SELECT 1 AS x FROM ' . $new . ' LIMIT 1'), false);
                if (!$newHasRows) {
                    \DBexecute('DROP TABLE ' . $new);
                    \DBexecute($this->renameTableSql($old, $new));
                    unset($exists[$old]);
                }
                else {
                    error_log('[plantonistas] AVISO: ' . $old . ' e ' . $new
                        . ' existem e ambas têm dados — resolução manual necessária.');
                }
            }
        }

        // 2. Criar o que ainda faltar (instalação nova / tabela nunca usada).
        //
        // O DDL vem do Schema, que descreve as tabelas uma vez e gera o
        // dialeto certo — antes estava escrito à mão aqui e de novo no
        // sql/schema.sql, em MySQL puro (issue #1). Cada tabela pode render
        // mais de um statement: no PostgreSQL, os índices e os comentários
        // são comandos separados.
        foreach (Schema::ddl($this->isPgsql()) as $table => $stmts) {
            if (isset($exists[$table])) {
                continue;
            }
            foreach ($stmts as $stmt) {
                \DBexecute($stmt);
            }
        }

        // 3. Migrações de coluna/índice (upgrades de versões antigas).
        $this->migrateColumns();
    }

    /**
     * O backend é PostgreSQL? (issue #1)
     *
     * O Zabbix expõe o tipo em $DB['TYPE'] a partir do zabbix.conf.php. É a
     * chave que decide dialeto — mantida num método só para não espalhar a
     * leitura da global pelo código.
     */
    private function isPgsql(): bool {
        // Delega ao SqlFn para haver UMA implementação: duas cópias da mesma
        // expressão só divergem quando alguém corrige uma delas.
        return Actions\SqlFn::isPgsql();
    }

    /**
     * Filtro de schema do INFORMATION_SCHEMA, por dialeto.
     *
     * `DATABASE()` é MySQL. No PostgreSQL o equivalente prático é
     * `current_schema()` — e a diferença conceitual importa: lá o
     * `table_schema` é o schema (normalmente `public`), não o banco.
     */
    private function schemaFilter(): string {
        return $this->isPgsql() ? 'current_schema()' : 'DATABASE()';
    }

    /**
     * `RENAME TABLE a TO b` é MySQL; o padrão é `ALTER TABLE a RENAME TO b`.
     *
     * As crases saíram junto: são identificador de MySQL. Como todos os nomes
     * do módulo são minúsculos e sem caractere especial, não precisam de
     * aspas em banco nenhum.
     */
    private function renameTableSql(string $old, string $new): string {
        return $this->isPgsql()
            ? "ALTER TABLE $old RENAME TO $new"
            : "RENAME TABLE $old TO $new";
    }

    /**
     * Adiciona coluna. O `AFTER x` do MySQL não existe no PostgreSQL, que não
     * ordena colunas — lá a coluna nova vai para o fim, e isso não afeta nada
     * (o módulo nunca faz `SELECT *` posicional).
     *
     * `COMMENT` inline também é MySQL; no PostgreSQL vira statement separado —
     * e ele É emitido, de propósito. O `Schema.php` já emite `COMMENT ON
     * COLUMN` numa instalação PG do zero; se a migração não emitisse, dois
     * ambientes PG acabariam com catálogos diferentes, que é exatamente a
     * divergência silenciosa que o Schema existe para evitar.
     *
     * @return string[] statements a executar em ordem
     */
    private function addColumnSql(string $table, string $col, string $tipoMysql,
                                  string $tipoPgsql, string $after = '',
                                  string $comment = ''): array {
        if ($this->isPgsql()) {
            $stmts = ["ALTER TABLE $table ADD COLUMN $col $tipoPgsql"];
            if ($comment !== '') {
                $stmts[] = "COMMENT ON COLUMN $table.$col IS '"
                    . str_replace("'", "''", $comment) . "'";
            }
            return $stmts;
        }

        $sql = "ALTER TABLE $table ADD COLUMN $col $tipoMysql";
        if ($comment !== '') {
            $sql .= " COMMENT '" . str_replace("'", "''", $comment) . "'";
        }
        if ($after !== '') {
            $sql .= " AFTER $after";
        }

        return [$sql];
    }

    /** Índice: `ALTER TABLE ... ADD INDEX` é MySQL; o padrão é `CREATE INDEX`. */
    private function addIndexSql(string $table, string $nome, string $cols): string {
        return $this->isPgsql()
            ? "CREATE INDEX IF NOT EXISTS $nome ON $table ($cols)"
            : "ALTER TABLE $table ADD INDEX $nome ($cols)";
    }

    /** Remoção de índice. No PostgreSQL o índice é objeto do schema, não da tabela. */
    private function dropIndexSql(string $table, string $nome): string {
        return $this->isPgsql()
            ? "DROP INDEX IF EXISTS $nome"
            : "ALTER TABLE $table DROP INDEX `$nome`";
    }

    /** Executa uma lista de statements em ordem. */
    private function execAll(array $stmts): void {
        foreach ($stmts as $stmt) {
            \DBexecute($stmt);
        }
    }

    /**
     * Troca o tipo de uma coluna existente.
     *
     * `MODIFY COLUMN nome TIPO NOT NULL` é MySQL — o padrão separa as duas
     * coisas: `ALTER COLUMN nome TYPE tipo` e `ALTER COLUMN nome SET NOT NULL`.
     * Por isso o retorno é uma LISTA de statements, não um só.
     *
     * @return string[] statements a executar em ordem
     */
    private function modifyTypeSql(string $table, string $col, string $tipo,
                                   bool $notNull = false): array {
        if (!$this->isPgsql()) {
            return ["ALTER TABLE $table MODIFY COLUMN $col $tipo" . ($notNull ? ' NOT NULL' : '')];
        }

        $stmts = ["ALTER TABLE $table ALTER COLUMN $col TYPE $tipo"];
        if ($notNull) {
            $stmts[] = "ALTER TABLE $table ALTER COLUMN $col SET NOT NULL";
        }

        return $stmts;
    }

    /**
     * Remove um UNIQUE.
     *
     * No PostgreSQL um UNIQUE criado como CONSTRAINT **não sai por DROP
     * INDEX**: o banco recusa com "cannot drop index ... because constraint
     * ... requires it". E como `\DBexecute()` não lança (devolve false), o
     * try/catch do `init()` não pegaria nada — o erro sairia como banner
     * vermelho do Zabbix, com o SQL inteiro, a CADA carga de página, porque a
     * migração nunca concluiria.
     *
     * Vale para `uniq_group_day`, que é criado pelo próprio módulo via
     * addUniqueSql(). Os outros três nomes dropados por aqui
     * (`schedule_date`, `uniq_group_week`, `uk_schedule_date`) são índices
     * legados do escala v1→v3, que só existem em MySQL.
     */
    private function dropUniqueSql(string $table, string $nome): string {
        return $this->isPgsql()
            ? "ALTER TABLE $table DROP CONSTRAINT IF EXISTS $nome"
            : "ALTER TABLE $table DROP INDEX `$nome`";
    }

    /** UNIQUE: `ADD UNIQUE KEY nome (...)` é MySQL; o padrão é CONSTRAINT. */
    private function addUniqueSql(string $table, string $nome, string $cols): string {
        return $this->isPgsql()
            ? "ALTER TABLE $table ADD CONSTRAINT $nome UNIQUE ($cols)"
            : "ALTER TABLE $table ADD UNIQUE KEY $nome ($cols)";
    }

    private function existingTables(array $names): array {
        $in  = "'" . implode("','", $names) . "'";
        $res = \DBselect(
            'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES' .
            ' WHERE TABLE_SCHEMA = ' . $this->schemaFilter() . ' AND TABLE_NAME IN (' . $in . ')'
        );
        $out = [];
        while ($row = \DBfetch($res)) {
            // Chaves normalizadas para minúsculo ANTES de qualquer uso: o
            // MySQL devolve TABLE_NAME e o PostgreSQL devolve table_name.
            // Ler direto em maiúsculo daria array vazio no PG — e o estrago
            // seria silencioso: existingTables() devolveria [], o RENAME das
            // tabelas antigas nunca aconteceria e migrateColumns() pularia
            // todas as migrações, sem erro e sem log, com as telas abrindo
            // normalmente.
            $row = array_change_key_case($row, CASE_LOWER);
            $out[$row['table_name']] = true;
        }
        return $out;
    }

    private function migrateColumns(): void {
        $tables = [
            'module_plantonistas_schedule',
            'module_plantonistas_history',
            'module_plantonistas_shift_notes',
            'module_plantonistas_user_sessions',
            'module_plantonistas_shift_reports',
        ];
        $in = "'" . implode("','", $tables) . "'";

        // Um SELECT só para todas as colunas relevantes.
        //
        // O tipo vem junto porque CHARACTER_MAXIMUM_LENGTH é NULL em coluna
        // numérica e não distingue INT de BIGINT. A coluna que traz o tipo
        // muda por dialeto: COLUMN_TYPE ('bigint(20) unsigned') é MySQL-only;
        // no PostgreSQL o padrão é DATA_TYPE ('bigint'), sem a noção de
        // unsigned — que lá não existe.
        $tipoCol = $this->isPgsql() ? 'DATA_TYPE' : 'COLUMN_TYPE';

        $cols  = [];
        $types = [];
        $res   = \DBselect(
            'SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, ' . $tipoCol . ' AS COL_TYPE' .
            ' FROM INFORMATION_SCHEMA.COLUMNS' .
            ' WHERE TABLE_SCHEMA = ' . $this->schemaFilter() . ' AND TABLE_NAME IN (' . $in . ')'
        );
        while ($row = \DBfetch($res)) {
            $row = array_change_key_case($row, CASE_LOWER);   // ver existingTables()
            $t = $row['table_name'];
            $c = strtolower($row['column_name']);
            $cols[$t][$c]  = $row['character_maximum_length'];
            $types[$t][$c] = strtolower((string)$row['col_type']);
        }

        // Um SELECT só para todos os índices relevantes.
        //
        // INFORMATION_SCHEMA.STATISTICS é exclusiva do MySQL — no PostgreSQL
        // a lista de índices vive em pg_indexes, com outros nomes de coluna.
        $idx = [];
        $res = $this->isPgsql()
            ? \DBselect(
                'SELECT tablename AS TABLE_NAME, indexname AS INDEX_NAME FROM pg_indexes' .
                ' WHERE schemaname = current_schema() AND tablename IN (' . $in . ')'
            )
            : \DBselect(
                'SELECT TABLE_NAME, INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS' .
                ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $in . ')'
            );
        while ($row = \DBfetch($res)) {
            $row = array_change_key_case($row, CASE_LOWER);
            $idx[$row['table_name']][$row['index_name']] = true;
        }

        // ── module_plantonistas_schedule (herdado do escala v1→v3) ──
        $t = 'module_plantonistas_schedule';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['usrgrpid'])) {
                $this->execAll($this->addColumnSql($t, 'usrgrpid', 'BIGINT NOT NULL DEFAULT 0', 'BIGINT NOT NULL DEFAULT 0', 'scheduleid'));
            }
            if (!isset($cols[$t]['userid_reserva'])) {
                $this->execAll($this->addColumnSql($t, 'userid_reserva', 'BIGINT NULL', 'BIGINT', 'userid'));
            }
            if (!isset($cols[$t]['shift_id'])) {
                $this->execAll($this->addColumnSql($t, 'shift_id', 'BIGINT NOT NULL DEFAULT 0', 'BIGINT NOT NULL DEFAULT 0',
                    'usrgrpid', 'FK -> module_plantonistas_shifts.id; 0 = grupo sem turnos configurados (legado)'));
            }
            if (!isset($idx[$t]['uniq_group_day']) && !isset($idx[$t]['uniq_group_day_shift'])) {
                if (isset($idx[$t]['schedule_date'])) {
                    \DBexecute($this->dropIndexSql($t, 'schedule_date'));
                }
                if (isset($idx[$t]['uniq_group_week'])) {
                    \DBexecute($this->dropUniqueSql($t, 'uniq_group_week'));
                }
                \DBexecute($this->addUniqueSql($t, 'uniq_group_day', 'usrgrpid, schedule_date'));
            }
            if (isset($idx[$t]['uk_schedule_date'])) {
                \DBexecute($this->dropUniqueSql($t, 'uk_schedule_date'));
            }

            // v4.1 — turnos dinâmicos na Escala: shift_id passa a fazer parte
            // da unicidade (0 = grupo sem turnos, mantém "1 titular por
            // grupo/dia"; >0 = 1 titular por grupo/dia/turno). ADD antes do
            // DROP para nunca deixar a tabela um instante sem constraint.
            if (!isset($idx[$t]['uniq_group_day_shift'])) {
                \DBexecute($this->addUniqueSql($t, 'uniq_group_day_shift', 'usrgrpid, schedule_date, shift_id'));
            }
            if (isset($idx[$t]['uniq_group_day'])) {
                \DBexecute($this->dropUniqueSql($t, 'uniq_group_day'));
            }
            if (!isset($idx[$t]['idx_sched_shift'])) {
                \DBexecute($this->addIndexSql($t, 'idx_sched_shift', 'shift_id'));
            }
        }

        // ── module_plantonistas_history — snapshot do turno por alteração ──
        // (v4.1). shift_id/shift_name gravam o turno no momento da alteração
        // (nome "congelado" — sobrevive a rename/remoção do turno depois).
        $t = 'module_plantonistas_history';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['shift_id'])) {
                $this->execAll($this->addColumnSql($t, 'shift_id', 'BIGINT NOT NULL DEFAULT 0', 'BIGINT NOT NULL DEFAULT 0', 'usrgrpid'));
            }
            if (!isset($cols[$t]['shift_name'])) {
                $this->execAll($this->addColumnSql($t, 'shift_name', "VARCHAR(50) NOT NULL DEFAULT ''", "VARCHAR(50) NOT NULL DEFAULT ''", 'shift_id'));
            }
            if (!isset($idx[$t]['idx_hist_shift'])) {
                \DBexecute($this->addIndexSql($t, 'idx_hist_shift', 'shift_id'));
            }
        }

        // ── module_plantonistas_shift_notes (herdado do repasse v2.2→v2.5) ──
        $t = 'module_plantonistas_shift_notes';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['shift_id'])) {
                $this->execAll($this->addColumnSql($t, 'shift_id', 'BIGINT DEFAULT NULL', 'BIGINT',
                    'shift_name', 'FK -> module_plantonistas_shifts.id (NULL = turno legado)'));
                \DBexecute($this->addIndexSql($t, 'idx_csn_shift_id', 'shift_id'));
            }
            if (!isset($cols[$t]['noc_context'])) {
                $this->execAll($this->addColumnSql($t, 'noc_context', 'VARCHAR(50) DEFAULT NULL', 'VARCHAR(50)', 'notes'));
                \DBexecute($this->addIndexSql($t, 'idx_csn_noc', 'noc_context'));
            }
            if ((int)($cols[$t]['shift_name'] ?? 0) > 0 && (int)$cols[$t]['shift_name'] < 50) {
                $this->execAll($this->modifyTypeSql($t, 'shift_name', 'VARCHAR(50)', true));
            }
            // v4.2 — editor rico + menções: distingue nota antiga (texto puro,
            // precisa de nl2br+htmlspecialchars na exibição) de nota nova
            // (HTML já sanitizado no save, exibida direto).
            if (!isset($cols[$t]['notes_format'])) {
                $this->execAll($this->addColumnSql($t, 'notes_format',
                    "VARCHAR(10) NOT NULL DEFAULT 'text'", "VARCHAR(10) NOT NULL DEFAULT 'text'",
                    'notes', 'text = legado (escapado na exibicao) | html = editor rico (ja sanitizado)'));
            }
        }

        // ── module_plantonistas_user_sessions ──
        //
        // Até a v4 o próprio cron de presença tinha um CREATE TABLE, que
        // divergia deste DDL (id INT, name VARCHAR(255), sem a coluna ip,
        // índices idx_userid/idx_lastaccess). Onde o cron rodou antes do
        // primeiro request com o módulo habilitado, é essa tabela torta que
        // está no banco — e existingTables() a considera pronta. O DDL do
        // cron foi removido na v5; o conserto do que ele criou é aqui.
        $t = 'module_plantonistas_user_sessions';
        if (isset($cols[$t])) {
            // `ip` PRECISA vir antes do bloco de noc_context: o ALTER de
            // noc_context usa AFTER ip e falharia numa tabela criada pelo cron.
            if (!isset($cols[$t]['ip'])) {
                $this->execAll($this->addColumnSql($t, 'ip', 'VARCHAR(39) DEFAULT NULL', 'VARCHAR(39)', 'lastaccess'));
            }
            // id INT estoura em 2,1 bi de linhas — a tabela é de alta rotação
            // (uma escrita por analista a cada ciclo do cron).
            // Só no MySQL: `int` no PostgreSQL vem como 'integer' e a promoção
            // é outra sintaxe. Instalação PG nova nunca passou pelo cron
            // antigo, então não há o que consertar lá.
            if (!$this->isPgsql()
                    && str_starts_with((string)($types[$t]['id'] ?? ''), 'int')) {
                \DBexecute("ALTER TABLE $t MODIFY COLUMN id BIGINT NOT NULL AUTO_INCREMENT");
            }
            // (Sem par no PostgreSQL de propósito: a coluna `id` lá nasce
            // BIGSERIAL pelo Schema, e uma instalação PG nunca passou pelo
            // CREATE TABLE do cron antigo, que é quem deixava `id INT`.)
            // A promoção de `userid` para UNSIGNED saiu: o Schema passou a
            // gerar BIGINT sem unsigned nos dois bancos (o PostgreSQL não tem
            // unsigned, e o ganho de dobrar um teto que essas tabelas nunca vão
            // encostar não paga a divergência). Mantê-la aqui faria toda
            // instalação MySQL nova rodar um ALTER inútil no primeiro request,
            // reescrevendo a tabela para desfazer o que o CREATE acabou de
            // fazer. Coluna já UNSIGNED em produção fica como está — é
            // compatível e converter custaria um rebuild sem ganho nenhum.
            if (!isset($cols[$t]['noc_context'])) {
                $this->execAll($this->addColumnSql($t, 'noc_context', 'VARCHAR(50) DEFAULT NULL', 'VARCHAR(50)', 'ip'));
                \DBexecute($this->addIndexSql($t, 'idx_cus_noc_context', 'noc_context'));
            }
            // Índices com o nome oficial. Os do cron (idx_userid,
            // idx_lastaccess) ficam onde estão: são redundantes, mas dropar
            // índice numa tabela grande trava escrita, e o ganho é zero.
            foreach ([
                'idx_cus_userid'        => 'userid',
                'idx_cus_lastaccess'    => 'lastaccess',
                'idx_cus_session_start' => 'session_start',
            ] as $name => $col) {
                if (!isset($idx[$t][$name]) && isset($cols[$t][$col])) {
                    \DBexecute($this->addIndexSql($t, $name, $col));
                }
            }
        }
        // `name` VARCHAR(255) criado pelo cron NÃO é reduzido para o
        // VARCHAR(128) do DDL: encurtar coluna trunca dado silenciosamente, e
        // 255 acomoda 128 sem prejuízo nenhum.

        // ── module_plantonistas_shift_reports ──
        $t = 'module_plantonistas_shift_reports';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['noc_context'])) {
                $this->execAll($this->addColumnSql($t, 'noc_context', 'VARCHAR(50) DEFAULT NULL', 'VARCHAR(50)', 'shift_name'));
                \DBexecute($this->addIndexSql($t, 'idx_csr_noc', 'noc_context'));
            }
            // A tabela de produção veio do RENAME de custom_shift_reports e,
            // até o "Fechar turno" (issue #2), NUNCA teve uma linha escrita —
            // as colunas nunca foram exercitadas. Se o schema antigo declarou
            // report_json como TEXT (64 KB), o snapshot de um dia movimentado
            // estoura: com sql_mode STRICT o INSERT falha, e sem STRICT
            // trunca em silêncio — aí a leitura devolve "fechamento não
            // encontrado" por JSON inválido, que é o pior dos dois mundos.
            // Só no MySQL: no PostgreSQL `TEXT` já é ilimitado, então não há
            // o que promover — a checagem lá casaria com 'text' e a migração
            // seria um ALTER inútil reescrevendo a tabela.
            $tipo = (string) ($types[$t]['report_json'] ?? '');
            if (!$this->isPgsql() && $tipo !== '' && !str_contains($tipo, 'longtext')) {
                $this->execAll($this->modifyTypeSql($t, 'report_json', 'LONGTEXT', true));
            }
        }
    }

}
