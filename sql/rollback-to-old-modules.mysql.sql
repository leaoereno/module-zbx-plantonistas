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
-- Dialeto: MySQL / MariaDB
-- ============================================================

RENAME TABLE module_plantonistas_phones TO module_plantao_phones;
RENAME TABLE module_plantonistas_schedule TO module_plantao_schedule;
RENAME TABLE module_plantonistas_history TO module_plantao_history;
RENAME TABLE module_plantonistas_shifts TO custom_shifts;
RENAME TABLE module_plantonistas_user_shift TO custom_user_shift;
RENAME TABLE module_plantonistas_shift_notes TO custom_shift_notes;
RENAME TABLE module_plantonistas_user_sessions TO custom_user_sessions;
RENAME TABLE module_plantonistas_shift_reports TO custom_shift_reports;
