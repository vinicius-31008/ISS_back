-- Migração 002 — empresas, usuários e separação dos dados por empresa
--
-- Necessária apenas para bancos criados antes desta versão do BancoDeDados.sql
-- (aplique a 001 antes, se ainda não aplicou). Instalações novas já nascem com o
-- schema correto. Execute uma única vez e FAÇA BACKUP antes:
--
--   mysqldump -u root -p sistema > backup_sistema.sql
--   mysql -u root -p < migracoes/002_empresas_usuarios.sql
--
-- Os dados já existentes (importações, prestadores, tomadores) são atribuídos a uma
-- empresa "padrão" com CNPJ 00000000000000. Depois da migração, ajuste o CNPJ e a
-- razão social dela e crie os usuários com scripts/criar_usuario.php:
--
--   UPDATE empresas SET cnpj = '...', razao_social = '...' WHERE cnpj = '00000000000000';

USE sistema;

-- 1. Novas tabelas.
CREATE TABLE IF NOT EXISTS empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(200) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_empresa_cnpj (cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuario_email (email),
    KEY idx_usuarios_empresa (empresa_id),
    CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    UNIQUE KEY uk_sessao_token (token_hash),
    CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Empresa que recebe os dados existentes.
INSERT INTO empresas (cnpj, razao_social)
VALUES ('00000000000000', 'Empresa padrão (dados anteriores à migração 002)')
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id);

SET @empresa_padrao = LAST_INSERT_ID();

-- 3. Importações: empresa (obrigatória) e usuário que importou (opcional; nulo
--    nas importações antigas).
ALTER TABLE importacoes
    ADD COLUMN empresa_id INT NULL AFTER id,
    ADD COLUMN usuario_id INT NULL AFTER empresa_id;

UPDATE importacoes SET empresa_id = @empresa_padrao;

ALTER TABLE importacoes
    MODIFY empresa_id INT NOT NULL,
    ADD KEY idx_importacoes_empresa_data (empresa_id, data_importacao),
    ADD CONSTRAINT fk_importacoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    ADD CONSTRAINT fk_importacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- 4. Prestadores: chave única passa a ser empresa + CNPJ.
ALTER TABLE prestadores ADD COLUMN empresa_id INT NULL AFTER id;

UPDATE prestadores SET empresa_id = @empresa_padrao;

ALTER TABLE prestadores
    MODIFY empresa_id INT NOT NULL,
    DROP INDEX uk_prestador_cnpj,
    ADD UNIQUE KEY uk_prestador_empresa_cnpj (empresa_id, cnpj),
    ADD CONSTRAINT fk_prestadores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id);

-- 5. Tomadores: chave única passa a ser empresa + documento.
ALTER TABLE tomadores ADD COLUMN empresa_id INT NULL AFTER id;

UPDATE tomadores SET empresa_id = @empresa_padrao;

ALTER TABLE tomadores
    MODIFY empresa_id INT NOT NULL,
    DROP INDEX uk_tomador_documento,
    ADD UNIQUE KEY uk_tomador_empresa_documento (empresa_id, cpf_cnpj),
    ADD CONSTRAINT fk_tomadores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id);

-- Notas não mudam: a chave (prestador_id, numero, serie) já separa por empresa,
-- porque cada prestador agora pertence a uma única empresa.
