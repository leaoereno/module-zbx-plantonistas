<?php

namespace Modules\Plantonistas;

/**
 * Schema — descrição única das tabelas do módulo, e o gerador de DDL.
 *
 * ── Por que existe (issue #1) ────────────────────────────────────────────
 *
 * O DDL das 9 tabelas vivia escrito duas vezes, em MySQL puro: no
 * `Module::tableDdl()` (usado pelo `init()`) e no `sql/schema.sql` (usado por
 * instalação manual). Manter duas cópias já era ruim; com PostgreSQL entrando
 * viraria quatro. E a divergência entre elas é do tipo que não dá erro na
 * hora: as duas instalam, e a diferença só aparece meses depois, quando uma
 * coluna existe num ambiente e não no outro.
 *
 * Aqui a tabela é descrita UMA vez, em termos abstratos, e o DDL de cada banco
 * é gerado a partir disso.
 *
 * ── Decisões de mapeamento de tipo ───────────────────────────────────────
 *
 * - `bigint` — no MySQL sai `BIGINT`; o `UNSIGNED` que existia em algumas
 *   tabelas foi abandonado de propósito. PostgreSQL não tem unsigned, e o
 *   ganho (dobrar um teto que nenhuma dessas tabelas vai encostar) não paga a
 *   divergência entre os dois bancos. Em tabela já existente o tipo atual é
 *   mantido — quem migra não precisa de um ALTER que reescreve a tabela.
 * - `serial` — chave primária auto-numerada. `BIGINT AUTO_INCREMENT` no MySQL,
 *   `BIGSERIAL` no PostgreSQL.
 * - `flag` — o antigo `TINYINT(1)`. Vira **`SMALLINT`**, não `BOOLEAN`: o
 *   código compara `active = 1` e `is_read = 0` em 10 lugares, e no PostgreSQL
 *   `boolean = integer` é erro. `SMALLINT` funciona igual nos dois e não muda
 *   uma linha de PHP.
 * - `datetime` — `DATETIME` no MySQL, `TIMESTAMP(0)` (sem fuso) no PostgreSQL,
 *   preservando o comportamento atual: o módulo sempre gravou hora local.
 * - `epoch` — `INT` guardando timestamp Unix, como o próprio core do Zabbix faz.
 * - `text` — `LONGTEXT` no MySQL (o `TEXT` de 64 KB truncava snapshot de
 *   fechamento); no PostgreSQL `TEXT` já é ilimitado.
 * - `touch` — a coluna que se atualiza sozinha (`ON UPDATE CURRENT_TIMESTAMP`).
 *   No PostgreSQL isso não existe como propriedade de coluna: exigiria função
 *   + trigger por tabela. Como as duas colunas assim (`updated_at` de
 *   `shifts` e de `user_shift`) **não são lidas por nenhuma tela**, no ramo PG
 *   ela vira um `datetime` comum com default — e fica registrado aqui que o
 *   valor não se atualiza sozinho lá. Criar dois triggers para uma coluna que
 *   ninguém consulta seria custo sem retorno.
 *
 * Índices saem do CREATE TABLE e viram `CREATE INDEX` separados: `KEY`/`INDEX`
 * inline é sintaxe do MySQL.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class Schema {

    /**
     * As 9 tabelas do módulo.
     *
     * Formato de cada coluna: [nome, tipo, opções].
     *   tipo:    serial | bigint | epoch | int | flag | varchar:N | text |
     *            date | time | datetime | touch
     *   opções:  'null' (aceita NULL), 'default' => valor, 'comment' => texto
     */
    public static function tables(): array {
        return [
            'module_plantonistas_phones' => [
                'columns' => [
                    ['userid', 'bigint'],
                    ['phone',  'varchar:100', ['default' => '']],
                ],
                'primary' => ['userid'],
            ],

            'module_plantonistas_schedule' => [
                'columns' => [
                    ['scheduleid',     'serial'],
                    ['usrgrpid',       'bigint',  ['default' => 0, 'comment' => 'FK -> usrgrp.usrgrpid']],
                    ['shift_id',       'bigint',  ['default' => 0, 'comment' => 'FK -> module_plantonistas_shifts.id; 0 = grupo sem turnos (legado)']],
                    ['userid',         'bigint'],
                    ['userid_reserva', 'bigint',  ['null']],
                    ['schedule_date',  'date'],
                    ['created_by',     'bigint',  ['default' => 0]],
                    ['created_at',     'epoch',   ['default' => 0]],
                ],
                'primary' => ['scheduleid'],
                'unique'  => ['uniq_group_day_shift' => ['usrgrpid', 'schedule_date', 'shift_id']],
                'index'   => ['idx_sched_shift' => ['shift_id']],
                'comment' => 'Escala de plantão: 1 titular por grupo/dia, ou por grupo/dia/turno quando há turnos cadastrados',
            ],

            'module_plantonistas_history' => [
                'columns' => [
                    ['historyid',     'serial'],
                    ['scheduleid',    'bigint',      ['default' => 0]],
                    ['usrgrpid',      'bigint',      ['default' => 0]],
                    ['shift_id',      'bigint',      ['default' => 0]],
                    ['shift_name',    'varchar:50',  ['default' => '', 'comment' => 'Snapshot do nome do turno — sobrevive a rename/remoção']],
                    ['schedule_date', 'date'],
                    ['action',        'varchar:16',  ['default' => 'save']],
                    ['userid_old',    'bigint',      ['null']],
                    ['userid_new',    'bigint',      ['null']],
                    ['reserva_old',   'bigint',      ['null']],
                    ['reserva_new',   'bigint',      ['null']],
                    ['changed_by',    'bigint',      ['default' => 0]],
                    ['changed_at',    'epoch',       ['default' => 0]],
                ],
                'primary' => ['historyid'],
                'index'   => [
                    'idx_schedule_date' => ['schedule_date'],
                    'idx_usrgrpid'      => ['usrgrpid'],
                    'idx_hist_shift'    => ['shift_id'],
                ],
                'comment' => 'Histórico de alterações da escala',
            ],

            'module_plantonistas_user_sessions' => [
                'columns' => [
                    ['id',            'serial'],
                    ['userid',        'bigint'],
                    ['username',      'varchar:100'],
                    ['name',          'varchar:128', ['null']],
                    ['session_start', 'datetime'],
                    ['lastaccess',    'datetime'],
                    ['ip',            'varchar:39',  ['null']],
                    ['noc_context',   'varchar:50',  ['null']],
                ],
                'primary' => ['id'],
                'index'   => [
                    'idx_cus_userid'        => ['userid'],
                    'idx_cus_lastaccess'    => ['lastaccess'],
                    'idx_cus_session_start' => ['session_start'],
                    'idx_cus_noc_context'   => ['noc_context'],
                ],
                'comment' => 'Presença de analistas — populada por scripts/cron_presence_tracker.php',
            ],

            'module_plantonistas_shift_notes' => [
                'columns' => [
                    ['id',             'serial'],
                    ['shift_date',     'date'],
                    ['shift_name',     'varchar:50'],
                    ['shift_id',       'bigint',      ['null']],
                    ['analyst_userid', 'bigint'],
                    ['analyst_name',   'varchar:128'],
                    ['notes',          'text'],
                    ['notes_format',   'varchar:10',  ['default' => 'text', 'comment' => 'text = legado (escapado na exibição) | html = editor rico (já sanitizado)']],
                    ['noc_context',    'varchar:50',  ['null']],
                    ['created_at',     'datetime',    ['default_now']],
                ],
                'primary' => ['id'],
                'index'   => [
                    'idx_csn_shift'    => ['shift_date', 'shift_name'],
                    'idx_csn_shift_id' => ['shift_id'],
                    'idx_csn_analyst'  => ['analyst_userid'],
                    'idx_csn_noc'      => ['noc_context'],
                ],
                'comment' => 'Diário de Bordo — histórico permanente, sem expiração',
            ],

            'module_plantonistas_mentions' => [
                'columns' => [
                    ['id',               'serial'],
                    ['note_id',          'bigint'],
                    ['mentioned_userid', 'bigint'],
                    ['created_by',       'bigint'],
                    ['is_read',          'flag',     ['default' => 0]],
                    ['created_at',       'datetime', ['default_now']],
                    ['read_at',          'datetime', ['null']],
                    ['notified_at',      'datetime', ['null', 'comment' => 'Quando o cron avisou pelo media type; NULL = ainda na fila']],
                ],
                'primary' => ['id'],
                'index'   => [
                    'idx_mention_user_unread' => ['mentioned_userid', 'is_read'],
                    'idx_mention_note'        => ['note_id'],
                    // A fila varre por notified_at IS NULL a cada minuto:
                    // sem índice isso é full scan numa tabela que só cresce.
                    'idx_mention_fila'        => ['notified_at'],
                ],
                'comment' => 'Menções @ do Diário de Bordo',
            ],

            'module_plantonistas_shift_reports' => [
                'columns' => [
                    ['id',           'serial'],
                    ['shift_date',   'date'],
                    ['shift_name',   'varchar:20'],
                    ['generated_by', 'bigint',     ['comment' => 'FK -> users.userid']],
                    ['noc_context',  'varchar:50', ['null']],
                    ['generated_at', 'datetime',   ['default_now']],
                    ['report_json',  'text',       ['comment' => 'Snapshot JSON do relatório no fechamento do turno']],
                ],
                'primary' => ['id'],
                'index'   => [
                    'idx_csr_shift' => ['shift_date', 'shift_name'],
                    'idx_csr_noc'   => ['noc_context'],
                ],
                'comment' => 'Turnos fechados — repasse congelado (issue #2)',
            ],

            'module_plantonistas_shifts' => [
                'columns' => [
                    ['id',         'serial'],
                    ['usrgrpid',   'bigint',     ['comment' => 'FK -> usrgrp.usrgrpid (equipe dona do turno)']],
                    ['name',       'varchar:50', ['comment' => 'Ex: Diurno, Turno 1']],
                    ['start_time', 'time'],
                    ['end_time',   'time',       ['comment' => 'Se <= start_time, o turno vira o dia (madrugada)']],
                    ['sort_order', 'int',        ['default' => 0]],
                    ['active',     'flag',       ['default' => 1]],
                    ['created_at', 'datetime',   ['default_now']],
                    ['updated_at', 'touch'],
                ],
                'primary' => ['id'],
                'unique'  => ['uq_cs_group_name' => ['usrgrpid', 'name']],
                'index'   => [
                    'idx_cs_usrgrp' => ['usrgrpid'],
                    'idx_cs_active' => ['active'],
                ],
                'comment' => 'Turnos configuráveis por equipe',
            ],

            'module_plantonistas_user_shift' => [
                'columns' => [
                    ['userid',     'bigint'],
                    ['shift_id',   'bigint'],
                    ['updated_at', 'touch'],
                ],
                'primary' => ['userid'],
                'index'   => ['idx_cush_shift' => ['shift_id']],
                'comment' => 'Vínculo analista -> turno (1 turno por analista)',
            ],
        ];
    }

    /**
     * DDL de todas as tabelas, no dialeto pedido.
     *
     * @param bool $pgsql true = PostgreSQL, false = MySQL/MariaDB
     * @return array<string, string[]> nome da tabela => lista de statements
     */
    public static function ddl(bool $pgsql): array {
        $out = [];

        foreach (self::tables() as $table => $def) {
            $out[$table] = self::tableDdl($table, $def, $pgsql);
        }

        return $out;
    }

    /** Statements de UMA tabela: o CREATE TABLE, seus índices e comentários. */
    private static function tableDdl(string $table, array $def, bool $pgsql): array {
        $linhas = [];

        foreach ($def['columns'] as $col) {
            $linhas[] = '  ' . self::columnSql($col, $pgsql);
        }

        $linhas[] = '  PRIMARY KEY (' . implode(', ', $def['primary']) . ')';

        // UNIQUE dentro do CREATE TABLE nos dois bancos, mas com sintaxe
        // diferente: `UNIQUE KEY nome (...)` é MySQL; o padrão é
        // `CONSTRAINT nome UNIQUE (...)`.
        foreach (($def['unique'] ?? []) as $nome => $cols) {
            $linhas[] = $pgsql
                ? "  CONSTRAINT $nome UNIQUE (" . implode(', ', $cols) . ')'
                : "  UNIQUE KEY $nome (" . implode(', ', $cols) . ')';
        }

        // Os índices ficam INLINE no MySQL e separados no PostgreSQL — não é
        // preciosismo. `CREATE INDEX IF NOT EXISTS` existe no MariaDB e no
        // PostgreSQL, mas **não no MySQL 8.0**: lá o statement é erro de
        // sintaxe. Inline dentro do `CREATE TABLE IF NOT EXISTS` é idempotente
        // por tabelar junto, funciona em todo MySQL/MariaDB, e é exatamente o
        // que o módulo já fazia em produção.
        if (!$pgsql) {
            foreach (($def['index'] ?? []) as $nome => $cols) {
                $linhas[] = "  INDEX $nome (" . implode(', ', $cols) . ')';
            }
        }

        $sql = "CREATE TABLE IF NOT EXISTS $table (\n" . implode(",\n", $linhas) . "\n)";

        if (!$pgsql) {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
            if (isset($def['comment'])) {
                $sql .= " COMMENT='" . str_replace("'", "''", $def['comment']) . "'";
            }
        }

        $stmts = [$sql];

        if ($pgsql) {
            foreach (($def['index'] ?? []) as $nome => $cols) {
                $stmts[] = "CREATE INDEX IF NOT EXISTS $nome ON $table (" . implode(', ', $cols) . ')';
            }
        }

        // Comentários: no PostgreSQL são statements separados.
        if ($pgsql) {
            if (isset($def['comment'])) {
                $stmts[] = "COMMENT ON TABLE $table IS " . self::quote($def['comment']);
            }
            foreach ($def['columns'] as $col) {
                if (isset($col[2]['comment'])) {
                    $stmts[] = "COMMENT ON COLUMN $table.{$col[0]} IS " . self::quote($col[2]['comment']);
                }
            }
        }

        return $stmts;
    }

    /** Uma coluna, do tipo abstrato ao tipo do banco. */
    private static function columnSql(array $col, bool $pgsql): string {
        [$nome, $tipo] = $col;
        $opts = $col[2] ?? [];

        // `serial` sai antes do caminho comum por causa da ORDEM dos
        // atributos: no MySQL o AUTO_INCREMENT vem depois do NOT NULL
        // (`BIGINT NOT NULL AUTO_INCREMENT`), e montá-lo pelo fluxo genérico
        // produziria `BIGINT AUTO_INCREMENT NOT NULL`. No PostgreSQL o
        // BIGSERIAL já implica NOT NULL, então declarar de novo é ruído.
        if ($tipo === 'serial') {
            return $pgsql
                ? $nome . ' BIGSERIAL'
                : $nome . ' BIGINT NOT NULL AUTO_INCREMENT';
        }

        $sql = $nome . ' ' . self::typeSql($tipo, $pgsql);

        $aceitaNull = in_array('null', $opts, true);

        if (!$aceitaNull) {
            $sql .= ' NOT NULL';
        }

        if (array_key_exists('default', $opts)) {
            $v = $opts['default'];
            $sql .= ' DEFAULT ' . (is_int($v) ? (string) $v : self::quote((string) $v));
        }
        elseif (in_array('default_now', $opts, true) || $tipo === 'touch') {
            $sql .= ' DEFAULT CURRENT_TIMESTAMP';
        }

        // ON UPDATE CURRENT_TIMESTAMP só existe no MySQL. No PG exigiria
        // função + trigger; como nenhuma tela lê essas colunas, o valor
        // simplesmente não se atualiza sozinho lá (ver o cabeçalho da classe).
        if ($tipo === 'touch' && !$pgsql) {
            $sql .= ' ON UPDATE CURRENT_TIMESTAMP';
        }

        if (!$pgsql && isset($opts['comment'])) {
            $sql .= " COMMENT " . self::quote($opts['comment']);
        }

        return $sql;
    }

    private static function typeSql(string $tipo, bool $pgsql): string {
        if (str_starts_with($tipo, 'varchar:')) {
            return 'VARCHAR(' . substr($tipo, 8) . ')';
        }

        switch ($tipo) {
            case 'serial':   return $pgsql ? 'BIGSERIAL'    : 'BIGINT AUTO_INCREMENT';
            case 'bigint':   return 'BIGINT';
            case 'epoch':
            case 'int':      return 'INTEGER';
            case 'flag':     return 'SMALLINT';
            case 'text':     return $pgsql ? 'TEXT'         : 'LONGTEXT';
            case 'date':     return 'DATE';
            case 'time':     return 'TIME';
            case 'touch':
            case 'datetime': return $pgsql ? 'TIMESTAMP(0)' : 'DATETIME';
        }

        throw new \RuntimeException('Schema: tipo desconhecido: ' . $tipo);
    }

    private static function quote(string $v): string {
        return "'" . str_replace("'", "''", $v) . "'";
    }
}
