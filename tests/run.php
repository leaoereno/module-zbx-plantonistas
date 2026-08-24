<?php
declare(strict_types=1);

/**
 * tests/run.php — roda toda a suíte de testes puros do módulo.
 *
 *     php tests/run.php
 *
 * Sai com código 0 se tudo passou (ou só pulou), 1 se algo falhou — dá pra
 * usar num pipeline de CI ou num hook de pre-commit local.
 *
 * Só cobre lógica PURA (sem framework do Zabbix carregado): UserLabel,
 * PhonesFormat, SqlFn e o sanitizador de HTML do Diário de Bordo. Validação
 * de tela/permissão/DB de verdade continua sendo o lab (CHECKLIST-LAB.md) —
 * não tem como isso ser unitário sem simular o Zabbix inteiro.
 */

require_once __DIR__ . '/bootstrap.php';

$suites = [
    __DIR__ . '/UserLabelTest.php',
    __DIR__ . '/PhonesFormatTest.php',
    __DIR__ . '/SqlFnTest.php',
    __DIR__ . '/SanitizeNoteHtmlTest.php',
];

foreach ($suites as $suite) {
    echo "\n== " . basename($suite) . " ==\n";
    require $suite;
}

tests_summary_and_exit();
