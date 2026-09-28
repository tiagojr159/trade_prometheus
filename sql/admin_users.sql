-- Execute no banco configurado em config/database.php (padrão: prometheus).
-- Cria a tabela usada pelo login administrativo.
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

-- Depois de criar um hash bcrypt/Argon2id com PHP password_hash(), insira o usuário:
-- INSERT INTO admin_users (username, password_hash) VALUES ('SEU_USUARIO', 'COLE_O_HASH_GERADO_AQUI');
