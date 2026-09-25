-- SemÃ¢ntica de avaliaÃ§Ã£o v2: target direcional BINÃRIO (UP/DOWN), FLAT explÃ­cito.
-- v1 (ternÃ¡ria SIDEWAYS) legada: resultados antigos sÃ£o recomputados
-- deterministicamente por admin/migrate_eval_v2.php (mesma regra do evaluator,
-- com dados que jÃ¡ existiam na criaÃ§Ã£o da previsÃ£o: initial_price + market_data).
ALTER TABLE prediction_results
    MODIFY actual_direction ENUM('UP','DOWN','FLAT','SIDEWAYS') NOT NULL,
    ADD COLUMN IF NOT EXISTS directional_hit TINYINT(1) NULL COMMENT '1/0 somente quando predicted e actual em {UP,DOWN}; NULL para FLAT/INDETERMINATE',
    ADD COLUMN IF NOT EXISTS realized_return DECIMAL(14,10) NULL COMMENT 'retorno realizado fracionÃ¡rio (target-initial)/initial',
    ADD COLUMN IF NOT EXISTS evaluation_version TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'v1=ternÃ¡ria legada; v2=binÃ¡ria DirectionPolicy';
