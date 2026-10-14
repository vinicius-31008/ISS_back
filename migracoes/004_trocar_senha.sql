-- Migração 004 — troca de senha obrigatória (usada pelo usuário padrão de primeiro acesso)
--
-- Necessária apenas para bancos criados antes desta versão do BancoDeDados.sql
-- (aplique a 003 antes, se ainda não aplicou). Execute uma única vez:
--
--   mysql -u root -p < migracoes/004_trocar_senha.sql

USE sistema;

ALTER TABLE usuarios
    ADD COLUMN trocar_senha TINYINT(1) NOT NULL DEFAULT 0 AFTER perfil;
