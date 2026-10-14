-- Migração 003 — perfil do usuário (ADMIN / USUARIO)
--
-- Necessária apenas para bancos criados antes desta versão do BancoDeDados.sql
-- (aplique a 002 antes, se ainda não aplicou). Execute uma única vez:
--
--   mysql -u root -p < migracoes/003_perfil_usuario.sql
--
-- Todos os usuários existentes ficam como USUARIO. Promova quem vai administrar
-- empresas e usuários (empresas.php / usuarios.php):
--
--   UPDATE usuarios SET perfil = 'ADMIN' WHERE email = 'voce@exemplo.com';

USE sistema;

ALTER TABLE usuarios
    ADD COLUMN perfil ENUM('ADMIN','USUARIO') NOT NULL DEFAULT 'USUARIO' AFTER senha_hash;
