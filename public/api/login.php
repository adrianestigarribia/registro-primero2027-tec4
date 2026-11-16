<?php
// POST /api/login  { clave }  ->  abre la sesión del panel
// 200 { ok, debeCambiar }   debeCambiar = true si todavía es la contraseña inicial de la configuración
// 401 contraseña incorrecta · 429 IP bloqueada por intentos fallidos
require_once __DIR__ . '/_auth.php';

soloMetodo('POST');
mismoOrigen();
login(cuerpo()['clave'] ?? null);
