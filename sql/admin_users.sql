-- No phpMyAdmin, selecione primeiro o banco usado pelo site e execute este script.
-- Troque a chave de ativação abaixo por uma frase secreta longa e exclusiva.
-- Você usará essa mesma chave uma única vez na página /admin/setup.php.

CREATE TABLE IF NOT EXISTS admin_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(64) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_setup_tokens (
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  PRIMARY KEY (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @admin_setup_key = 'TROQUE-POR-UMA-CHAVE-SECRETA-LONGA-E-EXCLUSIVA';
INSERT INTO admin_setup_tokens (token_hash, expires_at, used_at)
VALUES (SHA2(@admin_setup_key, 256), DATE_ADD(NOW(), INTERVAL 24 HOUR), NULL)
ON DUPLICATE KEY UPDATE expires_at = VALUES(expires_at), used_at = NULL;

-- Após executar, abra /trade_prometheus/admin/setup.php e informe a chave acima,
-- seu usuário e uma senha forte (mínimo 14 caracteres). A chave será de uso único.
