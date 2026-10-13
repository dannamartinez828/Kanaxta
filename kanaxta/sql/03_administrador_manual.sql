-- =====================================================================
-- Kanaxta - OPCIONAL: crear el usuario administrador por SQL
-- Lo normal es crearlo en  http://localhost/kanaxta/admin/crear_admin.php
-- Use este archivo solo si esa página no abre.
--
-- 1) Genere el hash de su contraseña (en CMD, con XAMPP):
--      C:\xampp\php\php.exe -r "echo password_hash('MiClaveSegura2026', PASSWORD_DEFAULT);"
--    Copie el texto que empieza por $2y$...
-- 2) Pegue ese texto en lugar de PEGUE_AQUI_EL_HASH y cambie 'admin' si quiere.
-- =====================================================================

INSERT INTO administrador (usuario, hash_contrasena)
VALUES ('admin', 'PEGUE_AQUI_EL_HASH');
