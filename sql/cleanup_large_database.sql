-- PROMETHEUS: limpeza de dados antigos com prévia e retenção configurável.
-- Execute primeiro em uma cópia/backup. Este arquivo NÃO é executado pela aplicação.
-- Padrão: remove somente logs com mais de 30 dias. Histórico de trades, previsões,
-- resultados e snapshots de equity são preservados.
--
-- Compatível com MySQL/MariaDB. Execute no banco prometheus.

USE `prometheus`;

-- Ajuste as retenções conforme sua necessidade.
SET @retencao_logs_dias := 30;
SET @retencao_llm_dias := 90;

-- 0 = modo seguro (somente system_logs e llm_usage antigos).
-- 1 = também remove decisões/previsões/sinais antigos, perdendo a capacidade
--     de auditar previsões antigas e refazer avaliações/backtests desse período.
SET @limpar_historico_analitico := 0;
SET @retencao_analitica_dias := 180;

SET @corte_logs := DATE_SUB(NOW(), INTERVAL @retencao_logs_dias DAY);
SET @corte_llm := DATE_SUB(NOW(), INTERVAL @retencao_llm_dias DAY);
SET @corte_analitica := DATE_SUB(NOW(), INTERVAL @retencao_analitica_dias DAY);

-- 1) PRÉVIA: confira quantas linhas seriam afetadas antes de executar exclusões.
SELECT 'system_logs' AS tabela, COUNT(*) AS linhas_a_remover, MIN(created_at) AS mais_antigo, MAX(created_at) AS mais_recente
FROM system_logs WHERE created_at < @corte_logs;

SELECT 'llm_usage' AS tabela, COUNT(*) AS linhas_a_remover, MIN(created_at) AS mais_antigo, MAX(created_at) AS mais_recente
FROM llm_usage WHERE created_at < @corte_llm;

SELECT 'paper_trading_decisions opcional' AS tabela, COUNT(*) AS linhas_a_remover
FROM paper_trading_decisions WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica;

SELECT 'predictions opcional' AS tabela, COUNT(*) AS linhas_a_remover
FROM predictions WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica;

-- 2) LIMPEZA SEGURA. DELETE em InnoDB pode ser revertido antes do COMMIT.
-- Excluir em lotes reduz locks e carga. Repita os blocos até ROW_COUNT() retornar 0.
START TRANSACTION;

DELETE FROM system_logs
WHERE created_at < @corte_logs
ORDER BY created_at
LIMIT 10000;
SELECT ROW_COUNT() AS logs_removidos_neste_lote;

DELETE FROM llm_usage
WHERE created_at < @corte_llm
ORDER BY created_at
LIMIT 5000;
SELECT ROW_COUNT() AS registros_llm_removidos_neste_lote;

-- Opcional: excluir rastros analíticos antigos. A exclusão de results/shadow
-- antecede predictions para respeitar relações com chave estrangeira.
-- Ordens e trades são mantidos, portanto a auditoria financeira permanece.
DELETE FROM prediction_results
WHERE @limpar_historico_analitico = 1
  AND prediction_id IN (SELECT id FROM predictions WHERE created_at < @corte_analitica)
LIMIT 10000;

DELETE FROM athena50_shadow_predictions
WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica
LIMIT 10000;

DELETE FROM paper_trading_decisions
WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica
LIMIT 10000;

DELETE FROM predictions
WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica
  AND id NOT IN (SELECT prediction_id FROM paper_trading_decisions WHERE prediction_id IS NOT NULL)
  AND id NOT IN (SELECT prediction_id FROM paper_trading_v2_orders WHERE prediction_id IS NOT NULL)
  AND id NOT IN (SELECT entry_prediction_id FROM paper_trading_v2_trades WHERE entry_prediction_id IS NOT NULL)
  AND id NOT IN (SELECT exit_prediction_id FROM paper_trading_v2_trades WHERE exit_prediction_id IS NOT NULL)
  AND id NOT IN (SELECT prediction_id FROM paper_trading_equity WHERE prediction_id IS NOT NULL)
  AND id NOT IN (SELECT prediction_id FROM athena50_shadow_predictions WHERE prediction_id IS NOT NULL)
LIMIT 10000;

DELETE FROM signals
WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica
LIMIT 10000;

DELETE FROM market_regimes
WHERE @limpar_historico_analitico = 1 AND created_at < @corte_analitica
LIMIT 10000;

-- Verifique os números retornados. Se estiverem corretos:
COMMIT;
-- Para desfazer antes do COMMIT, execute ROLLBACK em vez de COMMIT.

-- 3) APÓS DELETE: InnoDB pode não devolver imediatamente o espaço ao sistema de
-- arquivos. OPTIMIZE TABLE reconstrói tabelas e pode exigir espaço temporário,
-- bloquear acesso e demorar. Rode em janela de manutenção, apenas se necessário:
-- OPTIMIZE TABLE system_logs, llm_usage;

-- Evite apagar market_data, prediction_results, paper_trading_equity, orders ou
-- trades como rotina: são referências para avaliação e auditoria dos bots.
