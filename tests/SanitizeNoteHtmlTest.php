<?php
declare(strict_types=1);

require_once __DIR__ . '/../actions/TurnosReportBase.php';

/**
 * Só exercita sanitizeNoteHtml()/sanitizeNode() — as duas únicas partes
 * PURAS do trait (DOMDocument, sem \DBselect/ZbxDb). O resto do trait não é
 * testável fora do runtime do Zabbix; ver CHECKLIST-LAB.md.
 *
 * Cobre a mesma suíte que o CLAUDE.md menciona como "test_sanitizer.php, não
 * versionada — rodar de novo se mexer nessa função". Agora está versionada.
 */
final class SanitizeNoteHtmlHarness {
    use \Modules\Plantonistas\Actions\TurnosReportBase;

    /** @return array{html:string,mentioned_userids:int[]} */
    public function clean(string $html): array {
        return $this->sanitizeNoteHtml($html);
    }
}

echo "TurnosReportBase::sanitizeNoteHtml()\n";

if (!class_exists('DOMDocument')) {
    skip_case('sanitizeNoteHtml (suíte inteira)', 'extensão DOM do PHP ausente — instale php-dom/php-xml');
} else {
    $h = new SanitizeNoteHtmlHarness();

    test_case('tags permitidas passam intactas', function () use ($h) {
        // str_contains, não igualdade exata: a serialização de acento
        // (Olá) pelo DOMDocument pode variar como entidade numérica
        // conforme a versão do libxml — o que importa aqui é a tag
        // sobreviver, não o byte exato do "á".
        $r = $h->clean('<p>Ola <b>mundo</b></p>');
        assert_same('<p>Ola <b>mundo</b></p>', $r['html']);
    });

    test_case('<script> é removido, texto ao redor sobrevive', function () use ($h) {
        $r = $h->clean('<script>alert(1)</script>texto normal');
        assert_false(str_contains($r['html'], '<script'), 'script sobreviveu: ' . $r['html']);
        assert_true(str_contains($r['html'], 'texto normal'));
    });

    test_case('bypass aninhado (iframe > span onclick) é neutralizado nos dois níveis', function () use ($h) {
        // A sanitização roda em profundidade primeiro (filho antes do pai) —
        // inverter essa ordem reabriria este bypass específico.
        $r = $h->clean('<iframe><span onclick="alert(1)">x</span></iframe>');
        assert_false(str_contains($r['html'], '<iframe'), 'iframe sobreviveu: ' . $r['html']);
        assert_false(str_contains($r['html'], 'onclick'), 'onclick sobreviveu: ' . $r['html']);
        assert_true(str_contains($r['html'], 'x'), 'texto interno devia sobreviver: ' . $r['html']);
    });

    test_case('href javascript: é removido, tag e texto sobrevivem', function () use ($h) {
        $r = $h->clean('<a href="javascript:alert(1)">clique</a>');
        assert_false(str_contains($r['html'], 'javascript:'), $r['html']);
        assert_true(str_contains($r['html'], 'clique'));
    });

    test_case('href data: é removido', function () use ($h) {
        // URL simples, sem "<" dentro do valor do atributo: um "<" literal
        // ali é entrada adversarial que o parser HTML do DOMDocument trata
        // de forma menos previsível que um navegador real (ver CLAUDE.md,
        // "edge case conhecido e aceito") — não é o que este teste quer
        // cobrir, que é só a allowlist de esquema (http/https/zabbix.php).
        $r = $h->clean('<a href="data:text/plain,hello">clique</a>');
        assert_false(str_contains($r['html'], 'data:'), $r['html']);
        assert_true(str_contains($r['html'], 'clique'), $r['html']);
    });

    test_case('href http(s) é preservado e ganha target=_blank/rel=noopener', function () use ($h) {
        $r = $h->clean('<a href="https://example.com/x">link</a>');
        assert_true(str_contains($r['html'], 'href="https://example.com/x"'), $r['html']);
        assert_true(str_contains($r['html'], 'target="_blank"'), $r['html']);
        assert_true(str_contains($r['html'], 'rel="noopener noreferrer"'), $r['html']);
    });

    test_case('atributo fora da allowlist é removido, tag permanece', function () use ($h) {
        $r = $h->clean('<p style="color:red" onclick="alert(1)">texto</p>');
        assert_false(str_contains($r['html'], 'style='), $r['html']);
        assert_false(str_contains($r['html'], 'onclick'), $r['html']);
        assert_true(str_contains($r['html'], '<p>texto</p>'), $r['html']);
    });

    test_case('menção de usuário é extraída do span data-mention-userid', function () use ($h) {
        $r = $h->clean('<span data-mention-userid="42">Rafael</span>');
        assert_same([42], $r['mentioned_userids']);
    });

    test_case('menção repetida no texto não duplica no array', function () use ($h) {
        $html = '<span data-mention-userid="42">Rafael</span> e de novo '
              . '<span data-mention-userid="42">Rafael</span>';
        $r = $h->clean($html);
        assert_same([42], $r['mentioned_userids']);
    });

    test_case('data-mention-userid inválido (0 ou negativo) não vira menção', function () use ($h) {
        $r = $h->clean('<span data-mention-userid="0">x</span><span data-mention-userid="-1">y</span>');
        assert_same([], $r['mentioned_userids']);
    });

    test_case('HTML vazio devolve html vazio e sem menções', function () use ($h) {
        $r = $h->clean('');
        assert_same('', $r['html']);
        assert_same([], $r['mentioned_userids']);
    });
}
