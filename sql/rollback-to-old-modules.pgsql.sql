-- ============================================================
-- ROLLBACK: Plantonistas → módulos antigos
--
-- Devolve as tabelas aos nomes que os módulos antigos
-- (module-zbx-escala-plantao e module-zbx-repasse-plantao) usam.
-- O rename é atômico e instantâneo — nenhuma linha é copiada, e os
-- dados gravados enquanto o Plantonistas esteve ativo são mantidos.
--
-- ⚠️ AO CONTRÁRIO DO ARQUIVO DE MIGRAÇÃO, ESTE NÃO É OPCIONAL NEM
-- AUTOMÁTICO. O init() do Plantonistas renomeia no sentido OPOSTO:
-- se o módulo continuar habilitado, ele desfaz este rollback na
-- primeira carga de página.
--
-- Ordem do rollback completo:
--   1. Zabbix UI: desabilitar o módulo "Plantonistas" — PRIMEIRO,
--      senão o passo 2 é desfeito sozinho
--   2. Rodar este SQL
--   3. Zabbix UI: reabilitar os dois módulos antigos
--   4. Restaurar o crontab antigo do presence tracker
--
-- Dialeto: PostgreSQL
-- ============================================================

-- O PostgreSQL faz DDL dentro de transação: se qualquer rename falhar,
-- o BEGIN/COMMIT desfaz todos. É mais garantido que no MySQL, onde cada
-- RENAME TABLE é atômico em si, mas não há atomicidade entre eles.

BEGIN;

ALTER TABLE module_plantonistas_phones RENAME TO module_plantao_phones;
ALTER TABLE module_plantonistas_schedule RENAME TO module_plantao_schedule;
ALTER TABLE module_plantonistas_history RENAME TO module_plantao_history;
ALTER TABLE module_plantonistas_shifts RENAME TO custom_shifts;
ALTER TABLE module_plantonistas_user_shift RENAME TO custom_user_shift;
ALTER TABLE module_plantonistas_shift_notes RENAME TO custom_shift_notes;
ALTER TABLE module_plantonistas_user_sessions RENAME TO custom_user_sessions;
ALTER TABLE module_plantonistas_shift_reports RENAME TO custom_shift_reports;

COMMIT;
