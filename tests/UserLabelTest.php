<?php
declare(strict_types=1);

require_once __DIR__ . '/../actions/UserLabel.php';

/**
 * Harness pública sobre o trait UserLabel (métodos são `private`, mas
 * privado-no-trait vira privado-na-classe-que-usa — acessível de dentro
 * desta classe, que é exatamente o que a family "escala" faz).
 */
final class UserLabelHarness {
    use \Modules\Plantonistas\Actions\UserLabel;

    public function label(?string $name, ?string $surname, string $username = ''): string {
        return $this->userLabel($name, $surname, $username);
    }
}

$h = new UserLabelHarness();

echo "UserLabel::userLabel()\n";

test_case('nome e sobrenome normais concatenam', function () use ($h) {
    assert_same('Rafael Ereno', $h->label('Rafael', 'Ereno'));
});

test_case('sobrenome já contém o nome completo -> usa só o sobrenome', function () use ($h) {
    // Caso real de produção: name='Rafael', surname='Rafael Leao Ereno'.
    assert_same('Rafael Leao Ereno', $h->label('Rafael', 'Rafael Leao Ereno'));
});

test_case('nome já contém o sobrenome -> usa só o nome', function () use ($h) {
    assert_same('Rafael Leao Ereno', $h->label('Rafael Leao Ereno', 'Ereno'));
});

test_case('contenção no MEIO da palavra não conta como duplicação', function () use ($h) {
    // "Ana" é substring de "Mariana Costa", mas não no limite de palavra —
    // tem que concatenar mesmo (é o caso que gerou o requisito de "borda").
    assert_same('Ana Mariana Costa', $h->label('Ana', 'Mariana Costa'));
});

test_case('nome e sobrenome vazios -> cai no username (conta de serviço)', function () use ($h) {
    assert_same('z148534', $h->label('', '', 'z148534'));
    assert_same('z148534', $h->label(null, null, 'z148534'));
});

test_case('nome e sobrenome e username vazios -> string vazia', function () use ($h) {
    assert_same('', $h->label('', '', ''));
});

test_case('só sobrenome vazio -> usa o nome', function () use ($h) {
    assert_same('Rafael', $h->label('Rafael', ''));
});

test_case('só nome vazio -> usa o sobrenome', function () use ($h) {
    assert_same('Ereno', $h->label('', 'Ereno'));
});

test_case('comparação de borda ignora maiúscula/minúscula', function () use ($h) {
    assert_same('RAFAEL LEAO ERENO', $h->label('rafael', 'RAFAEL LEAO ERENO'));
});

test_case('espaços múltiplos são normalizados antes de comparar', function () use ($h) {
    assert_same('Rafael Ereno', $h->label('  Rafael  ', '  Ereno  '));
});

test_case('nome igual ao sobrenome não duplica', function () use ($h) {
    assert_same('Erica', $h->label('Erica', 'Erica'));
});
