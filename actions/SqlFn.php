<?php

namespace Modules\Plantonistas\Actions;

/**
 * SqlFn — funções de data/hora escritas uma vez, geradas por dialeto.
 *
 * ── Por que existe (issue #1) ────────────────────────────────────────────
 *
 * `DATE_FORMAT`, `FROM_UNIXTIME` e `TIMESTAMPDIFF` são MySQL. Espalhar um
 * `if ($pgsql)` por dentro de cada uma das ~20 consultas que as usam tornaria
 * cada consulta ilegível e a próxima auditoria impossível. Aqui a regra fica
 * num lugar só, e a consulta chama um método com nome do que ela quer.
 *
 * Todos os métodos são estáticos e sem estado: servem tanto ao trait da
 * família repasse quanto às actions da família escala, que consultam o banco
 * por caminhos diferentes.
 *
 * ── A premissa de fuso, que precisa ficar explícita ──────────────────────
 *
 * O módulo converte epoch para data somando o offset do PHP ao valor
 * (`ev.clock + $tzOffset`) e só então formatando. Isso pressupõe que **o banco
 * está em UTC** — se o MySQL estivesse em America/Sao_Paulo, o `FROM_UNIXTIME`
 * aplicaria o fuso dele por cima do offset já somado, e a hora sairia 3 horas
 * adiantada.
 *
 * Essa premissa não é nova nem foi introduzida aqui; o que é novo é estar
 * escrita. No ramo PostgreSQL ela vira explícita no SQL: `to_timestamp()`
 * devolve `timestamptz` e seria renderizado no `TimeZone` da *sessão*, então
 * o `AT TIME ZONE 'UTC'` fixa a interpretação e faz o resultado bater com o
 * do MySQL, independentemente de como o servidor PG esteja configurado.
 *
 * Fuso já produziu erro de um dia três vezes neste módulo (heatmap, cron de
 * presença, janela de notificação). Ao mexer aqui, teste às 9h, 20h e 23h.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class SqlFn {

    /** O backend é PostgreSQL? Lê o $DB['TYPE'] que o Zabbix expõe. */
    public static function isPgsql(): bool {
        return strtolower((string)($GLOBALS['DB']['TYPE'] ?? '')) === 'postgresql';
    }

    /**
     * Data/hora no padrão brasileiro: `17/08/2026 14:30`.
     *
     * Atenção ao `HH24`: no `to_char` do PostgreSQL, `HH` sozinho é relógio de
     * 12 horas — usá-lo faria as 14h virarem 02 em silêncio.
     */
    public static function dateTimeBr(string $col): string {
        return self::isPgsql()
            ? "to_char($col, 'DD/MM/YYYY HH24:MI')"
            : "DATE_FORMAT($col, '%d/%m/%Y %H:%i')";
    }

    /**
     * Data em ISO (`2026-08-17`), usada como CHAVE, não para exibir.
     *
     * Continua em Y-m-d de propósito: é o formato que o `value` do
     * `input type="date"` e os links do módulo esperam (ver CLAUDE.md,
     * "Y-m-d que é protocolo").
     */
    public static function dateIso(string $col): string {
        return self::isPgsql()
            ? "to_char($col, 'YYYY-MM-DD')"
            : "DATE_FORMAT($col, '%Y-%m-%d')";
    }

    /**
     * Hora (00–23) de um epoch — para agrupar por hora do dia.
     *
     * Ver a nota de fuso no cabeçalho: quem chama já somou o offset ao epoch.
     */
    public static function hourFromEpoch(string $expr): string {
        return self::isPgsql()
            ? "to_char(to_timestamp($expr) AT TIME ZONE 'UTC', 'HH24')"
            : "FROM_UNIXTIME($expr, '%H')";
    }

    /** Data (sem hora) de um epoch — para agrupar por dia. */
    public static function dateFromEpoch(string $expr): string {
        return self::isPgsql()
            ? "CAST(to_timestamp($expr) AT TIME ZONE 'UTC' AS DATE)"
            : "DATE(FROM_UNIXTIME($expr))";
    }

    /**
     * Minutos entre dois instantes.
     *
     * O `EXTRACT(EPOCH FROM ...)` do PostgreSQL devolve segundos com fração.
     * `TRUNC` e não `FLOOR`: o `TIMESTAMPDIFF(MINUTE, ...)` do MySQL trunca em
     * direção a zero, enquanto o FLOOR arredonda para baixo — os dois só
     * divergem com diferença negativa, que o único chamador de hoje não
     * produz, mas TRUNC é a tradução exata e custa a mesma palavra.
     */
    public static function minutesBetween(string $inicio, string $fim): string {
        return self::isPgsql()
            ? "TRUNC(EXTRACT(EPOCH FROM ($fim - $inicio)) / 60)"
            : "TIMESTAMPDIFF(MINUTE, $inicio, $fim)";
    }

    /**
     * "Agora menos N minutos", para comparar com uma coluna de data/hora.
     *
     * Sai do MESMO relógio que o now() — ver o porquê no docblock dele.
     */
    public static function nowMinusMinutes(int $minutos): string {
        return "'" . date('Y-m-d H:i:s', time() - $minutos * 60) . "'";
    }

    /**
     * "Agora" para gravar em coluna de data/hora, ou comparar com uma.
     *
     * Devolve a hora do PHP como LITERAL entre aspas — não `NOW()`, não
     * `LOCALTIMESTAMP`. Quem responde "que horas são" é a APLICAÇÃO, e não o
     * banco, porque é a aplicação que escreve todo o resto:
     *
     * - o cron de presença grava `lastaccess` com `date()` do PHP;
     * - o controller monta os limites do turno com `date()` do PHP;
     * - as notas, as menções e o fechamento de turno também.
     *
     * Perguntar a hora ao banco no meio disso mistura dois relógios. A versão
     * anterior usava `LOCALTIMESTAMP` (PG) / `NOW()` (MySQL) e isso QUEBROU em
     * produção: o PostgreSQL do ambiente roda com `timezone = UTC`, então
     * `LOCALTIMESTAMP` devolvia 15:49 enquanto a linha de presença gravada
     * pelo cron dizia 12:42. O "online nos últimos 15 minutos" comparava
     * `12:42 >= 15:34` e dava sempre falso — **todo mundo aparecia offline em
     * "Analistas Escalados neste Turno"**, mesmo com o coletor rodando de 5 em
     * 5 minutos e gravando certo.
     *
     * `LOCALTIMESTAMP` corrigia OUTRA coisa (o `now()` do PG é `timestamptz`, e
     * converter para `timestamp` usa o fuso da sessão), mas continuava sendo o
     * relógio do BANCO — e o docblock antigo descrevia exatamente este cenário
     * ("com o servidor em UTC e o cron gravando em America/Sao_Paulo…") sem
     * perceber que a correção não o cobria. O mesmo desencontro carimbava nota
     * do Diário de Bordo e `read_at` de menção 3 horas adiantados.
     *
     * Literal, e não parâmetro ligado, porque estes call sites montam SQL sem
     * um `?` sobrando (um deles é `\DBexecute()` no Module.php). O valor vem de
     * `date()` — texto gerado pelo próprio PHP, sem nada digitado por usuário.
     *
     * Efeito colateral bem-vindo: some a diferença de dialeto. Os dois bancos
     * aceitam o literal, e a data gravada passa a ser sempre a mesma
     * independentemente de como o servidor de banco está configurado.
     */
    public static function now(): string {
        return "'" . date('Y-m-d H:i:s') . "'";
    }

    /**
     * Sufixo de "insere, ou atualiza se já existe".
     *
     * `ON DUPLICATE KEY UPDATE` é MySQL; o padrão é `ON CONFLICT (col) DO
     * UPDATE SET ... = EXCLUDED....`. Repare na diferença de referência ao
     * valor que estava sendo inserido: `VALUES(col)` no MySQL, `EXCLUDED.col`
     * no PostgreSQL.
     *
     * O PostgreSQL exige saber **em qual coluna** o conflito acontece; o MySQL
     * descobre sozinho pela primeira constraint violada. Por isso `$conflito`
     * é obrigatório aqui, mesmo sem uso no ramo MySQL.
     *
     * @param string   $conflito  coluna da PK/unique que gera o conflito
     * @param string[] $atualizar colunas a sobrescrever quando já existe
     */
    public static function upsert(string $conflito, array $atualizar): string {
        if (self::isPgsql()) {
            $sets = array_map(fn($c) => "$c = EXCLUDED.$c", $atualizar);
            return ' ON CONFLICT (' . $conflito . ') DO UPDATE SET ' . implode(', ', $sets);
        }

        $sets = array_map(fn($c) => "$c = VALUES($c)", $atualizar);
        return ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
    }

    // ── Trava consultiva (advisory lock) ─────────────────────────────────
    //
    // Serve para serializar uma operação que o banco não tem como serializar
    // sozinho — o caso concreto é "conferir se este turno já foi fechado e,
    // se não foi, fechar", em que os dois passos precisam ser indivisíveis.
    //
    // **Por que não `SELECT ... FOR UPDATE`**: no MySQL ele funcionaria, pelo
    // gap lock do InnoDB, que bloqueia INSERT na faixa lida mesmo quando a
    // faixa está vazia. O PostgreSQL **não tem gap lock** — ali um SELECT que
    // não devolve linha nenhuma não trava nada, e as duas transações passariam
    // pela checagem. Escrever `FOR UPDATE` daria a impressão de estar resolvido
    // e continuaria quebrado num dos dois bancos.
    //
    // A trava é **por tentativa, sem espera**: quem não conseguir recebe uma
    // mensagem clara ("outra pessoa está fechando agora") em vez de ficar com
    // a requisição pendurada. Numa ação de um clique, isso é melhor UX e evita
    // segurar um worker do PHP-FPM.

    /**
     * SQL que tenta pegar a trava sem esperar. Devolve a coluna `travou`
     * com 1 (conseguiu) ou 0 (outra sessão está com ela).
     *
     * O `CASE WHEN` no ramo PostgreSQL não é enfeite: `pg_try_advisory_lock()`
     * devolve booleano, que vem como `t`/`f` — e `(int)'t'` é 0, ou seja, a
     * trava obtida seria lida como negada.
     */
    public static function tryLock(string $nome): string {
        if (self::isPgsql()) {
            return 'SELECT CASE WHEN pg_try_advisory_lock(' . self::lockKey($nome)
                 . ') THEN 1 ELSE 0 END AS travou';
        }

        return 'SELECT GET_LOCK(' . \zbx_dbstr(self::lockName($nome)) . ', 0) AS travou';
    }

    /** SQL que devolve a trava. Sempre chamar, inclusive em caminho de erro. */
    public static function releaseLock(string $nome): string {
        if (self::isPgsql()) {
            return 'SELECT pg_advisory_unlock(' . self::lockKey($nome) . ') AS liberou';
        }

        return 'SELECT RELEASE_LOCK(' . \zbx_dbstr(self::lockName($nome)) . ') AS liberou';
    }

    /**
     * Nome da trava no MySQL. O limite é 64 caracteres a partir do MySQL 5.7 —
     * acima disso o `GET_LOCK` dá erro, então o nome é encurtado por hash em
     * vez de truncado (truncar juntaria travas de turnos diferentes).
     */
    private static function lockName(string $nome): string {
        $completo = 'plt:' . $nome;

        return strlen($completo) <= 64 ? $completo : 'plt:' . md5($nome);
    }

    /**
     * Chave da trava no PostgreSQL, que aceita inteiro e não texto.
     *
     * `crc32()` devolve unsigned de 32 bits, dentro do bigint do PG, e é
     * estável entre processos — `hash()` do PHP não seria, com randomização
     * de hash ligada.
     *
     * Advisory lock no PostgreSQL é global ao BANCO, não à aplicação: uma
     * colisão de CRC32 com outro consumidor de advisory lock no mesmo banco
     * Zabbix serializaria duas operações sem relação. Aceito — o efeito seria
     * uma espera, não um dado errado, e o espaço de 32 bits é folgado para o
     * punhado de travas que o módulo usa.
     */
    private static function lockKey(string $nome): string {
        return (string) crc32('plt:' . $nome);
    }
}
