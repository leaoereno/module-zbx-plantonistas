<?php
declare(strict_types=1);

require_once __DIR__ . '/../actions/SqlFn.php';

use Modules\Plantonistas\Actions\SqlFn;

/**
 * SqlFn só MONTA string de SQL a partir de $GLOBALS['DB']['TYPE'] — nunca
 * conecta em banco nenhum, então é testável isolado. Cada teste seta o tipo
 * explicitamente (não confiar em ordem de execução entre arquivos).
 */

echo "SqlFn — dialeto MySQL x PostgreSQL\n";

test_case('isPgsql() lê $GLOBALS[DB][TYPE]', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_false(SqlFn::isPgsql());
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    assert_true(SqlFn::isPgsql());
    $GLOBALS['DB']['TYPE'] = 'postgresql'; // case-insensitive
    assert_true(SqlFn::isPgsql());
});

test_case('dateIso: MySQL usa DATE_FORMAT (%Y-%m-%d)', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_same("DATE_FORMAT(s.schedule_date, '%Y-%m-%d')", SqlFn::dateIso('s.schedule_date'));
});

test_case('dateIso: PostgreSQL usa to_char (YYYY-MM-DD)', function () {
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    assert_same("to_char(s.schedule_date, 'YYYY-MM-DD')", SqlFn::dateIso('s.schedule_date'));
});

test_case('dateTimeBr: PostgreSQL usa HH24, nunca HH sozinho', function () {
    // Regressão documentada: HH sozinho no to_char do PG é relógio de 12h —
    // 14h viraria "02" em silêncio, sem erro de SQL nenhum.
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    assert_same("to_char(created_at, 'DD/MM/YYYY HH24:MI')", SqlFn::dateTimeBr('created_at'));
});

test_case('dateTimeBr: MySQL usa DATE_FORMAT com %H (24h)', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_same("DATE_FORMAT(created_at, '%d/%m/%Y %H:%i')", SqlFn::dateTimeBr('created_at'));
});

test_case('upsert: MySQL usa ON DUPLICATE KEY UPDATE + VALUES()', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_same(
        ' ON DUPLICATE KEY UPDATE phone = VALUES(phone)',
        SqlFn::upsert('userid', ['phone'])
    );
});

test_case('upsert: PostgreSQL usa ON CONFLICT + EXCLUDED', function () {
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    assert_same(
        ' ON CONFLICT (userid) DO UPDATE SET phone = EXCLUDED.phone',
        SqlFn::upsert('userid', ['phone'])
    );
});

test_case('upsert: múltiplas colunas mantêm a ordem', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_same(
        ' ON DUPLICATE KEY UPDATE a = VALUES(a), b = VALUES(b)',
        SqlFn::upsert('id', ['a', 'b'])
    );
});

test_case('now(): literal do relógio do PHP, não do banco', function () {
    // Era LOCALTIMESTAMP (PG) / NOW() (MySQL) — o relógio do BANCO. Num
    // ambiente com o PostgreSQL em UTC e a aplicação em America/Sao_Paulo, o
    // "online nos últimos 15 min" comparava 12:42 com 15:34 e dava sempre
    // falso. Ver o docblock do método.
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    $agora = SqlFn::now();
    assert_true((bool) preg_match("/^'\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}'$/", $agora), $agora);
    assert_true(abs(strtotime(trim($agora, "'")) - time()) <= 2, $agora);
});

test_case('now(): mesmo valor nos dois bancos (o literal não tem dialeto)', function () {
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    $pg = SqlFn::now();
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    assert_same($pg, SqlFn::now());
});

test_case('nowMinusMinutes(): N minutos antes do now(), no mesmo relógio', function () {
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    $limite = SqlFn::nowMinusMinutes(15);
    assert_true((bool) preg_match("/^'\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}'$/", $limite), $limite);
    $delta = time() - strtotime(trim($limite, "'"));
    assert_true($delta >= 15 * 60 - 2 && $delta <= 15 * 60 + 2, 'delta=' . $delta);
});

test_case('tryLock: PostgreSQL embrulha em CASE WHEN (booleano t/f não é (int) direto)', function () {
    $GLOBALS['DB']['TYPE'] = 'POSTGRESQL';
    $sql = SqlFn::tryLock('fechar-turno:1:2026-08-24');
    assert_true(strpos($sql, 'CASE WHEN pg_try_advisory_lock(') !== false, $sql);
    assert_true(strpos($sql, 'THEN 1 ELSE 0 END') !== false, $sql);
});

test_case('tryLock: MySQL usa GET_LOCK com timeout 0 (sem espera)', function () {
    $GLOBALS['DB']['TYPE'] = 'MYSQL';
    $sql = SqlFn::tryLock('fechar-turno:1:2026-08-24');
    assert_true(strpos($sql, 'GET_LOCK(') !== false, $sql);
    assert_true(strpos($sql, ', 0)') !== false, 'timeout devia ser 0 (sem espera): ' . $sql);
});

unset($GLOBALS['DB']);
