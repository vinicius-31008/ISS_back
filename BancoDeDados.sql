CREATE DATABASE IF NOT EXISTS sistema
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE sistema;

CREATE TABLE IF NOT EXISTS importacoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(255) NOT NULL,
    data_importacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    total_linhas INT NOT NULL DEFAULT 0,
    total_processadas INT NOT NULL DEFAULT 0,
    total_erros INT NOT NULL DEFAULT 0,
    iss_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('PROCESSADA','PROCESSADA_COM_ERROS','ERRO') NOT NULL DEFAULT 'PROCESSADA'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prestadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(200) NULL,
    inscricao_municipal VARCHAR(30) NULL,
    municipio_ibge VARCHAR(7) NULL,
    uf CHAR(2) NULL,
    UNIQUE KEY uk_prestador_cnpj (cnpj)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tomadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cpf_cnpj VARCHAR(14) NOT NULL,
    razao_social VARCHAR(200) NULL,
    municipio_ibge VARCHAR(7) NULL,
    uf CHAR(2) NULL,
    UNIQUE KEY uk_tomador_documento (cpf_cnpj)
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
    -- Reimportar a mesma nota atualiza o registro em vez de duplicá-lo.
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
