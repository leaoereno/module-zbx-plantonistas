<?php
declare(strict_types=1);

/**
 * bootstrap.php — runner de testes mínimo, sem dependência de PHPUnit.
 *
 * ── Por que não PHPUnit ──────────────────────────────────────────────────
 *
 * O módulo não tem `composer.json`/`vendor/` (é um módulo Zabbix, não um
 * pacote Composer), e exigir `composer require --dev phpunit/phpunit` só
 * para rodar 4 arquivos de teste seria mais atrito do que valor — em
 * especial no frontend de produção, onde ninguém quer `composer install`
 * chegando perto do `/usr/share/zabbix/modules/`. Este runner é ~40 linhas,
 * zero dependência, roda com `php tests/run.php` em qualquer PHP 8.0+.
 *
 * ── O que é (e o que não é) testável aqui ────────────────────────────────
 *
 * Só a lógica PURA — sem `\DBselect`/`\CWebUser`/`ZbxDb` reais — é
 * testável fora do runtime do Zabbix: `UserLabel`, `PhonesFormat`, `SqlFn`
 * (que só monta string de SQL a partir de `$GLOBALS['DB']['TYPE']`, nunca
 * conecta em banco) e `TurnosReportBase::sanitizeNoteHtml()`/`sanitizeNode()`
 * (DOMDocument puro). Os controllers (`Plantao*`, `Turnos*Save`, etc.) e o
 * resto do trait (consultas com `ZbxDb $db`) dependem do framework do
 * Zabbix carregado e continuam só verificáveis no lab (ver
 * CHECKLIST-LAB.md) — não têm como ser unitários sem reescrever a
 * plataforma inteira.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */

if (!function_exists('zbx_dbstr')) {
    /**
     * Polyfill mínimo de \zbx_dbstr() (função global do Zabbix) — só para
     * SqlFn::tryLock()/releaseLock() rodarem fora do framework carregado.
     * Não é usado por nenhuma lógica de produção; produção sempre tem o
     * \zbx_dbstr() real do Zabbix disponível.
     */
    function zbx_dbstr(string $value): string {
        return "'" . addslashes($value) . "'";
    }
}

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;
$GLOBALS['__tests_skipped'] = 0;

function test_case(string $name, callable $fn): void {
    try {
        $fn();
        echo "  OK    {$name}\n";
        $GLOBALS['__tests_passed']++;
    } catch (\Throwable $e) {
        echo "  FAIL  {$name}\n";
        echo "        " . $e->getMessage() . "\n";
        $GLOBALS['__tests_failed']++;
    }
}

function skip_case(string $name, string $reason): void {
    echo "  SKIP  {$name} ({$reason})\n";
    $GLOBALS['__tests_skipped']++;
}

function assert_same($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException(
            ($msg !== '' ? "{$msg} — " : '')
            . 'esperado ' . var_export($expected, true) . ', veio ' . var_export($actual, true)
        );
    }
}

function assert_true($cond, string $msg = 'esperava true'): void {
    if ($cond !== true) {
        throw new \RuntimeException($msg);
    }
}

function assert_false($cond, string $msg = 'esperava false'): void {
    if ($cond !== false) {
        throw new \RuntimeException($msg);
    }
}

function tests_summary_and_exit(): void {
    $p = $GLOBALS['__tests_passed'];
    $f = $GLOBALS['__tests_failed'];
    $s = $GLOBALS['__tests_skipped'];
    echo "\n" . str_repeat('-', 50) . "\n";
    echo "{$p} passou(ram), {$f} falhou(aram), {$s} pulado(s).\n";
    exit($f > 0 ? 1 : 0);
}
