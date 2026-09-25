-- v2: `correct` pode ser NULL quando a direção realizada é FLAT
-- (retorno exatamente zero) — não é acerto nem erro. O valor
-- semântico do acerto direcional vive em directional_hit (NULL quando
-- FLAT/INDETERMINATE), mas `correct` mantém compatibilidade com queries
-- existentes e também admite NULL a partir da v2.
ALTER TABLE prediction_results
    MODIFY correct TINYINT(1) NULL COMMENT 'NULL quando FLAT/INDETERMINATE (v2)';
