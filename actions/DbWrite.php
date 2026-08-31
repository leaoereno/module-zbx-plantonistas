<?php declare(strict_types = 1);

namespace Modules\Plantonistas\Actions;

/**
 * DbWrite — escrita conferida na camada nativa do Zabbix (família escala).
 *
 * ── Por que existe ───────────────────────────────────────────────────────
 *
 * `\DBexecute()` **não lança**: em erro devolve `false` (o Zabbix trata
 * internamente com `error()`). A família escala chamava a função e ignorava o
 * retorno em todos os pontos de escrita — então um INSERT que falhava seguia
 * o fluxo como se tivesse gravado, e a tela dizia "30 linhas importadas" ou
 * "5 dias salvos" sem nada ter entrado no banco. O `try/catch` que existe em
 * volta dos laços de gravação nunca disparava, porque não havia exceção
 * nenhuma para capturar.
 *
 * É o mesmo motivo pelo qual o `ZbxDb` da família repasse converte o `false`
 * em `\RuntimeException` — ali a decisão já estava tomada e documentada; aqui
 * faltava o equivalente.
 *
 * ── Duas formas, de propósito ────────────────────────────────────────────
 *
 * - `dbExec()` lança: use quando a escrita É o dado principal (a escala, o
 *   telefone). Quem chama decide o que dizer ao usuário.
 * - `dbExecOptional()` só loga: use em informação acessória (o registro de
 *   histórico), seguindo a regra do módulo de que o acessório que falha não
 *   pode derrubar o dado principal.
 *
 * A mensagem da exceção é **fixa e sem SQL**: ela chega à tela (o import a
 * ecoa na lista de avisos), e SQL não vai para a UI. O comando fica só no
 * `error_log`, com o prefixo de sempre.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
trait DbWrite {

    /**
     * Executa uma escrita e falha alto se o banco recusar.
     *
     * @throws \RuntimeException com mensagem exibível (sem SQL)
     */
    private function dbExec(string $sql, string $oque = 'gravar'): void {
        if (!\DBexecute($sql)) {
            error_log('[plantonistas] falha ao ' . $oque . ': ' . $sql);
            throw new \RuntimeException(
                'O banco de dados recusou a operação (' . $oque . '). Confira o log do PHP-FPM.'
            );
        }
    }

    /**
     * Executa uma escrita acessória: falha vira log, não exceção.
     *
     * @return bool true se gravou
     */
    private function dbExecOptional(string $sql, string $oque = 'gravar'): bool {
        if (!\DBexecute($sql)) {
            error_log('[plantonistas] falha ao ' . $oque . ' (seguindo mesmo assim): ' . $sql);
            return false;
        }

        return true;
    }
}
