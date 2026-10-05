-- Migração 001 — chave única de notas por prestador + número + série
--
-- Necessária apenas para bancos criados com a versão anterior do BancoDeDados.sql
-- (chave uk_nota_origem = importacao_id, numero, serie). Instalações novas já
-- nascem com o schema correto. Execute uma única vez e FAÇA BACKUP antes:
--
--   mysqldump -u root -p sistema > backup_sistema.sql
--   mysql -u root -p < migracoes/001_chave_unica_notas.sql

USE sistema;

-- 1. Índice próprio para a FK de importacao_id (hoje ela se apoia em uk_nota_origem,
--    que será removida).
ALTER TABLE notas ADD INDEX idx_notas_importacao (importacao_id);

-- 2. Série passa a ser obrigatória (vazia quando não informada), para que a chave
--    única funcione — NULL não é comparado em UNIQUE KEY.
UPDATE notas SET serie = '' WHERE serie IS NULL;
ALTER TABLE notas MODIFY serie VARCHAR(20) NOT NULL DEFAULT '';

-- 3. Remove notas duplicadas (mesmo prestador + número + série gravadas por
--    reimportações), mantendo a mais recente — o mesmo comportamento que o
--    importador passa a ter. Para conferir antes o que será apagado:
--
--   SELECT prestador_id, numero, serie, COUNT(*) AS qtd
--   FROM notas GROUP BY prestador_id, numero, serie HAVING qtd > 1;
DELETE antiga
FROM notas antiga
JOIN notas nova
  ON nova.prestador_id = antiga.prestador_id
 AND nova.numero = antiga.numero
 AND nova.serie = antiga.serie
 AND nova.id > antiga.id;

-- 4. Troca a chave única.
ALTER TABLE notas
    DROP INDEX uk_nota_origem,
    ADD UNIQUE KEY uk_nota_prestador (prestador_id, numero, serie);
