<?php
declare(strict_types=1);

require_once __DIR__ . '/../actions/PhonesFormat.php';

final class PhonesFormatHarness {
    use \Modules\Plantonistas\Actions\PhonesFormat;

    public function digits(string $raw): string {
        return $this->phoneDigits($raw);
    }

    public function br(string $phone): string {
        return $this->formatPhoneBr($phone);
    }
}

$h = new PhonesFormatHarness();

echo "PhonesFormat::phoneDigits() / formatPhoneBr()\n";

test_case('phoneDigits remove máscara e espaços', function () use ($h) {
    assert_same('11987654321', $h->digits('(11) 98765-4321'));
});

test_case('phoneDigits ignora letras e outros caracteres', function () use ($h) {
    assert_same('11987654321', $h->digits('ramal: 11 98765-4321 (WhatsApp)'));
});

test_case('celular com DDD (11 dígitos)', function () use ($h) {
    assert_same('(11) 98765-4321', $h->br('11987654321'));
});

test_case('fixo com DDD (10 dígitos)', function () use ($h) {
    assert_same('(11) 3456-7890', $h->br('1134567890'));
});

test_case('celular sem DDD (9 dígitos)', function () use ($h) {
    assert_same('98765-4321', $h->br('987654321'));
});

test_case('fixo sem DDD (8 dígitos)', function () use ($h) {
    assert_same('3456-7890', $h->br('34567890'));
});

test_case('0800/0300/0500 (11 dígitos, começa com 0) não vira (08)', function () use ($h) {
    // DDD brasileiro nunca começa com 0 (vai de 11 a 99) — é o que distingue
    // este caso do celular normal de 11 dígitos.
    assert_same('0800 777-1234', $h->br('08007771234'));
});

test_case('ramal curto (< 8 dígitos) volta cru, sem máscara', function () use ($h) {
    assert_same('1234', $h->br('1234'));
});

test_case('+55 colado (13 dígitos) volta cru, sem truncar', function () use ($h) {
    // Regressão: o phnMask() do JS já truncou em 11 dígitos um dia — aqui o
    // PHP nunca truncou, mas o teste documenta o contrato: fora do padrão
    // conhecido, os dígitos voltam INTEIROS.
    assert_same('5511987654321', $h->br('5511987654321'));
});

test_case('máscara já aplicada é idempotente via phoneDigits + formatPhoneBr', function () use ($h) {
    $original = '(11) 98765-4321';
    assert_same($original, $h->br($h->digits($original)));
});
