<?php
/**
 * gen_schema.php — regenera os arquivos sql/schema.*.sql a partir do Schema.php.
 *
 * O DDL das tabelas do módulo é descrito UMA vez, em `Schema.php`, e o
 * `Module::init()` gera dele o dialeto do banco em uso. Os arquivos em `sql/`
 * existem só para instalação manual e para consulta — e por isso precisam ser
 * **gerados**, nunca editados à mão: duas cópias mantidas em paralelo divergem,
 * e a divergência não dá erro na hora, só meses depois, quando uma coluna
 * existe num ambiente e não no outro.
 *
 * Uso:
 *   php scripts/gen_schema.php            # regrava sql/schema.mysql.sql e sql/schema.pgsql.sql
 *   php scripts/gen_schema.php mysql      # imprime na tela, sem gravar
 *   php scripts/gen_schema.php pgsql
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */

require_once __DIR__ . '/../Schema.php';

use Modules\Plantonistas\Schema;

function cabecalho(string $banco): string {
    return "-- ARQUIVO GERADO — não edite à mão.\n"
         . "--\n"
         . "-- Fonte: Schema.php (descrição única das tabelas do módulo).\n"
         . "-- Regenerar: php scripts/gen_schema.php\n"
         . "--\n"
         . "-- Dialeto: $banco\n"
         . "-- Módulo:  Plantonistas (Zabbix 7.0 LTS)\n"
         . "--\n"
         . "-- Este arquivo serve para instalação manual e consulta. Em uso normal\n"
         . "-- quem cria e migra as tabelas é o Module::init(), a cada request, de\n"
         . "-- forma idempotente — não é preciso rodar nada disto à mão.\n\n";
}

function gerar(bool $pgsql): string {
    $out = cabecalho($pgsql ? 'PostgreSQL' : 'MySQL / MariaDB');

    foreach (Schema::ddl($pgsql) as $table => $stmts) {
        $out .= "-- ── $table " . str_repeat('─', max(1, 60 - strlen($table))) . "\n";
        foreach ($stmts as $stmt) {
            $out .= $stmt . ";\n";
        }
        $out .= "\n";
    }

    return $out;
}

$alvo = $argv[1] ?? '';

if ($alvo === 'mysql' || $alvo === 'pgsql') {
    echo gerar($alvo === 'pgsql');
    exit(0);
}

$dir = __DIR__ . '/../sql';

foreach (['mysql' => false, 'pgsql' => true] as $nome => $pgsql) {
    $arquivo = $dir . '/schema.' . $nome . '.sql';
    file_put_contents($arquivo, gerar($pgsql));
    echo "gerado: sql/schema.$nome.sql\n";
}
