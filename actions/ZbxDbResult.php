<?php

namespace Modules\Plantonistas\Actions;

/**
 * ZbxDbResult — resultado de consulta sobre o cursor do \DBselect().
 *
 * Substitui mysqli_result no módulo, com os três métodos que ele usa:
 * fetch_all(), fetch_assoc() e fetch_row(). Ver ZbxDb para o contexto.
 *
 * As três leituras passam `false` como segundo argumento do \DBfetch(). Sem
 * isso o Zabbix converte **toda coluna NULL na string '0'** (o default de
 * `$convertNulls` é true). O módulo foi escrito contra o mysqli, que devolve
 * null de verdade — e colunas como `shift_id`, `notes_format` e `last_seen`
 * são anuláveis. Hoje nenhum ponto quebraria com o '0', mas o primeiro
 * `=== null` ou `?? 'text'` escrito depois quebraria em silêncio.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class ZbxDbResult {

    private $cursor;

    public function __construct($cursor) {
        $this->cursor = $cursor;
    }

    /**
     * Todas as linhas.
     *
     * O parâmetro existe só para o código chamador continuar passando
     * MYSQLI_ASSOC sem mudança; o retorno é sempre associativo, que é o que
     * \DBfetch() entrega e o único modo usado no módulo.
     *
     * O parâmetro sobrou da API do mysqli e hoje nenhuma chamada o usa —
     * mantido só para não quebrar código externo que ainda passe MYSQLI_ASSOC.
     * O default é o literal 1 (valor da constante) de propósito: constante em
     * assinatura é avaliada ao carregar a classe, e MYSQLI_ASSOC só existe se
     * a extensão mysqli estiver instalada — e parar de depender dela é
     * justamente um dos objetivos da issue #1.
     */
    public function fetch_all(int $mode = 1): array {
        $rows = [];

        if ($this->cursor === null) {
            return $rows;
        }

        while ($row = \DBfetch($this->cursor, false)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** Próxima linha como array associativo, ou null quando acabou. */
    public function fetch_assoc(): ?array {
        if ($this->cursor === null) {
            return null;
        }

        $row = \DBfetch($this->cursor, false);

        return $row === false ? null : $row;
    }

    /** Próxima linha como array indexado por posição, ou null. */
    public function fetch_row(): ?array {
        $row = $this->fetch_assoc();

        return $row === null ? null : array_values($row);
    }
}
