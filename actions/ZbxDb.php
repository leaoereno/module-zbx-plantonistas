<?php

namespace Modules\Plantonistas\Actions;

/**
 * ZbxDb — ponte entre o código da família repasse (escrito para mysqli) e a
 * camada de banco nativa do Zabbix (\DBselect / \DBexecute / \DBfetch).
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * Até a v4 o trait TurnosReportBase abria uma conexão mysqli PRÓPRIA a cada
 * request (getDb(), lendo $GLOBALS['DB']), paralela à conexão que o Zabbix já
 * tinha aberto. Isso custava uma conexão extra por request e, pior, colocava
 * metade do módulo fora da única camada do Zabbix que é agnóstica de banco —
 * o que trava o suporte a PostgreSQL (issue #1).
 *
 * Converter as ~50 consultas uma a uma para SQL interpolado à mão seria ~50
 * oportunidades de esquecer um zbx_dbstr() e criar SQL injection. Esta classe
 * faz a mesma coisa em UM lugar só, testável e revisável: recebe a consulta
 * com placeholders `?`, escapa cada valor conforme o tipo declarado no
 * bind_param() e entrega o SQL montado para o \DBselect()/\DBexecute().
 *
 * ── O que ela NÃO é ──────────────────────────────────────────────────────
 *
 * Não é uma emulação completa de mysqli. Implementa exatamente o que o módulo
 * usa: prepare/query/close/error/insert_id, e no statement
 * bind_param/execute/get_result. Qualquer método fora disso lança.
 *
 * ── Diferença de comportamento que importa ───────────────────────────────
 *
 * `\DBselect()` e `\DBexecute()` NÃO lançam exceção: em erro devolvem false
 * (o Zabbix trata internamente com trigger_error). O código do módulo, porém,
 * foi escrito contra `MYSQLI_REPORT_STRICT`, ou seja, com try/catch em volta
 * das consultas. Para que todo esse tratamento continue valendo — e para não
 * voltar ao tempo em que consulta quebrada devolvia array vazio em silêncio —
 * esta classe **lança \RuntimeException** quando a camada do Zabbix devolve
 * false. É de propósito.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class ZbxDb {

    /** Mensagem do último erro, espelhando $mysqli->error. */
    public string $error = '';

    /** O backend é PostgreSQL? Delegado ao SqlFn, que é quem decide dialeto. */
    private static function isPgsql(): bool {
        return SqlFn::isPgsql();
    }

    /**
     * Compatibilidade com $mysqli->prepare().
     *
     * @throws \RuntimeException nunca aqui — o erro só aparece no execute(),
     *         porque a camada do Zabbix não tem etapa de preparo separada.
     */
    public function prepare(string $sql): ZbxDbStmt {
        return new ZbxDbStmt($this, $sql);
    }

    /**
     * Consulta sem parâmetro, no lugar de $mysqli->query().
     *
     * Só faz sentido para SELECT — é o único uso no módulo.
     */
    public function query(string $sql): ZbxDbResult {
        $cursor = self::silenced(fn() => \DBselect($sql));

        if ($cursor === false) {
            $this->error = 'DBselect falhou: ' . $sql;
            throw new \RuntimeException($this->error);
        }

        return new ZbxDbResult($cursor);
    }

    /**
     * Executa a consulta sem deixar o Zabbix pintar o erro na tela.
     *
     * `\DBselect()`/`\DBexecute()` chamam `trigger_error()` em caso de falha, e
     * o `zbx_err_handler` do frontend transforma isso numa mensagem vermelha na
     * página — **com o texto completo da consulta**. Antes isso não acontecia:
     * o mysqli lançava exceção e o módulo tratava em silêncio.
     *
     * Manter esse banner quebraria duas regras do módulo de uma vez: dado
     * acessório que falha (turno na Presença, menções pendentes) não pode
     * poluir a tela do dado principal, e SQL não vai para a UI. O erro continua
     * chegando a quem precisa — vira exceção aqui e `error_log('[plantonistas]
     * …')` em quem chama.
     */
    public static function silenced(callable $fn) {
        set_error_handler(static fn() => true);

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Último id gerado por AUTO_INCREMENT, no lugar de $mysqli->insert_id.
     *
     * Vale porque \DBexecute() e este SELECT rodam na MESMA conexão nativa do
     * Zabbix — LAST_INSERT_ID() é por conexão.
     *
     * Cada leitura roda uma consulta: guarde numa variável em vez de ler duas
     * vezes (`if ($db->insert_id > 0) { … $db->insert_id … }` custa dois
     * SELECT). Os dois usos do módulo já fazem isso.
     *
     * O dialeto é escolhido aqui (LAST_INSERT_ID no MySQL, lastval() no
     * PostgreSQL) — é um lugar só para mudar. As demais funções de dialeto do
     * módulo vivem no SqlFn.
     */
    public function __get(string $name) {
        if ($name === 'insert_id') {
            // LAST_INSERT_ID() é MySQL; no PostgreSQL o equivalente por sessão
            // é lastval(), que devolve o último valor gerado por qualquer
            // sequence nesta conexão. Vale o mesmo cuidado dos dois lados: ler
            // logo depois do INSERT, sem outra escrita no meio.
            $sql = self::isPgsql()
                ? 'SELECT lastval() AS id'
                : 'SELECT LAST_INSERT_ID() AS id';

            // Silenciado: LAST_INSERT_ID() nunca falha, mas lastval() SIM —
            // "lastval is not yet defined in this session" quando nenhuma
            // sequence foi usada ainda. Sem a guarda, isso viraria banner
            // vermelho com SQL na tela, no meio do save de uma nota.
            $cursor = self::silenced(fn() => \DBselect($sql));

            if ($cursor === false) {
                error_log('[plantonistas] insert_id indisponível nesta sessão.');
                return 0;
            }

            $row = \DBfetch($cursor, false);

            return $row ? (int) $row['id'] : 0;
        }

        throw new \RuntimeException('ZbxDb: propriedade não suportada: ' . $name);
    }

    /**
     * No-op deliberado.
     *
     * O código chama $db->close() ao terminar, hábito da conexão própria.
     * Agora a conexão é a do Zabbix e segue sendo usada pelo resto do request
     * (renderização da página, menu, sessão) — fechar aqui derrubaria tudo
     * depois deste ponto.
     */
    public function close(): void {
    }
}
