-- ARQUIVO GERADO — não edite à mão.
--
-- Fonte: Schema.php (descrição única das tabelas do módulo).
-- Regenerar: php scripts/gen_schema.php
--
-- Dialeto: MySQL / MariaDB
-- Módulo:  Plantonistas (Zabbix 7.0 LTS)
--
-- Este arquivo serve para instalação manual e consulta. Em uso normal
-- quem cria e migra as tabelas é o Module::init(), a cada request, de
-- forma idempotente — não é preciso rodar nada disto à mão.

-- ── module_plantonistas_phones ──────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_phones (
  userid BIGINT NOT NULL,
  phone VARCHAR(100) NOT NULL DEFAULT '',
  PRIMARY KEY (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── module_plantonistas_schedule ────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_schedule (
  scheduleid BIGINT NOT NULL AUTO_INCREMENT,
  usrgrpid BIGINT NOT NULL DEFAULT 0 COMMENT 'FK -> usrgrp.usrgrpid',
  shift_id BIGINT NOT NULL DEFAULT 0 COMMENT 'FK -> module_plantonistas_shifts.id; 0 = grupo sem turnos (legado)',
  userid BIGINT NOT NULL,
  userid_reserva BIGINT,
  schedule_date DATE NOT NULL,
  created_by BIGINT NOT NULL DEFAULT 0,
  created_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (scheduleid),
  UNIQUE KEY uniq_group_day_shift (usrgrpid, schedule_date, shift_id),
  INDEX idx_sched_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Escala de plantão: 1 titular por grupo/dia, ou por grupo/dia/turno quando há turnos cadastrados';

-- ── module_plantonistas_history ─────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_history (
  historyid BIGINT NOT NULL AUTO_INCREMENT,
  scheduleid BIGINT NOT NULL DEFAULT 0,
  usrgrpid BIGINT NOT NULL DEFAULT 0,
  shift_id BIGINT NOT NULL DEFAULT 0,
  shift_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT 'Snapshot do nome do turno — sobrevive a rename/remoção',
  schedule_date DATE NOT NULL,
  action VARCHAR(16) NOT NULL DEFAULT 'save',
  userid_old BIGINT,
  userid_new BIGINT,
  reserva_old BIGINT,
  reserva_new BIGINT,
  changed_by BIGINT NOT NULL DEFAULT 0,
  changed_at INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY (historyid),
  INDEX idx_schedule_date (schedule_date),
  INDEX idx_usrgrpid (usrgrpid),
  INDEX idx_hist_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Histórico de alterações da escala';

-- ── module_plantonistas_user_sessions ───────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_user_sessions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  userid BIGINT NOT NULL,
  username VARCHAR(100) NOT NULL,
  name VARCHAR(128),
  session_start DATETIME NOT NULL,
  lastaccess DATETIME NOT NULL,
  ip VARCHAR(39),
  noc_context VARCHAR(50),
  PRIMARY KEY (id),
  INDEX idx_cus_userid (userid),
  INDEX idx_cus_lastaccess (lastaccess),
  INDEX idx_cus_session_start (session_start),
  INDEX idx_cus_noc_context (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Presença de analistas — populada por scripts/cron_presence_tracker.php';

-- ── module_plantonistas_shift_notes ─────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shift_notes (
  id BIGINT NOT NULL AUTO_INCREMENT,
  shift_date DATE NOT NULL,
  shift_name VARCHAR(50) NOT NULL,
  shift_id BIGINT,
  analyst_userid BIGINT NOT NULL,
  analyst_name VARCHAR(128) NOT NULL,
  notes LONGTEXT NOT NULL,
  notes_format VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT 'text = legado (escapado na exibição) | html = editor rico (já sanitizado)',
  noc_context VARCHAR(50),
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_csn_shift (shift_date, shift_name),
  INDEX idx_csn_shift_id (shift_id),
  INDEX idx_csn_analyst (analyst_userid),
  INDEX idx_csn_noc (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Diário de Bordo — histórico permanente, sem expiração';

-- ── module_plantonistas_mentions ────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_mentions (
  id BIGINT NOT NULL AUTO_INCREMENT,
  note_id BIGINT NOT NULL,
  mentioned_userid BIGINT NOT NULL,
  created_by BIGINT NOT NULL,
  is_read SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at DATETIME,
  PRIMARY KEY (id),
  INDEX idx_mention_user_unread (mentioned_userid, is_read),
  INDEX idx_mention_note (note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Menções @ do Diário de Bordo';

-- ── module_plantonistas_shift_reports ───────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shift_reports (
  id BIGINT NOT NULL AUTO_INCREMENT,
  shift_date DATE NOT NULL,
  shift_name VARCHAR(20) NOT NULL,
  generated_by BIGINT NOT NULL COMMENT 'FK -> users.userid',
  noc_context VARCHAR(50),
  generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  report_json LONGTEXT NOT NULL COMMENT 'Snapshot JSON do relatório no fechamento do turno',
  PRIMARY KEY (id),
  INDEX idx_csr_shift (shift_date, shift_name),
  INDEX idx_csr_noc (noc_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Turnos fechados — repasse congelado (issue #2)';

-- ── module_plantonistas_shifts ──────────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_shifts (
  id BIGINT NOT NULL AUTO_INCREMENT,
  usrgrpid BIGINT NOT NULL COMMENT 'FK -> usrgrp.usrgrpid (equipe dona do turno)',
  name VARCHAR(50) NOT NULL COMMENT 'Ex: Diurno, Turno 1',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL COMMENT 'Se <= start_time, o turno vira o dia (madrugada)',
  sort_order INTEGER NOT NULL DEFAULT 0,
  active SMALLINT NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cs_group_name (usrgrpid, name),
  INDEX idx_cs_usrgrp (usrgrpid),
  INDEX idx_cs_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Turnos configuráveis por equipe';

-- ── module_plantonistas_user_shift ──────────────────────────────
CREATE TABLE IF NOT EXISTS module_plantonistas_user_shift (
  userid BIGINT NOT NULL,
  shift_id BIGINT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (userid),
  INDEX idx_cush_shift (shift_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Vínculo analista -> turno (1 turno por analista)';

