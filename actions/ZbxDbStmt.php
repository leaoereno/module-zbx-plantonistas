<?php

namespace Modules\Plantonistas\Actions;

/**
 * ZbxDbStmt — statement com placeholders `?` sobre a camada nativa do Zabbix.
 *
 * Substitui mysqli_stmt no módulo. A camada do Zabbix não tem prepared
 * statement: a consulta é montada aqui, com cada valor escapado conforme o
 * tipo declarado no bind_param(). Ver ZbxDb para o porquê de existir.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class ZbxDbStmt {

    private ZbxDb $db;
    private string $sql;
    private string $types = '';
    private array $values = [];
    private $cursor = null;

    public function __construct(ZbxDb $db, string $sql) {
        $this->db  = $db;
        $this->sql = $sql;
    }

    /**
     * Compatível com mysqli_stmt::bind_param().
     *
     * `$types` é a mesma string de sempre: i = inteiro, d = decimal,
     * s = string, b = blob (tratado como string).
     */
    // Sem `&` de propósito, ao contrário do mysqli: ele exige referência
    // porque só lê as variáveis no execute(), enquanto aqui o valor é
    // congelado no bind. Aceitar valor em vez de referência é estritamente
    // mais permissivo — uma chamada como bind_param('i', (int) $x), que no
    // mysqli seria erro fatal de "pass by reference", passa aqui.
    public function bind_param(string $types, ...$values): bool {
        if (strlen($types) !== count($values)) {
            throw new \RuntimeException(
                'ZbxDbStmt::bind_param — ' . strlen($types) . ' tipos para '
                . count($values) . ' valores: ' . $this->sql
            );
        }

        $this->types = $types;
        // Cópia por valor de propósito: mysqli lê as variáveis no execute(),
        // aqui elas são congeladas no bind. Nenhum ponto do módulo altera a
        // variável entre bind e execute, então o resultado é o mesmo — e evita
        // o efeito à distância que o bind por referência do mysqli permite.
        $this->values = $values;

        return true;
    }

    /**
     * Monta a consulta final e executa.
     *
     * SELECT vai para \DBselect() (o cursor fica guardado para get_result());
     * o resto vai para \DBexecute().
     *
     * @throws \RuntimeException se a camada do Zabbix devolver false — ver a
     *         explicação em ZbxDb sobre por que lançar em vez de retornar.
     */
    public function execute(): bool {
        $sql = $this->buildSql();

        if (preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql)) {
            $cursor = ZbxDb::silenced(fn() => \DBselect($sql));

            if ($cursor === false) {
                $this->db->error = 'DBselect falhou: ' . $sql;
                throw new \RuntimeException($this->db->error);
            }

            $this->cursor = $cursor;

            return true;
        }

        if (ZbxDb::silenced(fn() => \DBexecute($sql)) === false) {
            $this->db->error = 'DBexecute falhou: ' . $sql;
            throw new \RuntimeException($this->db->error);
        }

        $this->cursor = null;

        return true;
    }

    public function get_result(): ZbxDbResult {
        return new ZbxDbResult($this->cursor);
    }

    /** No-op: não há recurso de statement para liberar. */
    public function close(): void {
    }

    /**
     * Troca cada `?` pelo valor escapado, na ordem.
     *
     * O varredor ignora `?` dentro de literal de string (entre aspas simples
     * ou duplas), respeitando escape com barra invertida e a duplicação de
     * aspa ('' dentro de string). Hoje nenhuma consulta do módulo tem `?`
     * dentro de literal, mas uma consulta futura pode ter — e um parser que
     * só faz str_replace trocaria o caractere errado sem avisar.
     */
    private function buildSql(): string {
        $out    = '';
        $i      = 0;                 // índice do próximo parâmetro
        $len    = strlen($this->sql);
        $quote  = null;              // aspa que abriu o literal atual

        for ($p = 0; $p < $len; $p++) {
            $ch = $this->sql[$p];

            if ($quote !== null) {
                $out .= $ch;

                if ($ch === '\\' && $p + 1 < $len) {
                    $out .= $this->sql[++$p];       // caractere escapado
                }
                elseif ($ch === $quote) {
                    $quote = null;                   // fim do literal
                }

                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out  .= $ch;
                continue;
            }

            if ($ch === '?') {
                if (!array_key_exists($i, $this->values)) {
                    throw new \RuntimeException(
                        'ZbxDbStmt: mais placeholders que valores em: ' . $this->sql
                    );
                }
                $out .= $this->escape($this->types[$i] ?? 's', $this->values[$i]);
                $i++;
                continue;
            }

            $out .= $ch;
        }

        if ($i !== count($this->values)) {
            throw new \RuntimeException(
                'ZbxDbStmt: ' . count($this->values) . ' valores para ' . $i
                . ' placeholders em: ' . $this->sql
            );
        }

        return $out;
    }

    /**
     * Escapa um valor para inclusão direta no SQL.
     *
     * NULL vira NULL literal, não string vazia — é o que o bind do mysqli faz,
     * e o módulo depende disso (noc_context e shift_id nulos, por exemplo).
     */
    private function escape(string $type, $value): string {
        if ($value === null) {
            return 'NULL';
        }

        switch ($type) {
            case 'i':
                return (string) (int) $value;

            case 'd':
                return (string) (float) $value;

            case 's':
            case 'b':
            default:
                return \zbx_dbstr((string) $value);
        }
    }
}
