<?php

/**
 * CliDb — conexão de banco dos scripts CLI, sobre PDO, nos dois dialetos.
 *
 * ── Por que existe (issue #1) ────────────────────────────────────────────
 *
 * Os dois cron (`cron_presence_tracker.php` e `cron_sync_oncall.php`) rodam
 * fora do frontend: não há `$GLOBALS['DB']` nem as funções `\DBselect()` do
 * Zabbix, então o `ZbxDb` — que é a ponte usada pelas telas — não serve aqui.
 * Eles abriam `mysqli` direto, o que travava o suporte a PostgreSQL.
 *
 * Esta classe faz para o CLI o que o `ZbxDb` faz para o frontend: expõe a
 * fatia da API do mysqli que os scripts já usam, por cima do PDO. Nenhuma das
 * ~40 chamadas dos dois scripts precisou ser reescrita — muda a conexão, não
 * as consultas.
 *
 * ── DSN ──────────────────────────────────────────────────────────────────
 *
 * O dialeto vem da env `DB_TYPE` (`mysql` ou `pgsql`; default `mysql`), e a
 * porta default acompanha: 3306 ou 5432. É a única configuração nova no
 * arquivo de cron.
 *
 * ── Diferenças que o adaptador resolve ───────────────────────────────────
 *
 * - `bind_param('is', $a, $b)` vira array posicional do PDO. A string de tipos
 *   é aceita e **ignorada**: o PDO infere, e reescrever as 16 chamadas dos dois
 *   scripts só para tirar um argumento seria diff sem ganho.
 * - `fetch_all(MYSQLI_ASSOC)` vira `fetchAll(PDO::FETCH_ASSOC)`; o argumento é
 *   aceito e ignorado, porque associativo é o único modo usado.
 * - `affected_rows` vira `rowCount()`.
 * - `ERRMODE_EXCEPTION` reproduz o `MYSQLI_REPORT_STRICT` que os scripts
 *   esperam: todo o tratamento de erro deles continua valendo sem mudança.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
class CliDb {

    private \PDO $pdo;
    private bool $pgsql;

    public function __construct(string $host, string $user, string $pass,
                                string $dbname, int $port, string $tipo = 'mysql') {
        $this->pgsql = (strtolower($tipo) === 'pgsql' || strtolower($tipo) === 'postgresql');

        $dsn = $this->pgsql
            ? "pgsql:host=$host;port=$port;dbname=$dbname;options='--client_encoding=UTF8'"
            : "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";

        $this->pdo = new \PDO($dsn, $user, $pass, [
            // Equivale ao MYSQLI_REPORT_STRICT que os scripts assumem.
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            // Prepared statement de verdade, no servidor: sem isso o driver
            // do MySQL emula e o `?` volta a ser substituição de texto.
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public function isPgsql(): bool {
        return $this->pgsql;
    }

    public function prepare(string $sql): CliDbStmt {
        return new CliDbStmt($this->pdo->prepare($sql));
    }

    /** Consulta sem parâmetro. */
    public function query(string $sql): CliDbResult {
        return new CliDbResult($this->pdo->query($sql));
    }

    /** Compatível com $mysqli->begin_transaction(). */
    public function begin_transaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    /**
     * Desfaz a transação.
     *
     * O `rollBack()` do PDO **lança** se não houver transação ativa, ao
     * contrário do mysqli, que só devolve false. Como o único chamador está
     * num `catch` — desfazendo o que causou a exceção —, deixar isso lançar
     * trocaria a exceção original (a que diz o que aconteceu de verdade) por
     * "There is no active transaction". Daí a guarda.
     */
    public function rollback(): bool {
        if (!$this->pdo->inTransaction()) {
            return false;
        }

        return $this->pdo->rollBack();
    }

    /**
     * "Agora" em SQL, por dialeto.
     *
     * `NOW()` do PostgreSQL devolve `timestamptz` e a conversão para as colunas
     * `TIMESTAMP` do módulo usaria o fuso da sessão. Mesmo motivo do
     * `SqlFn::now()` do frontend — ver o comentário lá.
     */
    public function now(): string {
        return $this->pgsql ? 'LOCALTIMESTAMP' : 'NOW()';
    }

    /** Filtro de schema do INFORMATION_SCHEMA (`DATABASE()` é MySQL). */
    public function schemaFilter(): string {
        return $this->pgsql ? 'current_schema()' : 'DATABASE()';
    }

    /**
     * No-op: o PDO fecha ao sair de escopo. Existe só para os scripts poderem
     * seguir chamando `$db->close()`.
     */
    public function close(): void {
    }
}

/** Statement: recebe `bind_param` no formato do mysqli e executa via PDO. */
class CliDbStmt {

    private \PDOStatement $stmt;
    private array $valores = [];

    public function __construct(\PDOStatement $stmt) {
        $this->stmt = $stmt;
    }

    /**
     * A string de tipos ('i', 'ss', 'isssss'...) é do mysqli — e aqui ela NÃO
     * é decorativa.
     *
     * Com `ATTR_EMULATE_PREPARES => false`, passar os valores direto para o
     * `execute([...])` faz o PDO enviar **tudo como string**. No MySQL isso
     * passa (ele coage sozinho), mas o PostgreSQL é estrito: comparar
     * `bigint = text` é erro — `operator does not exist`. Como os dois scripts
     * comparam userid, usrgrpid, shift_id e timestamps com `= ?`, ignorar os
     * tipos deixaria o ramo PostgreSQL quebrado em quase toda consulta.
     *
     * Por isso cada valor é ligado com `bindValue()` e o tipo declarado:
     * `i` → PARAM_INT, `d` → string (o PDO não tem PARAM_FLOAT; numérico como
     * string é aceito nos dois bancos), `s`/`b` → PARAM_STR. NULL sempre vai
     * como PARAM_NULL, senão viraria a string vazia.
     */
    public function bind_param(string $types, ...$valores): bool {
        $this->valores = [];

        foreach ($valores as $i => $v) {
            $tipo = $types[$i] ?? 's';

            if ($v === null) {
                $pdoTipo = \PDO::PARAM_NULL;
            }
            elseif ($tipo === 'i') {
                $pdoTipo = \PDO::PARAM_INT;
                $v = (int) $v;
            }
            else {
                $pdoTipo = \PDO::PARAM_STR;
            }

            // Posicional do PDO começa em 1, não em 0.
            $this->stmt->bindValue($i + 1, $v, $pdoTipo);
            $this->valores[] = $v;
        }

        return true;
    }

    public function execute(): bool {
        // Sem argumento: os valores já foram ligados por bindValue() no
        // bind_param(). Passar o array aqui sobrescreveria os tipos e
        // devolveria tudo para string, que é justamente o que se quer evitar.
        $ok = $this->stmt->execute();
        $this->valores = [];

        return $ok;
    }

    public function get_result(): CliDbResult {
        return new CliDbResult($this->stmt);
    }

    /** Compatível com $stmt->affected_rows (INSERT/UPDATE/DELETE). */
    public function __get(string $nome) {
        if ($nome === 'affected_rows') {
            return $this->stmt->rowCount();
        }

        throw new \RuntimeException('CliDbStmt: propriedade não suportada: ' . $nome);
    }

    public function close(): void {
        $this->stmt->closeCursor();
    }
}

/** Resultado de consulta. */
class CliDbResult {

    private $stmt;

    public function __construct($stmt) {
        $this->stmt = $stmt;
    }

    /** O modo é ignorado: associativo é o único usado (ver o cabeçalho). */
    public function fetch_all(int $mode = 1): array {
        return $this->stmt ? $this->stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    public function fetch_assoc(): ?array {
        if (!$this->stmt) {
            return null;
        }
        $row = $this->stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetch_row(): ?array {
        $row = $this->fetch_assoc();

        return $row === null ? null : array_values($row);
    }

    /**
     * Compatível com $result->num_rows.
     *
     * ATENÇÃO: `rowCount()` **não é confiável para SELECT** em vários drivers
     * PDO — inclusive o do PostgreSQL, dependendo da versão. Os dois scripts só
     * usam isso para "a tabela existe?", então aqui a contagem é feita
     * materializando as linhas, que é correto em qualquer driver e barato para
     * um resultado de uma linha.
     */
    public function __get(string $nome) {
        if ($nome === 'num_rows') {
            return count($this->fetch_all());
        }

        throw new \RuntimeException('CliDbResult: propriedade não suportada: ' . $nome);
    }
}
