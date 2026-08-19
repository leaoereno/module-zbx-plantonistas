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
     * `INTERVAL 15 MINUTE` é MySQL; o padrão exige a unidade entre aspas.
     */
    public static function nowMinusMinutes(int $minutos): string {
        return self::isPgsql()
            ? "(" . self::now() . " - INTERVAL '$minutos minutes')"
            : "(NOW() - INTERVAL $minutos MINUTE)";
    }

    /**
     * "Agora" para gravar em coluna de data/hora, ou comparar com uma.
     *
     * `NOW()` do MySQL devolve `DATETIME` — hora de parede, sem fuso — e as
     * colunas do módulo são desse tipo nos dois bancos (`TIMESTAMP(0)` sem
     * fuso no PG, ver Schema.php). Mas o `now()` do PostgreSQL devolve
     * `timestamptz`, e converter para `timestamp` usa o `TimeZone` **da
     * sessão**, que ninguém configura no frontend do Zabbix: com o servidor em
     * UTC e o cron gravando em America/Sao_Paulo, o "online nos últimos 15
     * minutos" erraria por 3 horas e a nota nasceria com carimbo adiantado.
     *
     * `LOCALTIMESTAMP` é a tradução literal: hora local do servidor, sem fuso.
     * É o quarto lugar deste módulo em que fuso ia morder.
     */
    public static function now(): string {
        return self::isPgsql() ? 'LOCALTIMESTAMP' : 'NOW()';
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
}
