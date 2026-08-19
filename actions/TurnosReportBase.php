<?php

namespace Modules\Plantonistas\Actions;

/**
 * TurnosReportBase
 *
 * Trait compartilhado entre TurnosReportView e TurnosReportPdf.
 *
 * ── Segmentação automática ───────────────────────────────────
 * A visibilidade é derivada diretamente do Zabbix, sem configuração manual.
 *
 * Eventos / alertas:
 *   Filtrados pela tabela `rights` quando configurada.
 *   Se o usuário não tiver rights (ambiente sem permissões por host),
 *   mostra todos os eventos (sem filtro).
 *
 * Notas e Presença:
 *   Segmentados por grupo de usuário (`users_groups`).
 *   Um usuário vê apenas dados de outros usuários no mesmo grupo.
 *   Não depende de `rights` — funciona com qualquer configuração do Zabbix.
 *
 * Super Admin (role.type=3) ou Admin sem rights (role.type=2, rights vazias):
 *   Sem nenhum filtro — vê tudo.
 *
 * Mantido por Rafael M. A. Leão Ereno (MALE)
 * Fork de: https://github.com/JohnnyIver/zabbix-report-module
 *
 * Fix v2.2.1: corrigidas queries incompatíveis com sql_mode=ONLY_FULL_GROUP_BY
 *   - queryInheritedAlerts: MIN() em colunas de JOIN fora do GROUP BY
 *   - queryUnackedAlerts:   MIN() em colunas de JOIN fora do GROUP BY
 */
trait TurnosReportBase {

    // ── Conexão ──────────────────────────────────────────────

    /**
     * Handle de banco do módulo.
     *
     * Já foi uma conexão mysqli própria, aberta a cada request em paralelo à
     * do Zabbix, lendo credencial de $GLOBALS['DB']. Agora devolve o ZbxDb,
     * que fala com a conexão nativa (\DBselect / \DBexecute) — uma conexão a
     * menos por request e, principalmente, o módulo inteiro passando pela
     * única camada do Zabbix que é agnóstica de banco (issue #1, PostgreSQL).
     *
     * O retorno segue anulável e os chamadores seguem checando null: o
     * contrato não mudou. Na prática, sem conexão do Zabbix a página nem
     * chegaria a ser roteada, então o ramo de erro virou defesa.
     */
    private function getDb(): ?ZbxDb {
        try {
            return new ZbxDb();
        } catch (\Throwable $e) {
            // Antes essa falha era só um retorno nulo silencioso — a tela de
            // Gerenciar Turnos aparecia normal, mas todo grupo vinha vazio,
            // sem nenhum rastro de que a conexão nem chegou a abrir.
            error_log('[plantonistas] getDb() falhou: ' . $e->getMessage());
            return null;
        }
    }

    // ── Validação de data ────────────────────────────────────

    private function validateDate(string $date): string {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            [$y, $m, $d] = explode('-', $date);
            if (checkdate((int)$m, (int)$d, (int)$y)) {
                return $date;
            }
        }
        return date('Y-m-d');
    }

    // ── Janelas de turno ─────────────────────────────────────
    //
    // Turnos legados (24h/manha/tarde/noite) continuam com janelas fixas,
    // por compatibilidade. Turnos cadastrados em module_plantonistas_shifts (v2.5+) são
    // identificados por um shift_id numérico e têm a janela calculada a
    // partir de start_time/end_time — com detecção automática de virada de
    // meia-noite (quando end_time <= start_time).

    private function getShiftBounds(ZbxDb $db, string $date, string $shift): array {
        $date = $this->validateDate($date);
        switch ($shift) {
            case 'manha':
                return [strtotime("$date 07:00:00"), strtotime("$date 12:59:59")];
            case 'tarde':
                return [strtotime("$date 13:00:00"), strtotime("$date 18:59:59")];
            case 'noite':
                if ($date === date('Y-m-d') && (int)date('H') < 7) {
                    $date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
                }
                $next = date('Y-m-d', strtotime("$date +1 day"));
                return [strtotime("$date 19:00:00"), strtotime("$next 06:59:59")];
            case '24h':
                return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
            default:
                if (ctype_digit($shift)) {
                    $row = $this->getShiftById($db, (int)$shift);
                    if ($row !== null) {
                        return $this->computeCustomShiftBounds(
                            $date, $row['start_time'], $row['end_time']
                        );
                    }
                }
                return [strtotime("$date 00:00:00"), strtotime("$date 23:59:59")];
        }
    }

    /**
     * Calcula início/fim (timestamps) de um turno cadastrado, tratando a
     * virada de meia-noite igual ao turno "noite" legado: se end_time <=
     * start_time, o turno cruza para o dia seguinte.
     */
    private function computeCustomShiftBounds(string $date, string $start, string $end): array {
        $crossesMidnight = strtotime($end) <= strtotime($start);

        if (!$crossesMidnight) {
            return [strtotime("$date $start"), strtotime("$date $end") - 1];
        }

        // Se estamos vendo "hoje" e o horário atual ainda está dentro da
        // cauda do turno (antes de end_time), o turno começou ontem.
        if ($date === date('Y-m-d') && strtotime(date('H:i:s')) < strtotime($end)) {
            $date = date('Y-m-d', strtotime('-1 day', strtotime($date)));
        }
        $next = date('Y-m-d', strtotime("$date +1 day"));
        return [strtotime("$date $start"), strtotime("$next $end") - 1];
    }

    private function getShiftById(ZbxDb $db, int $shiftId): ?array {
        try {
            $stmt = $db->prepare(
                "SELECT id, usrgrpid, name, start_time, end_time, sort_order, active
                 FROM module_plantonistas_shifts WHERE id = ?"
            );
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Monta as opções do seletor de turno exibidas ao usuário: os 4 turnos
     * legados (sempre presentes, por compatibilidade) + os turnos
     * cadastrados em module_plantonistas_shifts para o(s) grupo(s) do usuário.
     *
     * Super Admin vê os turnos cadastrados de TODAS as equipes (rotulados
     * com o nome do grupo, já que pode navegar entre equipes diferentes).
     * Demais usuários veem apenas os turnos do(s) próprio(s) grupo(s).
     *
     * Retorna ['options' => [codigo => rotulo, ...], 'rows' => [id => linha]]
     */
    /**
     * Rótulos dos 4 turnos legados (24h/manhã/tarde/noite), sempre disponíveis
     * por compatibilidade. Fonte única — reutilizado por queryShiftOptions()
     * e exposto à view de Gerenciar Turnos, que antes citava esses turnos no
     * texto de apoio sem mostrar em lugar nenhum quais são ou seus horários.
     */
    private function legacyShiftLabels(): array {
        return [
            '24h'   => '24 Horas (Dia Inteiro)',
            'manha' => 'Manhã (07h–13h)',
            'tarde' => 'Tarde (13h–19h)',
            'noite' => 'Noite (19h–07h)',
        ];
    }

    private function queryShiftOptions(ZbxDb $db, array $ctx): array {
        $options = $this->legacyShiftLabels();
        $rows = [];

        try {
            if (!empty($ctx['is_superadmin'])) {
                $sql = "SELECT cs.id, cs.usrgrpid, cs.name, cs.start_time, cs.end_time,
                            ug.name AS group_name
                        FROM module_plantonistas_shifts cs
                        INNER JOIN usrgrp ug ON ug.usrgrpid = cs.usrgrpid
                        WHERE cs.active = 1
                        ORDER BY ug.name ASC, cs.sort_order ASC, cs.start_time ASC";
                $res = $db->query($sql);
                $shifts = $res ? $res->fetch_all() : [];
            } else {
                $groupIds = $ctx['group_ids'] ?? [];
                if (empty($groupIds)) {
                    return ['options' => $options, 'rows' => $rows];
                }
                $ids = implode(',', array_map('intval', $groupIds));
                $sql = "SELECT cs.id, cs.usrgrpid, cs.name, cs.start_time, cs.end_time,
                            ug.name AS group_name
                        FROM module_plantonistas_shifts cs
                        INNER JOIN usrgrp ug ON ug.usrgrpid = cs.usrgrpid
                        WHERE cs.active = 1 AND cs.usrgrpid IN ($ids)
                        ORDER BY cs.sort_order ASC, cs.start_time ASC";
                $res = $db->query($sql);
                $shifts = $res ? $res->fetch_all() : [];
            }

            foreach ($shifts as $s) {
                $label = substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5);
                $prefix = !empty($ctx['is_superadmin']) ? '[' . $s['group_name'] . '] ' : '';
                $options[$s['id']] = $prefix . $s['name'] . ' (' . $label . ')';
                $rows[(int)$s['id']] = $s;
            }
        } catch (\Exception $e) {}

        return ['options' => $options, 'rows' => $rows];
    }

    /**
     * Garante que o parâmetro shift recebido é válido: um código legado
     * conhecido ou um ID numérico presente nas opções do usuário. Caso
     * contrário, cai para '24h' (comportamento seguro).
     */
    private function normalizeShift(string $shiftRaw, array $shiftOptions): string {
        return array_key_exists($shiftRaw, $shiftOptions) ? $shiftRaw : '24h';
    }

    // ── Analistas escalados no turno selecionado ─────────────

    private function queryShiftAnalysts(ZbxDb $db, int $shiftId): array {
        $sql = "SELECT u.userid, u.username,
                    CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                    online.last_seen,
                    CASE WHEN online.last_seen IS NOT NULL
                              AND online.last_seen >= (NOW() - INTERVAL 15 MINUTE)
                         THEN 1 ELSE 0 END AS is_online
                FROM module_plantonistas_user_shift cush
                INNER JOIN users u ON u.userid = cush.userid
                LEFT JOIN (
                    SELECT userid, MAX(lastaccess) AS last_seen
                    FROM module_plantonistas_user_sessions
                    GROUP BY userid
                ) online ON online.userid = u.userid
                WHERE cush.shift_id = ?
                ORDER BY fullname ASC";

        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('i', $shiftId);
            $stmt->execute();
            return $stmt->get_result()->fetch_all();
        } catch (\Exception $e) {
            return [];
        }
    }

    // ── Resolução de contexto ─────────────────────────────────

    private function getUserRoleType(ZbxDb $db, int $userid): int {
        try {
            $stmt = $db->prepare(
                "SELECT r.type AS user_type
                 FROM users u
                 INNER JOIN role r ON r.roleid = u.roleid
                 WHERE u.userid = ?"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            if ($row !== false && $row !== null) {
                return (int)$row['user_type'];
            }
        } catch (\Exception $e) {}

        try {
            $stmt = $db->prepare("SELECT type AS user_type FROM users WHERE userid = ?");
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return $row ? (int)$row['user_type'] : 1;
        } catch (\Exception $e) {
            return 1;
        }
    }

    private function resolveUserContext(ZbxDb $db, int $userid): array {
        $result = [
            'is_superadmin'  => false,
            'host_filter'    => '',
            'display_groups' => [],
            'group_ids'      => [],
            'role_type'      => 1,
            'userid'         => $userid,
        ];

        $roleType = $this->getUserRoleType($db, $userid);
        $result['role_type'] = $roleType;

        // Grupos do usuário (necessário mesmo para Super Admin — usado para
        // escopar o seletor de turnos e a tela de gerenciamento).
        try {
            $stmt = $db->prepare(
                "SELECT ug.usrgrpid
                 FROM usrgrp ug
                 INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                 WHERE ugm.userid = ?"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            $result['group_ids'] = array_map(
                fn($r) => (int)$r['usrgrpid'],
                $stmt->get_result()->fetch_all()
            );
        } catch (\Throwable $e) {
            error_log('[plantonistas] resolveUserContext() group_ids falhou (userid=' . $userid . '): ' . $e->getMessage());
        }

        if ($roleType === 3) {
            $result['is_superadmin'] = true;
        }

        // Super Admin não tem filtro de host — mantém o mesmo comportamento
        // de antes (sem consultar `rights`).
        if (!$result['is_superadmin']) {
            try {
                $stmt = $db->prepare(
                    "SELECT DISTINCT hg.hostid
                     FROM hosts_groups hg
                     INNER JOIN rights r        ON r.id        = hg.groupid
                     INNER JOIN users_groups ug ON ug.usrgrpid = r.groupid
                     WHERE ug.userid = ? AND r.permission >= 2"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $hostIds = array_map(
                    fn($r) => (int)$r['hostid'],
                    $stmt->get_result()->fetch_all()
                );
                $result['host_filter'] = empty($hostIds)
                    ? ''
                    : 'AND h.hostid IN (' . implode(',', $hostIds) . ')';
            } catch (\Exception $e) {}
        }

        // Label de exibição (badge no header): mantém o comportamento original
        // — Super Admin não exibe badge de equipe (vê tudo, sem contexto único).
        if (!$result['is_superadmin']) {
            try {
                $stmt = $db->prepare(
                    "SELECT ug.name
                     FROM usrgrp ug
                     INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                     WHERE ugm.userid = ?
                     ORDER BY ug.usrgrpid ASC"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $result['display_groups'] = array_column(
                    $stmt->get_result()->fetch_all(),
                    'name'
                );
            } catch (\Exception $e) {}
        }

        return $result;
    }

    // ── Segmentação por grupo de usuário ─────────────────────

    private function sameGroupExists(int $userid, string $authorIdColumn): string {
        return "EXISTS (
            SELECT 1
            FROM users_groups ug_reader
            JOIN users_groups ug_author
                ON ug_author.usrgrpid = ug_reader.usrgrpid
            WHERE ug_reader.userid = $userid
              AND ug_author.userid  = $authorIdColumn
        )";
    }

    // ── Queries ──────────────────────────────────────────────

    private function queryMTTA(ZbxDb $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT sub.userid, sub.username, sub.fullname,
                    COUNT(*) AS total_acks,
                    ROUND(AVG(sub.mtta), 0) AS avg_mtta,
                    MIN(sub.mtta) AS min_mtta,
                    MAX(sub.mtta) AS max_mtta
                FROM (
                    SELECT a.userid, u.username,
                        CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                        a.eventid,
                        (a.clock - ev.clock) AS mtta
                    FROM acknowledges a
                    INNER JOIN events ev   ON ev.eventid  = a.eventid
                    INNER JOIN users u     ON u.userid    = a.userid
                    INNER JOIN triggers t  ON t.triggerid = ev.objectid
                    INNER JOIN functions f ON f.triggerid = t.triggerid
                    INNER JOIN items i     ON i.itemid    = f.itemid
                    INNER JOIN hosts h     ON h.hostid    = i.hostid
                    WHERE ev.source = 0 AND ev.object = 0
                      AND ev.clock BETWEEN ? AND ?
                      AND LEFT(u.username, 4) <> 'api_'
                      AND a.acknowledgeid = (
                          SELECT MIN(a2.acknowledgeid)
                          FROM acknowledges a2
                          WHERE a2.eventid = a.eventid
                      )
                      $hostFilter
                ) sub
                GROUP BY sub.userid, sub.username, sub.fullname
                ORDER BY avg_mtta ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    /**
     * FIX ONLY_FULL_GROUP_BY:
     * Colunas de tabelas joined (h.name, h.host, t.description) não são
     * funcionalmente dependentes de e.eventid na visão do otimizador MySQL.
     * Solução: MIN() em vez de ANY_VALUE() — compatível com MariaDB < 10.2 (determinístico neste
     * contexto pois eventid→host é 1-para-1 via trigger/function/item).
     */
    private function queryInheritedAlerts(ZbxDb $db, int $ts_start, string $hostFilter = ''): array {
        // PERF FIX v2.4.4 (substitui a tentativa v2.4.2): a v2.4.2 limitou a
        // busca em `events` a 180 dias, mas mesmo assim a query levava 200+
        // segundos e nunca terminava nesse ambiente (confirmado via SHOW FULL
        // PROCESSLIST em produção — milhões de linhas mesmo dentro da janela).
        //
        // Fix real: usar a tabela `problem`, nativa do Zabbix — é a mesma
        // fonte que a tela Monitoring → Problemas usa, contém só os problemas
        // correntes/relevantes (não o histórico completo de `events`), e já
        // tem r_eventid/r_clock prontos pra checar "ainda aberto ou resolvido
        // depois do início do turno" sem precisar de LEFT JOIN em
        // event_recovery. Validado em produção: 0,26s (era 200s+ e nunca
        // completava). Sem necessidade de corte de data — `problem` já é
        // enxuta por natureza, e um alerta esquecido há meses é exatamente o
        // tipo de coisa que este relatório deve continuar mostrando.
        $sql = "SELECT p.eventid, p.clock, p.severity,
                    REPLACE(MIN(t.description), '{HOST.NAME}', MIN(h.name)) AS trigger_desc,
                    MIN(h.host)  AS host,
                    MIN(h.name)  AS host_name,
                    (? - p.clock)      AS age_seconds,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM acknowledges ak WHERE ak.eventid = p.eventid
                    ) THEN 1 ELSE 0 END AS has_ack
                FROM problem p
                INNER JOIN triggers t       ON t.triggerid = p.objectid
                INNER JOIN functions f      ON f.triggerid = t.triggerid
                INNER JOIN items i          ON i.itemid    = f.itemid
                INNER JOIN hosts h          ON h.hostid    = i.hostid
                WHERE p.source = 0 AND p.object = 0
                  AND p.clock < ?
                  AND (p.r_eventid IS NULL OR p.r_clock > ?)
                  $hostFilter
                GROUP BY p.eventid, p.clock, p.severity
                ORDER BY p.severity DESC, p.clock ASC
                LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $ts_start, $ts_start, $ts_start);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    /**
     * FIX ONLY_FULL_GROUP_BY:
     * Mesma correção — MIN() para colunas de JOIN não agrupadas.
     */
    private function queryUnackedAlerts(ZbxDb $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT ev.eventid, ev.clock, ev.severity,
                    REPLACE(MIN(t.description), '{HOST.NAME}', MIN(h.name)) AS trigger_desc,
                    MIN(h.host) AS host,
                    MIN(h.name) AS host_name
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  AND NOT EXISTS (
                      SELECT 1 FROM acknowledges ak WHERE ak.eventid = ev.eventid
                  )
                  $hostFilter
                GROUP BY ev.eventid, ev.clock, ev.severity
                ORDER BY ev.severity DESC, ev.clock DESC
                LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    private function queryTopHosts(ZbxDb $db, int $s, int $e, int $limit, string $hostFilter = ''): array {
        $limitClause = $limit > 0 ? 'LIMIT ' . (int)$limit : '';
        $sql = "SELECT h.hostid, h.host, h.name AS host_name,
                    COUNT(DISTINCT ev.eventid) AS event_count,
                    MAX(ev.severity) AS max_severity
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY h.hostid, h.host, h.name
                ORDER BY event_count DESC $limitClause";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    private function queryTopTriggers(ZbxDb $db, int $s, int $e, int $limit, string $hostFilter = ''): array {
        $limitClause = $limit > 0 ? 'LIMIT ' . (int)$limit : '';
        $sql = "SELECT t.triggerid,
                    REPLACE(t.description, '{HOST.NAME}', MIN(h.name)) AS description,
                    t.priority AS severity,
                    COUNT(DISTINCT ev.eventid) AS event_count
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY t.triggerid, t.description, t.priority
                ORDER BY t.priority DESC, event_count DESC $limitClause";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    private function queryEventTotals(ZbxDb $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT COUNT(DISTINCT ev.eventid) AS total,
                    SUM(CASE WHEN ev.severity >= 4 THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN ev.severity = 3  THEN 1 ELSE 0 END) AS average,
                    SUM(CASE WHEN ev.severity <= 2 THEN 1 ELSE 0 END) AS low
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()
            ?: ['total' => 0, 'critical' => 0, 'average' => 0, 'low' => 0];
    }

    private function queryMttaTimeline(ZbxDb $db, int $s, int $e, string $hostFilter = ''): array {
        $tzOffset = (int)date('Z');
        $sql = "SELECT FROM_UNIXTIME(ev.clock + ?, '%H') AS hora,
                    ROUND(AVG(a.clock - ev.clock), 0) AS avg_mtta
                FROM acknowledges a
                INNER JOIN events ev   ON ev.eventid  = a.eventid
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0
                  AND ev.clock BETWEEN ? AND ?
                  AND a.acknowledgeid = (
                      SELECT MIN(a2.acknowledgeid)
                      FROM acknowledges a2
                      WHERE a2.eventid = a.eventid
                  )
                  $hostFilter
                GROUP BY hora
                ORDER BY hora ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $tzOffset, $s, $e);
        $stmt->execute();
        return $stmt->get_result()->fetch_all();
    }

    private function querySeverityDistribution(ZbxDb $db, int $s, int $e, string $hostFilter = ''): array {
        $sql = "SELECT ev.severity, COUNT(*) AS cnt
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY ev.severity
                ORDER BY ev.severity ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $s, $e);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->get_result()->fetch_all() as $r) {
            $rows[(int)$r['severity']] = (int)$r['cnt'];
        }
        return $rows;
    }

    private function queryCalendarHeatmap(ZbxDb $db, string $hostFilter = ''): array {
        $ts30     = (int)strtotime('-30 days 00:00:00');
        $tsNow    = (int)time();
        $tzOffset = (int)date('Z');

        $sql = "SELECT DATE(FROM_UNIXTIME(ev.clock + ?)) AS dia,
                    COUNT(DISTINCT ev.eventid) AS cnt,
                    SUM(CASE WHEN ev.severity >= 4 THEN 1 ELSE 0 END) AS critical
                FROM events ev
                INNER JOIN triggers t  ON t.triggerid = ev.objectid
                INNER JOIN functions f ON f.triggerid  = t.triggerid
                INNER JOIN items i     ON i.itemid     = f.itemid
                INNER JOIN hosts h     ON h.hostid     = i.hostid
                WHERE ev.source = 0 AND ev.object = 0 AND ev.value = 1
                  AND ev.clock BETWEEN ? AND ?
                  $hostFilter
                GROUP BY dia
                ORDER BY dia ASC";

        $stmt = $db->prepare($sql);
        $stmt->bind_param('iii', $tzOffset, $ts30, $tsNow);
        $stmt->execute();

        $rows = [];
        foreach ($stmt->get_result()->fetch_all() as $r) {
            $rows[$r['dia']] = $r;
        }
        return $rows;
    }

    private function queryNotes(ZbxDb $db, string $date, string $shift,
                                int $userid, bool $isSuperadmin = false): array {
        $date = $this->validateDate($date);

        // Turnos cadastrados (v2.5+) gravam shift_id numérico; turnos
        // legados (24h/manha/tarde/noite) continuam filtrando por shift_name.
        $filterByShiftId = ctype_digit($shift);
        $shiftIdInt      = $filterByShiftId ? (int)$shift : 0;
        $shiftCol        = $filterByShiftId ? 'shift_id' : 'shift_name';

        // created_at sai formatado em padrão brasileiro (dd/mm/aaaa hh:mm) —
        // views e PDF exibem o valor direto, sem reformatar. Ordenação usa uma
        // coluna à parte com o valor original (created_sort): ordenar pela
        // string já formatada "dd/mm/aaaa" daria resultado errado (comparação
        // léxica não é o mesmo que ordem cronológica).
        if ($isSuperadmin) {
            $sql  = "SELECT id, analyst_userid, analyst_name, notes, notes_format,
                        DATE_FORMAT(created_at, '%d/%m/%Y %H:%i') AS created_at,
                        created_at AS created_sort
                     FROM module_plantonistas_shift_notes
                     WHERE shift_date = ? AND $shiftCol = ?
                     ORDER BY created_sort DESC";
            $stmt = $db->prepare($sql);
            $filterByShiftId
                ? $stmt->bind_param('si', $date, $shiftIdInt)
                : $stmt->bind_param('ss', $date, $shift);
        } else {
            $sameGroup = $this->sameGroupExists($userid, 'csn.analyst_userid');
            $sql = "SELECT DISTINCT csn.id, csn.analyst_userid, csn.analyst_name,
                        csn.notes, csn.notes_format,
                        DATE_FORMAT(csn.created_at, '%d/%m/%Y %H:%i') AS created_at,
                        csn.created_at AS created_sort
                    FROM module_plantonistas_shift_notes csn
                    WHERE csn.shift_date = ? AND csn.$shiftCol = ?
                      AND $sameGroup
                    ORDER BY created_sort DESC";
            $stmt = $db->prepare($sql);
            $filterByShiftId
                ? $stmt->bind_param('si', $date, $shiftIdInt)
                : $stmt->bind_param('ss', $date, $shift);
        }

        try {
            $stmt->execute();
            return $stmt->get_result()->fetch_all();
        } catch (\Exception $e) {
            return [];
        }
    }

    private function queryPresence(ZbxDb $db, int $s, int $e,
                                   int $userid, bool $isSuperadmin = false): array {
        $ds = date('Y-m-d H:i:s', $s);
        $de = date('Y-m-d H:i:s', $e);

        // A coluna Turno NÃO é buscada por JOIN aqui de propósito: um JOIN nas
        // tabelas de turno faz a presença inteira desaparecer se essas tabelas
        // não existirem no ambiente — a consulta falha e leva junto o dado
        // principal. Turno é enfeite; vem depois, em query própria.
        if ($isSuperadmin) {
            $sql = "SELECT cus.userid, cus.username, cus.name AS fullname,
                        DATE_FORMAT(MIN(cus.session_start), '%d/%m/%Y %H:%i') AS first_seen,
                        DATE_FORMAT(MAX(cus.lastaccess), '%d/%m/%Y %H:%i')    AS last_seen,
                        MIN(cus.session_start) AS first_seen_sort,
                        TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
                    FROM module_plantonistas_user_sessions cus
                    WHERE cus.lastaccess BETWEEN ? AND ?
                    GROUP BY cus.userid, cus.username, cus.name
                    ORDER BY first_seen_sort ASC";
        } else {
            $sameGroup = $this->sameGroupExists($userid, 'cus.userid');
            $sql = "SELECT cus.userid, cus.username, cus.name AS fullname,
                        DATE_FORMAT(MIN(cus.session_start), '%d/%m/%Y %H:%i') AS first_seen,
                        DATE_FORMAT(MAX(cus.lastaccess), '%d/%m/%Y %H:%i')    AS last_seen,
                        MIN(cus.session_start) AS first_seen_sort,
                        TIMESTAMPDIFF(MINUTE, MIN(cus.session_start), MAX(cus.lastaccess)) AS online_minutes
                    FROM module_plantonistas_user_sessions cus
                    WHERE cus.lastaccess BETWEEN ? AND ?
                      AND $sameGroup
                    GROUP BY cus.userid, cus.username, cus.name
                    ORDER BY first_seen_sort ASC";
        }

        // Toda a consulta dentro do try. Com o ZbxDb quem lança é o execute()
        // (o prepare() só monta a string, não valida no servidor como o mysqli
        // fazia) — manter os três passos juntos é o que garante que tabela
        // ausente não derrube a página.
        try {
            $stmt = $db->prepare($sql);
            $stmt->bind_param('ss', $ds, $de);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all();
        } catch (\Throwable $ex) {
            error_log('[plantonistas] queryPresence() falhou: ' . $ex->getMessage());
            return [];
        }

        return $this->attachUserShift($db, $rows);
    }

    /**
     * Anexa o turno vinculado (tela Gerenciar Turnos) às linhas de presença.
     *
     * Query separada, e não JOIN dentro de queryPresence(), porque as tabelas
     * de turno podem não existir no ambiente (schema v2.5 nunca rodou em
     * produção — ver CLAUDE.md). Falha aqui só faz a coluna Turno mostrar
     * "Sem turno"; a presença continua na tela.
     *
     * Turno inativo (active=0) continua sendo exibido de propósito: o vínculo
     * existe, e esconder seria pior que mostrar um turno desativado.
     */
    private function attachUserShift(ZbxDb $db, array $rows): array {
        if (empty($rows)) {
            return $rows;
        }

        $ids = array_values(array_unique(array_map(
            fn($r) => (int)$r['userid'],
            $rows
        )));
        $in = implode(',', $ids); // inteiros já convertidos — sem entrada do usuário

        $map = [];
        try {
            $res = $db->query(
                "SELECT cush.userid, sh.name, sh.start_time, sh.end_time
                   FROM module_plantonistas_user_shift cush
                   INNER JOIN module_plantonistas_shifts sh ON sh.id = cush.shift_id
                  WHERE cush.userid IN ($in)"
            );
            while ($r = $res->fetch_assoc()) {
                $map[(int)$r['userid']] = $r;
            }
        } catch (\Throwable $ex) {
            error_log('[plantonistas] attachUserShift() falhou (coluna Turno da Presença fica vazia): '
                . $ex->getMessage());
        }

        foreach ($rows as &$row) {
            $s = $map[(int)$row['userid']] ?? null;
            $row['shift_name']  = $s['name']       ?? null;
            $row['shift_start'] = $s['start_time'] ?? null;
            $row['shift_end']   = $s['end_time']   ?? null;
        }
        unset($row);

        return $rows;
    }

    // ── Helper: MTTA global ponderado ────────────────────────

    private function calcGlobalMTTA(array $mttaRows): int {
        if (empty($mttaRows)) return 0;
        $sum = $cnt = 0;
        foreach ($mttaRows as $m) {
            $sum += $m['avg_mtta'] * $m['total_acks'];
            $cnt += $m['total_acks'];
        }
        return $cnt > 0 ? (int)round($sum / $cnt) : 0;
    }

    /**
     * Visibilidade de MTTA por role_type:
     *   3 (Super Admin) / 2 (Admin) → vê o MTTA de todos os analistas.
     *   1 (User)                    → vê apenas o próprio MTTA.
     */
    private function restrictMttaByRole(array $mttaRows, int $roleType, int $userid): array {
        if ($roleType >= 2) {
            return $mttaRows;
        }
        return array_values(array_filter(
            $mttaRows,
            fn($m) => (int)$m['userid'] === $userid
        ));
    }

    // ── Administração de turnos (CRUD) — Admin/Super Admin ───

    /**
     * Grupos que o usuário pode administrar: Super Admin → todos os grupos
     * com pelo menos 1 membro; Admin → apenas os grupos aos quais pertence.
     */
    private function listManageableGroups(ZbxDb $db, array $ctx): array {
        try {
            if (!empty($ctx['is_superadmin'])) {
                // Nota: exige >=1 membro em users_groups pra o grupo aparecer.
                // Um usrgrp de permissão sem nenhum usuário atribuído nunca
                // vira um card aqui — não é bug, é o INNER JOIN abaixo.
                $sql = "SELECT DISTINCT ug.usrgrpid, ug.name
                        FROM usrgrp ug
                        INNER JOIN users_groups ugm ON ugm.usrgrpid = ug.usrgrpid
                        ORDER BY ug.name ASC";
                $res = $db->query($sql);
                return $res ? $res->fetch_all() : [];
            }

            $groupIds = $ctx['group_ids'] ?? [];
            if (empty($groupIds)) return [];
            $ids = implode(',', array_map('intval', $groupIds));
            $sql = "SELECT usrgrpid, name FROM usrgrp WHERE usrgrpid IN ($ids) ORDER BY name ASC";
            $res = $db->query($sql);
            return $res ? $res->fetch_all() : [];
        } catch (\Throwable $e) {
            error_log('[plantonistas] listManageableGroups() falhou: ' . $e->getMessage());
            return [];
        }
    }

    private function listShiftsByGroup(ZbxDb $db, int $usrgrpid): array {
        try {
            $stmt = $db->prepare(
                "SELECT id, usrgrpid, name, start_time, end_time, sort_order, active
                 FROM module_plantonistas_shifts
                 WHERE usrgrpid = ?
                 ORDER BY sort_order ASC, start_time ASC"
            );
            $stmt->bind_param('i', $usrgrpid);
            $stmt->execute();
            return $stmt->get_result()->fetch_all();
        } catch (\Throwable $e) {
            error_log('[plantonistas] listShiftsByGroup() falhou (usrgrpid=' . $usrgrpid . '): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Retorna os analistas (usuários Zabbix) de uma equipe.
     *
     * Se a consulta falhar (conexão, permissão do usuário de banco, schema),
     * a chave 'error' vem preenchida — a view distingue isso de "equipe sem
     * analistas" de verdade, que é 'error' => null com 'rows' => [].
     * Antes esse retorno era só um array vazio nos dois casos, e a tela
     * mostrava sempre "Nenhum usuário Zabbix neste grupo", sem dar pra saber
     * se o grupo estava mesmo sem gente ou se a consulta tinha explodido.
     */
    private function listUsersByGroup(ZbxDb $db, int $usrgrpid): array {
        try {
            // Mesmo critério de "usuário habilitado" usado em Telefones/Escala,
            // agora igual ao do Zabbix: qualquer grupo desabilitado tira o
            // analista da lista de vínculo a turno (ver enabledUserClause()).
            $enabled = $this->enabledUserClause('u');
            $stmt = $db->prepare(
                "SELECT u.userid, u.username,
                        CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')) AS fullname,
                        cush.shift_id
                 FROM users u
                 INNER JOIN users_groups ugm ON ugm.userid = u.userid
                 LEFT JOIN module_plantonistas_user_shift cush ON cush.userid = u.userid
                 WHERE ugm.usrgrpid = ?
                   AND $enabled
                 ORDER BY fullname ASC"
            );
            // (O antigo `if ($stmt === false)` saiu: o ZbxDb::prepare() tem
            // retorno tipado e nunca devolve false — a falha vem por exceção
            // do execute(), que o catch abaixo já trata.)
            $stmt->bind_param('i', $usrgrpid);
            $stmt->execute();
            return ['error' => null, 'rows' => $stmt->get_result()->fetch_all()];
        } catch (\Throwable $e) {
            error_log('[plantonistas] listUsersByGroup() falhou (usrgrpid=' . $usrgrpid . '): ' . $e->getMessage());
            return ['error' => $e->getMessage(), 'rows' => []];
        }
    }

    /**
     * Confirma que o usuário logado pode administrar o grupo informado
     * (Super Admin: qualquer grupo; Admin: apenas os próprios grupos).
     */
    private function canManageGroup(array $ctx, int $usrgrpid): bool {
        if (!empty($ctx['is_superadmin'])) return true;
        return in_array($usrgrpid, $ctx['group_ids'] ?? [], true);
    }

    /**
     * Cria ou atualiza um turno. Retorna ['success'=>bool,'message'=>string,'id'=>?int].
     */
    private function saveShift(ZbxDb $db, array $ctx, ?int $id, int $usrgrpid,
                                string $name, string $start, string $end, int $sortOrder): array {
        if (!$this->canManageGroup($ctx, $usrgrpid)) {
            return ['success' => false, 'message' => 'Sem permissão para esta equipe.'];
        }
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'message' => 'Informe um nome para o turno.'];
        }
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end)) {
            return ['success' => false, 'message' => 'Horário inválido (use HH:MM).'];
        }

        try {
            if ($id) {
                $stmt = $db->prepare(
                    "UPDATE module_plantonistas_shifts
                     SET name = ?, start_time = ?, end_time = ?, sort_order = ?
                     WHERE id = ? AND usrgrpid = ?"
                );
                $stmt->bind_param('sssiii', $name, $start, $end, $sortOrder, $id, $usrgrpid);
                $stmt->execute();
                return ['success' => true, 'message' => 'Turno atualizado.', 'id' => $id];
            }

            $stmt = $db->prepare(
                "INSERT INTO module_plantonistas_shifts (usrgrpid, name, start_time, end_time, sort_order, active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $stmt->bind_param('isssi', $usrgrpid, $name, $start, $end, $sortOrder);
            $stmt->execute();
            return ['success' => true, 'message' => 'Turno criado.', 'id' => $db->insert_id];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Já existe um turno com esse nome nessa equipe, ou erro ao salvar.'];
        }
    }

    private function deleteShift(ZbxDb $db, array $ctx, int $id): array {
        $shift = $this->getShiftById($db, $id);
        if ($shift === null) {
            return ['success' => false, 'message' => 'Turno não encontrado.'];
        }
        if (!$this->canManageGroup($ctx, (int)$shift['usrgrpid'])) {
            return ['success' => false, 'message' => 'Sem permissão para esta equipe.'];
        }

        try {
            $stmt = $db->prepare("DELETE FROM module_plantonistas_user_shift WHERE shift_id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();

            $stmt = $db->prepare("DELETE FROM module_plantonistas_shifts WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();

            return ['success' => true, 'message' => 'Turno removido.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erro ao remover turno.'];
        }
    }

    /**
     * Vincula (ou desvincula, se $shiftId for null) um usuário a um turno.
     */
    private function saveUserShift(ZbxDb $db, array $ctx, int $userid, ?int $shiftId): array {
        if ($shiftId !== null) {
            $shift = $this->getShiftById($db, $shiftId);
            if ($shift === null || !$this->canManageGroup($ctx, (int)$shift['usrgrpid'])) {
                return ['success' => false, 'message' => 'Turno inválido ou sem permissão.'];
            }
        } else {
            // Para desvincular, confirma que o usuário pertence a um grupo administrável.
            try {
                $stmt = $db->prepare(
                    "SELECT usrgrpid FROM users_groups WHERE userid = ?"
                );
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                $groups = array_map(fn($r) => (int)$r['usrgrpid'], $stmt->get_result()->fetch_all());
                $allowed = !empty($ctx['is_superadmin']);
                foreach ($groups as $g) {
                    if ($this->canManageGroup($ctx, $g)) { $allowed = true; break; }
                }
                if (!$allowed) {
                    return ['success' => false, 'message' => 'Sem permissão para este usuário.'];
                }
            } catch (\Exception $e) {
                return ['success' => false, 'message' => 'Erro ao validar usuário.'];
            }
        }

        try {
            if ($shiftId === null) {
                $stmt = $db->prepare("DELETE FROM module_plantonistas_user_shift WHERE userid = ?");
                $stmt->bind_param('i', $userid);
                $stmt->execute();
                return ['success' => true, 'message' => 'Usuário desvinculado do turno.'];
            }

            $stmt = $db->prepare(
                "INSERT INTO module_plantonistas_user_shift (userid, shift_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE shift_id = VALUES(shift_id)"
            );
            $stmt->bind_param('ii', $userid, $shiftId);
            $stmt->execute();
            return ['success' => true, 'message' => 'Turno atribuído.'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Erro ao salvar vínculo.'];
        }
    }

    // ── Editor rico / menções (v4.2) ──────────────────────────

    /**
     * Hostgroups visíveis ao usuário — mesma regra de host_filter em
     * resolveUserContext() (rights.permission >= 2). Super Admin vê todos.
     */
    private function searchHostgroups(ZbxDb $db, array $ctx, string $q): array {
        $like = '%' . $q . '%';
        try {
            if (!empty($ctx['is_superadmin'])) {
                $stmt = $db->prepare(
                    "SELECT groupid AS id, name AS label
                     FROM hstgrp
                     WHERE name LIKE ?
                     ORDER BY name LIMIT 20"
                );
                $stmt->bind_param('s', $like);
            } else {
                $userid = (int)$ctx['userid'];
                $stmt = $db->prepare(
                    "SELECT DISTINCT hg.groupid AS id, hg.name AS label
                     FROM hstgrp hg
                     INNER JOIN rights r ON r.id = hg.groupid
                     INNER JOIN users_groups ug ON ug.usrgrpid = r.groupid
                     WHERE ug.userid = ? AND r.permission >= 2 AND hg.name LIKE ?
                     ORDER BY hg.name LIMIT 20"
                );
                $stmt->bind_param('is', $userid, $like);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all();
            foreach ($rows as &$r) {
                $r['url'] = 'zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_groupids[]=' . (int)$r['id'];
            }
            return $rows;
        } catch (\Throwable $e) {
            error_log('[plantonistas] searchHostgroups() falhou: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Hosts visíveis ao usuário — mesma regra de host_filter. Exclui
     * templates (status=3); inclui monitorados (0) e não monitorados (1).
     */
    private function searchHosts(ZbxDb $db, array $ctx, string $q): array {
        $like = '%' . $q . '%';
        try {
            if (!empty($ctx['is_superadmin'])) {
                $stmt = $db->prepare(
                    "SELECT hostid AS id, name AS label
                     FROM hosts
                     WHERE status IN (0,1) AND (host LIKE ? OR name LIKE ?)
                     ORDER BY name LIMIT 20"
                );
                $stmt->bind_param('ss', $like, $like);
            } else {
                $userid = (int)$ctx['userid'];
                $stmt = $db->prepare(
                    "SELECT DISTINCT h.hostid AS id, h.name AS label
                     FROM hosts h
                     INNER JOIN hosts_groups hg ON hg.hostid = h.hostid
                     INNER JOIN rights r ON r.id = hg.groupid
                     INNER JOIN users_groups ug ON ug.usrgrpid = r.groupid
                     WHERE ug.userid = ? AND r.permission >= 2
                       AND h.status IN (0,1) AND (h.host LIKE ? OR h.name LIKE ?)
                     ORDER BY h.name LIMIT 20"
                );
                $stmt->bind_param('iss', $userid, $like, $like);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all();
            foreach ($rows as &$r) {
                $r['url'] = 'zabbix.php?action=problem.view&filter_set=1&filter_show=3&filter_name=' . urlencode($r['label']);
            }
            return $rows;
        } catch (\Throwable $e) {
            error_log('[plantonistas] searchHosts() falhou: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Usuários @mencionáveis: mesma regra de visibilidade já usada em
     * Notas/Presença (grupo compartilhado) + só usuários ativos (mesmo
     * critério de Telefones/Escala/Gerenciar Turnos).
     */
    /**
     * Nome de exibição a partir de users.name/surname, com username de reserva.
     *
     * Existe porque CONCAT(name,' ',surname) cru produz duas falhas visíveis:
     *  - label em branco para conta que só tem username (serviço, integração);
     *  - nome duplicado ("Rafael Rafael Leao Ereno") em cadastro que colocou o
     *    nome completo no campo surname e só o primeiro nome em name.
     *
     * Mesma regra de buildFullName() em scripts/cron_presence_tracker.php: um
     * campo só absorve o outro se o contiver **nas bordas**, com espaço como
     * limite de palavra — "Ana" + "Mariana Costa" é substring coincidente e
     * continua concatenando. A duplicação de lógica é proposital: o cron roda
     * em CLI sem carregar o módulo, não dá pra reusar este trait.
     */
    private function formatUserLabel(?string $name, ?string $surname, string $username = ''): string {
        $norm    = fn(?string $v) => trim(preg_replace('/\s+/', ' ', (string)$v));
        $name    = $norm($name);
        $surname = $norm($surname);

        if ($name === '' && $surname === '') return $username;
        if ($name === '')    return $surname;
        if ($surname === '') return $name;

        $ln = mb_strtolower($name);
        $ls = mb_strtolower($surname);

        if ($ln === $ls || str_starts_with($ls, $ln . ' ')) return $surname;
        if (str_ends_with($ln, ' ' . $ls))                  return $name;

        return $name . ' ' . $surname;
    }

    /**
     * Y-m-d (formato de trânsito: input type="date", URL, banco) → d/m/Y,
     * para exibição. Toda data que aparece na tela passa por aqui; o valor
     * cru só continua onde é protocolo, não texto (o `value` do
     * input type="date" exige ISO por especificação do HTML, e mudar
     * quebraria o filtro; URLs e a const JS idem).
     */
    private function formatDateBr(?string $ymd): string {
        $ts = $ymd ? strtotime($ymd) : false;
        return $ts === false ? (string)$ymd : date('d/m/Y', $ts);
    }

    /**
     * Cláusula SQL de "usuário habilitado", igual ao critério do Zabbix.
     *
     * O Zabbix resolve o status com `MAX(usrgrp.users_status)` (ver
     * CUser::get/getAccess e a coluna Status da lista de usuários): pertencer
     * a **qualquer** grupo desabilitado já desabilita o usuário. Ou seja
     * `NOT EXISTS (grupo desabilitado)`, e não "tem pelo menos 1 grupo ativo"
     * — que era o que o módulo fazia e deixava passar quem está em grupo misto
     * (ex.: membro de "NOC" e de "Desligados" ao mesmo tempo).
     *
     * Usuário sem nenhum grupo conta como habilitado, igual ao Zabbix.
     * Sem `roleid` a lista do Zabbix mostra "Disabled" — também fica fora.
     *
     * @param string $alias alias da tabela `users` na query que vai usar isto
     */
    private function enabledUserClause(string $alias = 'u'): string {
        return "NOT EXISTS (
                    SELECT 1 FROM users_groups ugx
                    INNER JOIN usrgrp gx ON gx.usrgrpid = ugx.usrgrpid
                    WHERE ugx.userid = $alias.userid AND gx.users_status = 1
                )
                AND $alias.roleid IS NOT NULL AND $alias.roleid <> 0";
    }

    /**
     * Cláusula SQL de "usuário não bloqueado por tentativas de login".
     *
     * Mesmo critério da coluna "Login: Blocked" na lista de usuários do
     * Zabbix: `attempt_failed >= config.login_attempts`, sem olhar o tempo.
     * O bloqueio efetivo de login expira sozinho após `config.login_block`,
     * mas o contador só zera quando a pessoa loga ou um Super Admin usa
     * Unblock — então este é o critério que casa com o que se vê na tela.
     *
     * Aplicado só na busca de menção. Para escala/telefones a decisão segue
     * a de sempre: bloqueio é estado transitório de segurança e não define
     * elegibilidade (ver CLAUDE.md).
     */
    private function notBlockedUserClause(string $alias = 'u'): string {
        return "$alias.attempt_failed < (SELECT MIN(login_attempts) FROM config)";
    }

    private function searchMentionableUsers(ZbxDb $db, array $ctx, string $q): array {
        $activeClause = $this->enabledUserClause('u')
            . ' AND ' . $this->notBlockedUserClause('u');
        // ORDER BY pelo nome efetivo (username quando não há nome cadastrado):
        // ordenar pelo CONCAT cru jogava todos os labels vazios pro topo da
        // lista, que era exatamente o que aparecia como "itens em branco".
        $orderBy = "ORDER BY IF(TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,''))) = '',
                                u.username,
                                TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')))) ASC";
        // Busca por termos: cada palavra digitada tem que aparecer em ALGUM dos
        // campos (username, name, surname ou o nome completo montado). É isso
        // que faz "Rafael Leao" achar name='Rafael' + surname='Leao Ereno' — o
        // LIKE campo por campo, sozinho, nunca casa um nome que atravessa os
        // dois campos. Como cada termo é uma condição AND, a ordem digitada não
        // importa ("ereno rafael" acha igual) e dá pra combinar nome + username.
        $fullName = "TRIM(CONCAT(COALESCE(u.name,''), ' ', COALESCE(u.surname,'')))";
        $terms    = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms    = array_slice($terms, 0, 5); // teto de termos: query previsível

        $termSql    = '';
        $bindTypes  = '';
        $bindValues = [];
        foreach ($terms as $t) {
            $termSql .= " AND (u.username LIKE ? OR u.name LIKE ? OR u.surname LIKE ? OR $fullName LIKE ?)";
            $like     = '%' . $t . '%';
            $bindTypes .= 'ssss';
            array_push($bindValues, $like, $like, $like, $like);
        }

        try {
            if (!empty($ctx['is_superadmin'])) {
                $stmt = $db->prepare(
                    "SELECT u.userid AS id, u.username, u.name, u.surname
                     FROM users u
                     WHERE u.username != 'guest' AND $activeClause
                       $termSql
                     $orderBy LIMIT 20"
                );
            } else {
                $sameGroup = $this->sameGroupExists((int)$ctx['userid'], 'u.userid');
                $stmt = $db->prepare(
                    "SELECT DISTINCT u.userid AS id, u.username, u.name, u.surname
                     FROM users u
                     WHERE $sameGroup AND $activeClause
                       $termSql
                     $orderBy LIMIT 20"
                );
            }
            // Sem termo digitado (só o gatilho) não há placeholder pra ligar —
            // bind_param com string de tipos vazia é erro.
            if ($bindTypes !== '') {
                $stmt->bind_param($bindTypes, ...$bindValues);
            }
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all();

            $out = [];
            foreach ($rows as $r) {
                $label = $this->formatUserLabel($r['name'] ?? '', $r['surname'] ?? '', $r['username']);
                $out[] = [
                    'id'    => $r['id'],
                    'label' => $label,
                    // Username como linha secundária no dropdown: desambigua
                    // homônimo e dá o que mostrar quando o label caiu no
                    // próprio username (aí some, pra não repetir).
                    'sub'   => ($label !== $r['username']) ? $r['username'] : '',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[plantonistas] searchMentionableUsers() falhou: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Sanitiza o HTML do editor rico: allowlist de tags/atributos, remove
     * atributos on-event, links javascript:/data: e qualquer coisa fora da
     * lista — o servidor nunca confia no HTML vindo do cliente, mesmo
     * sendo produzido pelo nosso próprio editor (dá pra postar direto no
     * endpoint sem passar pela UI). Também extrai os userids marcados via
     * <span data-mention-userid> (inseridos pelo autocomplete [user]).
     *
     * @return array{html:string,mentioned_userids:int[]}
     */
    private function sanitizeNoteHtml(string $html): array {
        if (trim($html) === '' || !class_exists('DOMDocument')) {
            return ['html' => strip_tags($html), 'mentioned_userids' => []];
        }

        $allowedTags = ['b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'br', 'p', 'div', 'a', 'span'];
        $allowedAttrs = [
            'a'    => ['href', 'target', 'rel', 'class', 'title'],
            'span' => ['class', 'data-mention-userid', 'data-mention-name'],
        ];

        $doc = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div>' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementsByTagName('div')->item(0);
        if (!$root) {
            return ['html' => '', 'mentioned_userids' => []];
        }

        $mentioned = [];
        $this->sanitizeNode($root, $allowedTags, $allowedAttrs, $mentioned);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return ['html' => trim($out), 'mentioned_userids' => array_values(array_unique($mentioned))];
    }

    /**
     * @param string[] $allowedTags
     * @param array<string,string[]> $allowedAttrs
     * @param int[] $mentioned Acumulador por referência dos userids
     *              encontrados em <span data-mention-userid>.
     */
    private function sanitizeNode(\DOMNode $node, array $allowedTags, array $allowedAttrs, array &$mentioned): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->nodeName);

            // Sanitiza o subtree ANTES de decidir promover/manter a tag atual.
            // Se sanitizasse só depois de promover, os filhos de uma tag
            // descartada (ex.: <iframe><span onclick=...>) escapariam da
            // limpeza — o foreach deste nível já tem um snapshot fixo e não
            // revisita nó promovido no meio da iteração.
            $this->sanitizeNode($child, $allowedTags, $allowedAttrs, $mentioned);

            if (!in_array($tag, $allowedTags, true)) {
                // Tag não permitida: preserva o conteúdo (já sanitizado), descarta só a tag.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $allowed = $allowedAttrs[$tag] ?? [];
            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                $aname = strtolower($attr->nodeName);
                if (!in_array($aname, $allowed, true)) {
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }
                if ($aname === 'href') {
                    $href = trim($attr->nodeValue);
                    // Só http(s) ou link relativo do próprio Zabbix — nunca
                    // javascript:/data:/vbscript:.
                    if (!preg_match('#^(zabbix\.php\?|https?://)#i', $href)) {
                        $child->removeAttribute('href');
                    }
                }
            }
            if ($tag === 'a' && $child->getAttribute('href') !== '') {
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            if ($tag === 'span' && $child->hasAttribute('data-mention-userid')) {
                $uid = (int)$child->getAttribute('data-mention-userid');
                if ($uid > 0) {
                    $mentioned[] = $uid;
                }
            }
        }
    }

    /**
     * Grava 1 linha em module_plantonistas_mentions por userid mencionado
     * válido (ativo + visível ao autor: mesmo grupo, ou autor é Super
     * Admin). Menção inválida é descartada em silêncio — não deve
     * derrubar o save da nota por causa de 1 menção ruim.
     */
    private function recordMentions(ZbxDb $db, int $noteId, array $mentionedUserids, int $authorUserid, array $ctx): void {
        foreach ($mentionedUserids as $uid) {
            if ($uid === $authorUserid) {
                continue; // mencionar a si mesmo não gera notificação
            }
            try {
                $visible = true;
                if (empty($ctx['is_superadmin'])) {
                    $stmt = $db->prepare(
                        'SELECT 1 FROM users_groups ug_r' .
                        ' JOIN users_groups ug_a ON ug_a.usrgrpid = ug_r.usrgrpid' .
                        ' WHERE ug_r.userid = ? AND ug_a.userid = ? LIMIT 1'
                    );
                    $stmt->bind_param('ii', $authorUserid, $uid);
                    $stmt->execute();
                    $visible = (bool)$stmt->get_result()->fetch_row();
                }
                if (!$visible) {
                    continue;
                }
                $stmt = $db->prepare(
                    'INSERT INTO module_plantonistas_mentions (note_id, mentioned_userid, created_by)
                     VALUES (?, ?, ?)'
                );
                $stmt->bind_param('iii', $noteId, $uid, $authorUserid);
                $stmt->execute();
            } catch (\Throwable $e) {
                error_log('[plantonistas] recordMentions() falhou (userid=' . $uid . '): ' . $e->getMessage());
            }
        }
    }

    /**
     * Menções [user] pendentes (não lidas) para o usuário logado — usado
     * pelo banner de notificação ao entrar no Repasse Plantão.
     */
    private function queryPendingMentions(ZbxDb $db, int $userid): array {
        try {
            $stmt = $db->prepare(
                "SELECT m.id, m.note_id, n.shift_date, n.shift_id, n.shift_name, n.analyst_name,
                        DATE_FORMAT(m.created_at, '%d/%m/%Y %H:%i') AS created_at
                 FROM module_plantonistas_mentions m
                 INNER JOIN module_plantonistas_shift_notes n ON n.id = m.note_id
                 WHERE m.mentioned_userid = ? AND m.is_read = 0
                 ORDER BY m.created_at DESC
                 LIMIT 10"
            );
            $stmt->bind_param('i', $userid);
            $stmt->execute();
            return $stmt->get_result()->fetch_all();
        } catch (\Throwable $e) {
            error_log('[plantonistas] queryPendingMentions() falhou: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Marca 1 menção como lida — sempre escopada ao próprio usuário
     * (WHERE mentioned_userid = $userid), então não dá pra marcar como
     * lida a notificação de outra pessoa trocando o id no request.
     */
    private function markMentionRead(ZbxDb $db, int $mentionId, int $userid): bool {
        try {
            $stmt = $db->prepare(
                'UPDATE module_plantonistas_mentions
                 SET is_read = 1, read_at = NOW()
                 WHERE id = ? AND mentioned_userid = ?'
            );
            $stmt->bind_param('ii', $mentionId, $userid);
            $stmt->execute();
            return true;
        } catch (\Throwable $e) {
            error_log('[plantonistas] markMentionRead() falhou: ' . $e->getMessage());
            return false;
        }
    }
}
