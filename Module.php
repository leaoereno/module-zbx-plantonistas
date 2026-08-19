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
                \DBexecute('RENAME TABLE `' . $old . '` TO `' . $new . '`');
                $exists[$new] = true;
                unset($exists[$old]);
            }
            elseif ($hasOld && $hasNew) {
                // Cenário de schema.sql rodado antes da migração: a tabela
                // nova existe vazia enquanto a antiga tem os dados. Só nesse
                // caso a nova (vazia) é descartada para o RENAME acontecer.
                // Tabela com qualquer linha NUNCA é dropada.
                $newHasRows = (bool) \DBfetch(\DBselect('SELECT 1 AS x FROM `' . $new . '` LIMIT 1'));
                if (!$newHasRows) {
                    \DBexecute('DROP TABLE `' . $new . '`');
                    \DBexecute('RENAME TABLE `' . $old . '` TO `' . $new . '`');
                    unset($exists[$old]);
                }
                else {
                    error_log('[plantonistas] AVISO: ' . $old . ' e ' . $new
                        . ' existem e ambas têm dados — resolução manual necessária.');
                }
            }
        }

        // 2. Criar o que ainda faltar (instalação nova / tabela nunca usada).
        foreach ($this->tableDdl() as $table => $ddl) {
            if (!isset($exists[$table])) {
                \DBexecute($ddl);
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
        return strtolower((string)($GLOBALS['DB']['TYPE'] ?? '')) === 'postgresql';
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
                \DBexecute("ALTER TABLE $t ADD COLUMN usrgrpid BIGINT NOT NULL DEFAULT 0 AFTER scheduleid");
            }
            if (!isset($cols[$t]['userid_reserva'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN userid_reserva BIGINT NULL AFTER userid");
            }
            if (!isset($cols[$t]['shift_id'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN shift_id BIGINT UNSIGNED NOT NULL DEFAULT 0" .
                    " COMMENT 'FK -> module_plantonistas_shifts.id; 0 = grupo sem turnos configurados (legado)'" .
                    " AFTER usrgrpid");
            }
            if (!isset($idx[$t]['uniq_group_day']) && !isset($idx[$t]['uniq_group_day_shift'])) {
                if (isset($idx[$t]['schedule_date'])) {
                    \DBexecute("ALTER TABLE $t DROP INDEX `schedule_date`");
                }
                if (isset($idx[$t]['uniq_group_week'])) {
                    \DBexecute("ALTER TABLE $t DROP INDEX `uniq_group_week`");
                }
                \DBexecute("ALTER TABLE $t ADD UNIQUE KEY uniq_group_day (usrgrpid, schedule_date)");
            }
            if (isset($idx[$t]['uk_schedule_date'])) {
                \DBexecute("ALTER TABLE $t DROP INDEX `uk_schedule_date`");
            }

            // v4.1 — turnos dinâmicos na Escala: shift_id passa a fazer parte
            // da unicidade (0 = grupo sem turnos, mantém "1 titular por
            // grupo/dia"; >0 = 1 titular por grupo/dia/turno). ADD antes do
            // DROP para nunca deixar a tabela um instante sem constraint.
            if (!isset($idx[$t]['uniq_group_day_shift'])) {
                \DBexecute("ALTER TABLE $t ADD UNIQUE KEY uniq_group_day_shift (usrgrpid, schedule_date, shift_id)");
            }
            if (isset($idx[$t]['uniq_group_day'])) {
                \DBexecute("ALTER TABLE $t DROP INDEX `uniq_group_day`");
            }
            if (!isset($idx[$t]['idx_sched_shift'])) {
                \DBexecute("ALTER TABLE $t ADD INDEX idx_sched_shift (shift_id)");
            }
        }

        // ── module_plantonistas_history — snapshot do turno por alteração ──
        // (v4.1). shift_id/shift_name gravam o turno no momento da alteração
        // (nome "congelado" — sobrevive a rename/remoção do turno depois).
        $t = 'module_plantonistas_history';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['shift_id'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN shift_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER usrgrpid");
            }
            if (!isset($cols[$t]['shift_name'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN shift_name VARCHAR(50) NOT NULL DEFAULT '' AFTER shift_id");
            }
            if (!isset($idx[$t]['idx_hist_shift'])) {
                \DBexecute("ALTER TABLE $t ADD INDEX idx_hist_shift (shift_id)");
            }
        }

        // ── module_plantonistas_shift_notes (herdado do repasse v2.2→v2.5) ──
        $t = 'module_plantonistas_shift_notes';
        if (isset($cols[$t])) {
            if (!isset($cols[$t]['shift_id'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN shift_id BIGINT UNSIGNED DEFAULT NULL" .
                    " COMMENT 'FK -> module_plantonistas_shifts.id (NULL = turno legado)' AFTER shift_name");
                \DBexecute("ALTER TABLE $t ADD INDEX idx_csn_shift_id (shift_id)");
            }
            if (!isset($cols[$t]['noc_context'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN noc_context VARCHAR(50) DEFAULT NULL AFTER notes");
                \DBexecute("ALTER TABLE $t ADD INDEX idx_csn_noc (noc_context)");
            }
            if ((int)($cols[$t]['shift_name'] ?? 0) > 0 && (int)$cols[$t]['shift_name'] < 50) {
                \DBexecute("ALTER TABLE $t MODIFY COLUMN shift_name VARCHAR(50) NOT NULL");
            }
            // v4.2 — editor rico + menções: distingue nota antiga (texto puro,
            // precisa de nl2br+htmlspecialchars na exibição) de nota nova
            // (HTML já sanitizado no save, exibida direto).
            if (!isset($cols[$t]['notes_format'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN notes_format VARCHAR(10) NOT NULL DEFAULT 'text'" .
                    " COMMENT 'text = legado (escapado na exibição) | html = editor rico (ja sanitizado)' AFTER notes");
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
                \DBexecute("ALTER TABLE $t ADD COLUMN ip VARCHAR(39) DEFAULT NULL AFTER lastaccess");
            }
            // id INT estoura em 2,1 bi de linhas — a tabela é de alta rotação
            // (uma escrita por analista a cada ciclo do cron).
            if (str_starts_with((string)($types[$t]['id'] ?? ''), 'int')) {
                \DBexecute("ALTER TABLE $t MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
            }
            if (str_contains((string)($types[$t]['userid'] ?? ''), 'bigint')
                    && !str_contains((string)($types[$t]['userid'] ?? ''), 'unsigned')) {
                \DBexecute("ALTER TABLE $t MODIFY COLUMN userid BIGINT UNSIGNED NOT NULL");
            }
            if (!isset($cols[$t]['noc_context'])) {
                \DBexecute("ALTER TABLE $t ADD COLUMN noc_context VARCHAR(50) DEFAULT NULL AFTER ip");
                \DBexecute("ALTER TABLE $t ADD INDEX idx_cus_noc_context (noc_context)");
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
                    \DBexecute("ALTER TABLE $t ADD INDEX $name ($col)");
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
                \DBexecute("ALTER TABLE $t ADD COLUMN noc_context VARCHAR(50) DEFAULT NULL AFTER shift_name");
                \DBexecute("ALTER TABLE $t ADD INDEX idx_csr_noc (noc_context)");
            }
            // A tabela de produção veio do RENAME de custom_shift_reports e,
            // até o "Fechar turno" (issue #2), NUNCA teve uma linha escrita —
            // as colunas nunca foram exercitadas. Se o schema antigo declarou
            // report_json como TEXT (64 KB), o snapshot de um dia movimentado
            // estoura: com sql_mode STRICT o INSERT falha, e sem STRICT
            // trunca em silêncio — aí a leitura devolve "fechamento não
            // encontrado" por JSON inválido, que é o pior dos dois mundos.
            $tipo = (string) ($types[$t]['report_json'] ?? '');
            if ($tipo !== '' && !str_contains($tipo, 'longtext')) {
                \DBexecute("ALTER TABLE $t MODIFY COLUMN report_json LONGTEXT NOT NULL");
            }
        }
    }

    private function tableDdl(): array {
        return [
            'module_plantonistas_phones' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_phones (' .
                '  userid BIGINT NOT NULL,' .
                '  phone  VARCHAR(100) NOT NULL DEFAULT \'\',' .
                '  PRIMARY KEY (userid)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_schedule' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_schedule (' .
                '  scheduleid     BIGINT  NOT NULL AUTO_INCREMENT,' .
                '  usrgrpid       BIGINT  NOT NULL DEFAULT 0,' .
                '  shift_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,' .
                '  userid         BIGINT  NOT NULL,' .
                '  userid_reserva BIGINT  NULL,' .
                '  schedule_date  DATE    NOT NULL,' .
                '  created_by     BIGINT  NOT NULL DEFAULT 0,' .
                '  created_at     INT     NOT NULL DEFAULT 0,' .
                '  PRIMARY KEY (scheduleid),' .
                '  UNIQUE KEY uniq_group_day_shift (usrgrpid, schedule_date, shift_id),' .
                '  KEY idx_sched_shift (shift_id)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_history' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_history (' .
                '  historyid     BIGINT      NOT NULL AUTO_INCREMENT,' .
                '  scheduleid    BIGINT      NOT NULL DEFAULT 0,' .
                '  usrgrpid      BIGINT      NOT NULL DEFAULT 0,' .
                '  shift_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,' .
                '  shift_name    VARCHAR(50) NOT NULL DEFAULT \'\',' .
                '  schedule_date DATE        NOT NULL,' .
                '  action        VARCHAR(16) NOT NULL DEFAULT \'save\',' .
                '  userid_old    BIGINT      NULL,' .
                '  userid_new    BIGINT      NULL,' .
                '  reserva_old   BIGINT      NULL,' .
                '  reserva_new   BIGINT      NULL,' .
                '  changed_by    BIGINT      NOT NULL DEFAULT 0,' .
                '  changed_at    INT         NOT NULL DEFAULT 0,' .
                '  PRIMARY KEY (historyid),' .
                '  KEY idx_schedule_date (schedule_date),' .
                '  KEY idx_usrgrpid (usrgrpid),' .
                '  KEY idx_hist_shift (shift_id)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_user_sessions' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_user_sessions (' .
                '  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
                '  userid        BIGINT UNSIGNED NOT NULL,' .
                '  username      VARCHAR(100)    NOT NULL,' .
                '  name          VARCHAR(128)    DEFAULT NULL,' .
                '  session_start DATETIME        NOT NULL,' .
                '  lastaccess    DATETIME        NOT NULL,' .
                '  ip            VARCHAR(39)     DEFAULT NULL,' .
                '  noc_context   VARCHAR(50)     DEFAULT NULL,' .
                '  PRIMARY KEY (id),' .
                '  INDEX idx_cus_userid        (userid),' .
                '  INDEX idx_cus_lastaccess    (lastaccess),' .
                '  INDEX idx_cus_session_start (session_start),' .
                '  INDEX idx_cus_noc_context   (noc_context)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_shift_notes' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_shift_notes (' .
                '  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
                '  shift_date     DATE            NOT NULL,' .
                '  shift_name     VARCHAR(50)     NOT NULL,' .
                '  shift_id       BIGINT UNSIGNED DEFAULT NULL,' .
                '  analyst_userid BIGINT UNSIGNED NOT NULL,' .
                '  analyst_name   VARCHAR(128)    NOT NULL,' .
                '  notes          TEXT            NOT NULL,' .
                "  notes_format   VARCHAR(10)     NOT NULL DEFAULT 'text'," .
                '  noc_context    VARCHAR(50)     DEFAULT NULL,' .
                '  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
                '  PRIMARY KEY (id),' .
                '  INDEX idx_csn_shift    (shift_date, shift_name),' .
                '  INDEX idx_csn_shift_id (shift_id),' .
                '  INDEX idx_csn_analyst  (analyst_userid),' .
                '  INDEX idx_csn_noc      (noc_context)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_mentions' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_mentions (' .
                '  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
                '  note_id          BIGINT UNSIGNED NOT NULL,' .
                '  mentioned_userid BIGINT UNSIGNED NOT NULL,' .
                '  created_by       BIGINT UNSIGNED NOT NULL,' .
                '  is_read          TINYINT(1)      NOT NULL DEFAULT 0,' .
                '  created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
                '  read_at          DATETIME        NULL,' .
                '  PRIMARY KEY (id),' .
                '  INDEX idx_mention_user_unread (mentioned_userid, is_read),' .
                '  INDEX idx_mention_note        (note_id)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_shift_reports' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_shift_reports (' .
                '  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
                '  shift_date   DATE            NOT NULL,' .
                '  shift_name   VARCHAR(20)     NOT NULL,' .
                '  generated_by BIGINT UNSIGNED NOT NULL,' .
                '  noc_context  VARCHAR(50)     DEFAULT NULL,' .
                '  generated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
                '  report_json  LONGTEXT        NOT NULL,' .
                '  PRIMARY KEY (id),' .
                '  INDEX idx_csr_shift (shift_date, shift_name),' .
                '  INDEX idx_csr_noc   (noc_context)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_shifts' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_shifts (' .
                '  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,' .
                '  usrgrpid   BIGINT UNSIGNED NOT NULL,' .
                '  name       VARCHAR(50)     NOT NULL,' .
                '  start_time TIME            NOT NULL,' .
                '  end_time   TIME            NOT NULL,' .
                '  sort_order INT             NOT NULL DEFAULT 0,' .
                '  active     TINYINT(1)      NOT NULL DEFAULT 1,' .
                '  created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
                '  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
                '  PRIMARY KEY (id),' .
                '  UNIQUE KEY uq_cs_group_name (usrgrpid, name),' .
                '  INDEX idx_cs_usrgrp (usrgrpid),' .
                '  INDEX idx_cs_active (active)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',

            'module_plantonistas_user_shift' =>
                'CREATE TABLE IF NOT EXISTS module_plantonistas_user_shift (' .
                '  userid     BIGINT UNSIGNED NOT NULL,' .
                '  shift_id   BIGINT UNSIGNED NOT NULL,' .
                '  updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
                '  PRIMARY KEY (userid),' .
                '  INDEX idx_cush_shift (shift_id)' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];
    }
}
