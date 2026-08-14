-- ============================================================
-- Módulo Plantonistas — Schema SQL v4.0.0
--
-- SOMENTE PARA INSTALAÇÃO NOVA (banco sem os módulos antigos).
--
-- Se o banco tem dados dos módulos antigos (module_plantao_* /
-- custom_*), NÃO rode este arquivo: habilite o módulo no Zabbix e
-- ele renomeia as tabelas antigas sozinho (RENAME TABLE, sem cópia
-- de dados). Rodar este schema antes da migração cria tabelas novas
-- vazias — o módulo detecta esse caso e descarta a vazia, mas não
-- crie a situação de propósito. Ver sql/migrate-from-old-modules.sql.
--
--   mysql -uzabbix -p zabbix < schema.sql
-- ============================================================

-- ── 1. Escala mensal (ex-module_plantao_*) ──────────────────

CREATE TABLE IF NOT EXISTS module_plantonistas_phones (
    userid BIGINT       NOT NULL COMMENT 'FK → users.userid',
    phone  VARCHAR(100) NOT NULL DEFAULT '',
    PRIMARY KEY (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Telefone de contato por usuário';

CREATE TABLE IF NOT EXISTS module_plantonistas_schedule (
    scheduleid     BIGINT NOT NULL AUTO_INCREMENT,
    usrgrpid       BIGINT NOT NULL DEFAULT 0 COMMENT 'FK → usrgrp.usrgrpid',
    shift_id       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK → module_plantonistas_shifts.id; 0 = grupo sem turnos configurados (legado, 1 titular/dia)',
    userid         BIGINT NOT NULL COMMENT 'Plantonista titular (ou titular do turno, se shift_id>0)',
    userid_reserva BIGINT NULL COMMENT 'Plantonista reserva (opcional; só usado no modo legado, shift_id=0)',
    schedule_date  DATE   NOT NULL,
    created_by     BIGINT NOT NULL DEFAULT 0,
    created_at     INT    NOT NULL DEFAULT 0,
    PRIMARY KEY (scheduleid),
    UNIQUE KEY uniq_group_day_shift (usrgrpid, schedule_date, shift_id),
    KEY idx_sched_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Escala de plantão: 1 titular por grupo/dia (sem turnos) ou 1 titular por grupo/dia/turno (com turnos cadastrados em module_plantonistas_shifts)';

CREATE TABLE IF NOT EXISTS module_plantonistas_history (
    historyid     BIGINT      NOT NULL AUTO_INCREMENT,
    scheduleid    BIGINT      NOT NULL DEFAULT 0,
    usrgrpid      BIGINT      NOT NULL DEFAULT 0,
    shift_id      BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'FK → module_plantonistas_shifts.id; 0 = legado',
    shift_name    VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'Nome do turno no momento da alteração (snapshot — sobrevive a rename/remoção do turno)',
    schedule_date DATE        NOT NULL,
    action        VARCHAR(16) NOT NULL DEFAULT 'save',
    userid_old    BIGINT      NULL,
    userid_new    BIGINT      NULL,
    reserva_old   BIGINT      NULL,
    reserva_new   BIGINT      NULL,
    changed_by    BIGINT      NOT NULL DEFAULT 0,
    changed_at    INT         NOT NULL DEFAULT 0,
    PRIMARY KEY (historyid),
    KEY idx_schedule_date (schedule_date),
    KEY idx_usrgrpid (usrgrpid),
    KEY idx_hist_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Log de alterações da escala (1 linha por grupo/dia/turno alterado)';

-- ── 2. Repasse de plantão NOC (ex-custom_*) ─────────────────

CREATE TABLE IF NOT EXISTS module_plantonistas_user_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid          BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid',
    username        VARCHAR(100)    NOT NULL,
    name            VARCHAR(128)    DEFAULT NULL COMMENT 'Nome completo (name + surname)',
    session_start   DATETIME        NOT NULL,
    lastaccess      DATETIME        NOT NULL,
    ip              VARCHAR(39)     DEFAULT NULL,
    noc_context     VARCHAR(50)     DEFAULT NULL COMMENT 'Nome do primeiro grupo do usuário',
    PRIMARY KEY (id),
    INDEX idx_cus_userid        (userid),
    INDEX idx_cus_lastaccess    (lastaccess),
    INDEX idx_cus_session_start (session_start),
    INDEX idx_cus_noc_context   (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Presença de analistas — populada pelo cron_presence_tracker.php';

CREATE TABLE IF NOT EXISTS module_plantonistas_shift_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(50)     NOT NULL COMMENT '24h | manha | tarde | noite (legado) OU nome do turno cadastrado',
    shift_id        BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → module_plantonistas_shifts.id (NULL = turno legado)',
    analyst_userid  BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid',
    analyst_name    VARCHAR(128)    NOT NULL,
    notes           TEXT            NOT NULL,
    noc_context     VARCHAR(50)     DEFAULT NULL COMMENT 'Legado — visibilidade controlada por users_groups',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_csn_shift    (shift_date, shift_name),
    INDEX idx_csn_shift_id (shift_id),
    INDEX idx_csn_analyst  (analyst_userid),
    INDEX idx_csn_noc      (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Diário de Bordo por turno';

CREATE TABLE IF NOT EXISTS module_plantonistas_shift_reports (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shift_date      DATE            NOT NULL,
    shift_name      VARCHAR(20)     NOT NULL,
    generated_by    BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid',
    noc_context     VARCHAR(50)     DEFAULT NULL,
    generated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    report_json     LONGTEXT        NOT NULL COMMENT 'Snapshot JSON do relatório',
    PRIMARY KEY (id),
    INDEX idx_csr_shift (shift_date, shift_name),
    INDEX idx_csr_noc   (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Snapshots de relatórios consolidados (uso futuro)';

CREATE TABLE IF NOT EXISTS module_plantonistas_shifts (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usrgrpid        BIGINT UNSIGNED NOT NULL COMMENT 'FK → usrgrp.usrgrpid (equipe dona do turno)',
    name            VARCHAR(50)     NOT NULL COMMENT 'Ex: Diurno, Turno 1',
    start_time      TIME            NOT NULL,
    end_time        TIME            NOT NULL COMMENT 'Se <= start_time, o turno vira o dia (madrugada)',
    sort_order      INT             NOT NULL DEFAULT 0,
    active          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cs_group_name (usrgrpid, name),
    INDEX idx_cs_usrgrp (usrgrpid),
    INDEX idx_cs_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Turnos configuráveis por equipe (usrgrp)';

CREATE TABLE IF NOT EXISTS module_plantonistas_user_shift (
    userid          BIGINT UNSIGNED NOT NULL COMMENT 'FK → users.userid',
    shift_id        BIGINT UNSIGNED NOT NULL COMMENT 'FK → module_plantonistas_shifts.id',
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (userid),
    INDEX idx_cush_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COMMENT='Vínculo fixo usuário → turno (1 turno ativo por usuário)';

-- ============================================================
-- Schema aplicado.
-- ============================================================
