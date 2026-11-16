<?php
// Datos del servidor de la escuela.
// 1. Copiá este archivo con el nombre _config.local.php (en esta misma carpeta).
// 2. Completá los valores. Solo hace falta poner los que cambian respecto de _config.php.
// _config.local.php no se sube a GitHub y el .htaccess impide abrirlo desde el navegador.

return [
    'DB_HOST' => 'localhost',                 // o la IP/nombre del servidor de MariaDB
    'DB_PORT' => 3306,
    'DB_USER' => 'inscripcion_user',
    'DB_PASSWORD' => 'una-contraseña-larga-y-segura',
    'DB_NAME' => 'inscripcion_tec4',
    'ADMIN_CLAVE' => 'otra-contraseña-larga',   // inicial; cambiala desde el panel el primer día
    // 'CUPO' => 68,
    // 'CP_BERAZATEGUI' => '1884,1885,1886,1890,1893,1894',
    // 'CICLO_LECTIVO' => 2027,
];
