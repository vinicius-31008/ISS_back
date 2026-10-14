CREATE DATABASE IF NOT EXISTS sistema
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sistema;

-- Empresa é o "dono" dos dados: importações, prestadores e tomadores (e, por meio
-- deles, notas e erros) pertencem a uma empresa e só são vistos pelos usuários dela.
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
    -- ADMIN gerencia empresas e usuários (empresas.php / usuarios.php); USUARIO só importa e consulta.
    perfil ENUM('ADMIN','USUARIO') NOT NULL DEFAULT 'USUARIO',
    -- 1 = senha provisória (ex.: usuário padrão): até trocar, o token só serve para trocar-senha.php.
    trocar_senha TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_usuario_email (email),
    KEY idx_usuarios_empresa (empresa_id),
    CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tokens de login. Guarda só o SHA-256 do token: quem lê o banco não consegue se autenticar.
CREATE TABLE IF NOT EXISTS sessoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    token_hash CHAR(64) NOT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    UNIQUE KEY uk_sessao_token (token_hash),
    CONSTRAINT fk_sessoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS importacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    usuario_id INT NULL,
    arquivo VARCHAR(255) NOT NULL,
    data_importacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_linhas INT NOT NULL DEFAULT 0,
    total_processadas INT NOT NULL DEFAULT 0,
    total_erros INT NOT NULL DEFAULT 0,
    iss_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('PROCESSADA','PROCESSADA_COM_ERROS','ERRO') NOT NULL DEFAULT 'PROCESSADA',
    KEY idx_importacoes_empresa_data (empresa_id, data_importacao),
    CONSTRAINT fk_importacoes_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id),
    CONSTRAINT fk_importacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prestadores e tomadores são cadastrados por empresa: a importação de uma empresa
-- não altera a razão social que outra empresa vê, e a mesma nota pode existir em
-- empresas diferentes sem colidir na chave única de notas.
CREATE TABLE IF NOT EXISTS prestadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(200) NULL,
    inscricao_municipal VARCHAR(30) NULL,
    municipio_ibge VARCHAR(7) NULL,
    uf CHAR(2) NULL,
    UNIQUE KEY uk_prestador_empresa_cnpj (empresa_id, cnpj),
    CONSTRAINT fk_prestadores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tomadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    cpf_cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(200) NULL,
    municipio_ibge VARCHAR(7) NULL,
    uf CHAR(2) NULL,
    UNIQUE KEY uk_tomador_empresa_documento (empresa_id, cpf_cnpj),
    CONSTRAINT fk_tomadores_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servicos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_lc116 VARCHAR(20) NOT NULL,
    descricao VARCHAR(500) NOT NULL,
    nbs VARCHAR(20) NULL,
    ind_op VARCHAR(20) NULL,
    cclass_trib VARCHAR(20) NULL,
    aliquota_iss DECIMAL(8,4) NOT NULL DEFAULT 0.0000,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uk_servico_lc116 (codigo_lc116)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NULL,
    numero VARCHAR(50) NOT NULL,
    serie VARCHAR(20) NOT NULL DEFAULT '',
    data_emissao DATETIME NOT NULL,
    prestador_id INT NOT NULL,
    tomador_id INT NULL,
    servico_id INT NOT NULL,
    municipio_prestacao VARCHAR(7) NULL,
    valor_servico DECIMAL(15,2) NOT NULL,
    base_calculo DECIMAL(15,2) NOT NULL,
    aliquota_iss DECIMAL(8,4) NOT NULL,
    valor_iss DECIMAL(15,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'CALCULADA',
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    -- Uma nota é identificada pelo prestador + número + série, independente da importação.
    -- Reimportar a mesma nota atualiza o registro em vez de duplicá-lo. Como o prestador
    -- é cadastrado por empresa, a chave já separa as notas de cada empresa.
    UNIQUE KEY uk_nota_prestador (prestador_id, numero, serie),
    KEY idx_notas_importacao (importacao_id),
    CONSTRAINT fk_notas_importacao FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE SET NULL,
    CONSTRAINT fk_notas_prestador FOREIGN KEY (prestador_id) REFERENCES prestadores(id),
    CONSTRAINT fk_notas_tomador FOREIGN KEY (tomador_id) REFERENCES tomadores(id),
    CONSTRAINT fk_notas_servico FOREIGN KEY (servico_id) REFERENCES servicos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS erros_importacao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    importacao_id INT NOT NULL,
    numero_linha INT NOT NULL,
    mensagem VARCHAR(1000) NOT NULL,
    linha_original TEXT NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_erros_importacao FOREIGN KEY (importacao_id) REFERENCES importacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemplo mínimo. Cadastre/importe a tabela oficial de serviços do seu município
-- antes de processar produção. A alíquota abaixo é apenas demonstrativa.
INSERT INTO servicos (codigo_lc116, descricao, nbs, ind_op, cclass_trib, aliquota_iss)
VALUES ('01.01', 'Análise e desenvolvimento de sistemas', '1.1502.10.00', '100301', NULL, 5.0000)
ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), nbs = VALUES(nbs), ind_op = VALUES(ind_op);

-- Primeiro acesso: enquanto não houver nenhum usuário, o login cria o administrador
-- padrão definido em src/backend/UsuarioPadrao.php, com troca de senha obrigatória.
-- Alternativa: criar o primeiro administrador pelo script, que gera o hash da senha.
-- Os demais podem ser criados por ele via API (empresas.php / usuarios.php):
--   php scripts/criar_usuario.php --cnpj=12345678000190 --empresa="Empresa Exemplo Ltda" --nome="Fulano" --email=fulano@exemplo.com --senha=... --perfil=ADMIN
