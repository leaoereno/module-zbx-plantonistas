<?php

namespace Modules\Plantonistas\Actions;

/**
 * UserLabel — nome de exibição de um usuário do Zabbix, sem duplicar.
 *
 * ── O problema ───────────────────────────────────────────────────────────
 *
 * Vários cadastros do Zabbix têm o nome completo no campo `surname` e só o
 * primeiro nome em `name`. Concatenar os dois às cegas produz "Rafael Rafael
 * Leao Ereno" e "Erica Erica Felix de Oliveira" — casos reais em produção.
 *
 * ── A regra ──────────────────────────────────────────────────────────────
 *
 * Se um campo já contém o outro **nas bordas** (comparação sem distinguir
 * maiúscula), usa só o mais completo. Contenção no meio da string NÃO conta:
 * "Ana" dentro de "Mariana Costa" é coincidência de substring, não repetição
 * — por isso a comparação exige o espaço como limite de palavra.
 *
 * `$username` é a reserva quando os dois campos estão vazios, o que acontece
 * em conta de serviço e de integração.
 *
 * ── Por que existem três cópias desta regra ──────────────────────────────
 *
 * Este trait (família escala), o `formatUserLabel()` do `TurnosReportBase`
 * (família repasse) e o `buildFullName()` do `cron_presence_tracker.php`
 * implementam a mesma lógica. Não é descuido: o cron roda em CLI, sem carregar
 * o módulo, e as duas famílias de código do módulo são independentes de
 * propósito. Ao mexer numa, mexa nas três.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 */
trait UserLabel {

    /**
     * Nome de exibição a partir de name/surname, com o username como reserva.
     */
    private function userLabel(?string $name, ?string $surname, string $username = ''): string {
        $norm    = fn(?string $v) => trim((string) preg_replace('/\s+/', ' ', (string) $v));
        $name    = $norm($name);
        $surname = $norm($surname);

        if ($name === '' && $surname === '') {
            return trim($username);
        }
        if ($name === '') {
            return $surname;
        }
        if ($surname === '') {
            return $name;
        }

        $ln = mb_strtolower($name);
        $ls = mb_strtolower($surname);

        // surname já traz o nome completo ('Rafael' + 'Rafael Leao Ereno')
        if ($ln === $ls || str_starts_with($ls, $ln . ' ')) {
            return $surname;
        }
        // name já traz o sobrenome ('Rafael Leao Ereno' + 'Ereno')
        if (str_ends_with($ln, ' ' . $ls)) {
            return $name;
        }

        return $name . ' ' . $surname;
    }

    /**
     * Acrescenta `label` a cada linha, a partir de name/surname/username.
     *
     * Deixa a view livre de regra de negócio: ela lê `label` e imprime.
     */
    private function withUserLabel(array $rows, string $campo = 'label'): array {
        foreach ($rows as &$r) {
            $r[$campo] = $this->userLabel(
                $r['name'] ?? '', $r['surname'] ?? '', (string) ($r['username'] ?? '')
            );
        }
        unset($r);

        return $rows;
    }
}
