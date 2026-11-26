# Inscripción a 1° año · E.E.S.T. N°4 Berazategui

Aplicación web para que las familias soliciten una vacante en 1° año desde el celular, con control estricto de cupos (68 vacantes), lista de espera automática, filtro por código postal de Berazategui y un panel privado para directivos y preceptoría.

Está hecha con el mismo estilo que [registro-muestra](https://github.com/haghen29/registro-muestra): **HTML, CSS y JavaScript puros** (sin frameworks, sin npm, sin CDN), **PHP 8 + PDO + MariaDB** y Apache.

---

## Sobre este proyecto

### El problema
Cada año la escuela recibe más solicitudes que vacantes para 1° año. Con inscripción en papel o con un formulario común es difícil saber quién llegó primero, controlar que el alumno viva en el partido y evitar que la misma familia mande la solicitud dos veces. Además, si muchas familias envían el formulario al mismo tiempo, un sistema mal hecho puede dar **más de 68 vacantes**.

### La solución
- **La familia completa un formulario corto desde el celular** y recibe una constancia con su **número de solicitud** y su **estado**:
  - **Inscripción Recibida** (verde): está dentro de las 68 vacantes.
  - **Lista de Espera** (naranja): llegó después de la vacante 68.
  - **Fuera de zona** (gris): el código postal no es de Berazategui. Se guarda aparte y no ocupa vacante.
- **La escuela ve todo en tiempo real** desde el panel: vacantes ocupadas, lista de espera y fuera de zona; busca por apellido o DNI; anula una solicitud, y la vacante pasa sola al primero de la lista de espera; y descarga todo en CSV para Excel.

### Por qué está pensada así
- **Concurrencia primero.** Lo más importante es que nunca haya más de 68 ingresantes, aunque 80 familias toquen "Enviar" en el mismo milisegundo. Ver [Cómo funciona el control de cupos](#cómo-funciona-el-control-de-cupos).
- **Primero el celular.** Casi todas las familias lo hacen desde el teléfono: botones grandes, letra de 18 px (el iPhone no hace zoom) y fecha de nacimiento con tres listas en vez de un calendario.
- **Datos de menores protegidos (Ley 25.326).** Aviso de privacidad visible antes de enviar, datos visibles solo con contraseña, nada público y nada guardado en el celular de la familia.

---

## Cómo funciona

| Dirección | Para quién | Qué hace |
|---|---|---|
| `/` | Familias | Formulario de inscripción y constancia |
| `/admin` | Directivos y preceptoría | Panel con contraseña |

### Reglas de la inscripción

| Situación | Qué pasa |
|---|---|
| CP de Berazategui y hay vacantes | **Inscripción Recibida** (en el panel: *Ingresante*) |
| CP de Berazategui y las 68 vacantes ocupadas | **Lista de Espera**, por orden de llegada |
| CP de otro partido | **Fuera de zona**: se guarda, no ocupa vacante ni entra en la lista de espera |
| El mismo DNI otra vez, con la misma fecha de nacimiento | Ve **su constancia original**. No se crea otra ni se ocupa otra vacante |
| El mismo DNI con **otra** fecha de nacimiento | Error: "comunicate con la escuela". No se muestran datos del otro alumno |
| La escuela anula una inscripción de *Ingresante* | La vacante pasa al **primero de la lista de espera** (en la misma transacción) |
| Un DNI anulado se vuelve a inscribir | Se reutiliza su fila y entra **al final** de la fila |

La constancia original pide DNI **y** fecha de nacimiento a propósito: así nadie puede ver los datos de un menor sabiendo solo su DNI.

---

## Índice

1. [Requisitos](#1-requisitos)
2. [Probarla en una compu con XAMPP](#2-probarla-en-una-compu-con-xampp)
3. [Configuración](#3-configuración)
4. [Instalarla en el servidor de la escuela](#4-instalarla-en-el-servidor-de-la-escuela)
5. [Prueba de concurrencia](#5-prueba-de-concurrencia)
6. [Cómo se usa el panel](#6-cómo-se-usa-el-panel)
7. [Seguridad y datos personales](#7-seguridad-y-datos-personales)
8. [Comandos SQL útiles](#8-comandos-sql-útiles)
9. [Problemas comunes](#9-problemas-comunes)
10. [Cómo está hecho](#10-cómo-está-hecho)

---

## 1. Requisitos

| Programa | Para qué |
|---|---|
| **Apache** con `mod_rewrite` y `mod_headers` | Sirve las páginas y la API. Lo usa `public/.htaccess` |
| **PHP 8.1 o superior** con `pdo_mysql`, `mbstring` y `curl` | La API (`public/api/*.php`). `curl` solo hace falta para la prueba de concurrencia |
| **MariaDB 10.4+** o MySQL 8 | La base de datos |

En una compu, **XAMPP 8.1 o 8.2** (apachefriends.org) trae todo junto.

---

## 2. Probarla en una compu con XAMPP

En los comandos, cambiá `C:\xampp` por la carpeta donde instalaste XAMPP.

### 2.1 Copiar el proyecto

Descomprimí el proyecto en `C:\xampp\htdocs\inscripcion-tec4` (tiene que quedar `C:\xampp\htdocs\inscripcion-tec4\public\index.html`).

### 2.2 Prender Apache y MySQL

En el **XAMPP Control Panel**, tocá **Start** en **Apache** y en **MySQL**. Las dos filas tienen que quedar en verde.

### 2.3 Crear la base y el usuario

Abrí una terminal (PowerShell) y entrá a MariaDB como root (en XAMPP no tiene contraseña; si te la pide, agregá `-p`):

```powershell
cd C:\xampp\mysql\bin
.\mysql.exe -u root --default-character-set=utf8mb4
```

Dentro de la consola (usá barras `/` en la ruta):

```sql
source C:/xampp/htdocs/inscripcion-tec4/schema.sql
CREATE USER 'inscripcion_user'@'localhost' IDENTIFIED BY 'test1234';
GRANT SELECT, INSERT, UPDATE ON inscripcion_tec4.* TO 'inscripcion_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

`schema.sql` crea la base `inscripcion_tec4` con cinco tablas: `inscripciones`, `control_cupos` (la fila "candado"), `ajustes` (contraseña cifrada), `intentos_login` y `limite_registro`. La app **nunca borra filas** (las anuladas se marcan), por eso el usuario no tiene permiso `DELETE`.

> Si dice `ERROR 1396 ... CREATE USER failed`, el usuario ya existía: ejecutá solo el `GRANT` y el `FLUSH`.

### 2.4 Abrirla

`public/api/_config.php` ya trae los datos de arriba (`inscripcion_user` / `test1234`). Abrí:

- http://localhost/inscripcion-tec4/public/ → formulario
- http://localhost/inscripcion-tec4/public/admin → panel (contraseña inicial: **`cambiar-esta-clave`**)

Para comprobar que la conexión a la base está bien: http://localhost/inscripcion-tec4/public/api/ping tiene que decir `"base":true`.

---

## 3. Configuración

Todo lo que se puede cambiar está en **un solo lugar**: `public/api/_config.php`. Para el servidor, **no edites ese archivo**: copiá `_config.local.example.php` como `_config.local.php` y poné ahí solo lo que cambia. `_config.local.php` no se sube a GitHub (`.gitignore`) y no se puede abrir desde el navegador (`.htaccess`).

| Clave | Valor inicial | Qué es |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME` | XAMPP | Conexión a MariaDB |
| `ADMIN_CLAVE` | `cambiar-esta-clave` | Contraseña **inicial** del panel (10 a 72 caracteres). Cuando se cambia desde el panel, esta deja de valer |
| `CUPO` | `68` | Vacantes de 1° año |
| `CP_BERAZATEGUI` | `1884,1885,1886,1890,1893,1894` | Códigos postales del partido. **Confirmalos con Correo Argentino antes de abrir la inscripción** |
| `CICLO_LECTIVO` | `2027` | Año en que empiezan 1° año |
| `EDAD_MIN`, `EDAD_MAX` | `11`, `15` | Edad permitida al 30/06 del ciclo lectivo. Con 2027: nacidos entre el 01/07/2011 y el 30/06/2016 |
| `LIMITE_IP` | `on` | Límite de envíos por IP (20 por minuto, 200 por hora). **`off` solo para la prueba de concurrencia** |

También se pueden pasar como variables de entorno del servidor (`CUPO=68`, etc.), que tienen prioridad sobre los dos archivos.

El formulario toma el ciclo lectivo y el rango de años de nacimiento de `/api/ping`, así que no hace falta tocar el HTML ni el JS cuando cambia el año.

---

## 4. Instalarla en el servidor de la escuela

### 4.1 Archivos y Apache

1. Copiá el proyecto, por ejemplo en `/var/www/inscripcion-tec4`.
2. En el VirtualHost, el **DocumentRoot tiene que ser `public/`**, así nada fuera de esa carpeta (schema, pruebas, README) queda en la web:

   ```apache
   <VirtualHost *:443>
     ServerName inscripcion.tec4.ar
     DocumentRoot /var/www/inscripcion-tec4/public
     <Directory /var/www/inscripcion-tec4/public>
       AllowOverride All
       Require all granted
     </Directory>
     # ... certificados HTTPS (por ejemplo, con certbot)
   </VirtualHost>
   ```
3. Activá los módulos y reiniciá:

   ```bash
   sudo a2enmod rewrite headers
   sudo systemctl reload apache2
   ```
4. Permisos: Apache solo necesita **leer** los archivos.

   ```bash
   sudo chown -R root:www-data /var/www/inscripcion-tec4
   sudo find /var/www/inscripcion-tec4 -type d -exec chmod 750 {} \;
   sudo find /var/www/inscripcion-tec4 -type f -exec chmod 640 {} \;
   ```

### 4.2 Base de datos

Igual que en el [paso 2.3](#23-crear-la-base-y-el-usuario), pero con una **contraseña larga** y con el host correcto: `'localhost'` si PHP y MariaDB están en la misma máquina, o la IP del servidor web si no. **Nunca `'%'`.**

```sql
source /var/www/inscripcion-tec4/schema.sql
CREATE USER 'inscripcion_user'@'localhost' IDENTIFIED BY 'una-contraseña-larga-y-aleatoria';
GRANT SELECT, INSERT, UPDATE ON inscripcion_tec4.* TO 'inscripcion_user'@'localhost';
FLUSH PRIVILEGES;
```

MariaDB no tiene que escuchar en internet (`bind-address = 127.0.0.1` en `my.cnf` si está en la misma máquina).

### 4.3 Configuración local

```bash
cd /var/www/inscripcion-tec4/public/api
sudo cp _config.local.example.php _config.local.php
sudo nano _config.local.php     # DB_PASSWORD, ADMIN_CLAVE, y CP/CUPO si cambian
sudo chown root:www-data _config.local.php && sudo chmod 640 _config.local.php
```

### 4.4 PHP

En el `php.ini` del servidor:

```ini
display_errors = Off        ; los errores van al log, nunca a la pantalla
log_errors = On
expose_php = Off
session.cookie_secure = On  ; la app ya lo pone sola si detecta HTTPS
```

### 4.5 Antes de abrir la inscripción (checklist)

- [ ] HTTPS activo (el candado del navegador). Sin HTTPS, la contraseña y los datos viajan sin cifrar.
- [ ] `https://.../api/ping` dice `"base":true`.
- [ ] `https://.../api/_config.php` da **403**.
- [ ] Entraste al panel y **cambiaste la contraseña inicial** (el panel avisa en naranja mientras no la cambies).
- [ ] La lista `CP_BERAZATEGUI` está confirmada.
- [ ] `LIMITE_IP` está en `on` (o no aparece en `_config.local.php`).
- [ ] La base está **vacía**: si hiciste pruebas, ver [Vaciar la base](#vaciar-la-base-de-prueba).
- [ ] Hay un backup programado (ver [comandos](#8-comandos-sql-útiles)).

---

## 5. Prueba de concurrencia

`pruebas/concurrencia.php` manda **80 inscripciones al mismo tiempo** (todas salen juntas con `curl_multi`) y verifica:

1. que queden **exactamente 68 "Inscripción Recibida" y 12 "Lista de Espera"**;
2. que no se repita ningún número de solicitud ni de orden;
3. que los 68 ingresantes sean **los primeros 68 en llegar** (orden 1 a 68) y la espera siga después, sin huecos;
4. que el **mismo DNI enviado 10 veces a la vez** se guarde una sola vez (1 nuevo y 9 "constancia original").

### Cómo correrla (en tu compu, nunca contra la base real)

1. **Vaciá la base** ([cómo](#vaciar-la-base-de-prueba)).
2. Creá `public/api/_config.local.php` con el límite por IP apagado (todos los pedidos salen de tu IP):

   ```php
   <?php
   return ['LIMITE_IP' => 'off'];
   ```
3. Corré la prueba:

   ```powershell
   cd C:\xampp\htdocs\inscripcion-tec4
   C:\xampp\php\php.exe pruebas\concurrencia.php http://localhost/inscripcion-tec4/public/ --clave=cambiar-esta-clave
   ```

   - `--clave` es opcional. Con la contraseña del panel, además revisa los contadores y el orden **guardado en la base**.
   - `--cantidad=200` prueba con más pedidos.
4. Al terminar: **borrá `_config.local.php`** (o volvé `LIMITE_IP` a `on`) y **vaciá la base**.

Resultado esperado (resumido):

```
== 1. 80 inscripciones en paralelo ==
  [OK]    Todas respondieron 201 (80 × HTTP 201)
  [OK]    Inscripción Recibida: 68 (esperado 68)
  [OK]    Lista de Espera: 12 (esperado 12)
== 2. Lo que quedó guardado en la base (vía panel) ==
  [OK]    Orden de llegada 1..80, sin repetidos ni huecos
  [OK]    Los ingresantes son exactamente los primeros 68 en llegar; la espera sigue después
== 3. El mismo DNI enviado 10 veces a la vez ==
  [OK]    Se guardó una sola vez (1 respuesta 201)
RESULTADO: TODO OK. El control de cupos resiste pedidos simultáneos.
```

> **¿La prueba de verdad detecta el problema?** Sí. Si a `tomarCandado()` (en `_cupos.php`) se le saca el `FOR UPDATE`, la misma prueba falla: muchos pedidos leen el mismo "último orden" y la base los rechaza por la clave única `uq_orden` (54 de 80 terminaron en error 409 en nuestra prueba). Sin el candado, el sistema no puede atender a todos de manera ordenada.

Si aparece `HTTP 429`, el límite por IP sigue activo (paso 2). Si la URL no termina en `public/`, el script no encuentra la API.

---

## 6. Cómo se usa el panel

Entrá a `/admin` con la contraseña.

### Contadores (se actualizan solos cada 3 segundos)

- **Barra de vacantes:** "X de 68 vacantes ocupadas" y cuántas quedan libres ("Cupo completo" cuando no queda ninguna).
- **Todas / Ingresantes / Lista de espera / Fuera de zona:** también son **filtros** de la tabla.
- El punto verde **"En vivo"** indica que está conectado. Si se corta, dice "Sin conexión, reintentando…" y sigue solo cuando vuelve.

### Tabla

Cada inscripción tiene una **etiqueta de color**: verde *Ingresante*, naranja *Lista de Espera* (con su posición: "1° en espera" es la próxima en entrar) y gris *Fuera de zona*. También pueden aparecer:

- **Promovida:** entró desde la lista de espera porque se liberó una vacante.
- **⚠ Formulario completado en menos de 5 segundos / Enviado sin usar el formulario:** marca del antiabuso. La inscripción es válida, pero conviene revisar los datos.

En la compu se ve como tabla; en el celular, cada inscripción es una tarjeta.

### Buscar

Por **apellido o nombre** del alumno (sin importar tildes ni mayúsculas: "perez jose" encuentra "Pérez, José María") o por **DNI** (con o sin puntos).

### Anular una inscripción

Tocá **Anular**. Antes de confirmar, el panel avisa qué va a pasar: si era *Ingresante* y hay lista de espera, dice **quién pasa a ocupar la vacante**. Al confirmar:

- la inscripción queda **anulada** (no se borra: aparece al final del CSV como "Anulada");
- la vacante pasa al primero de la lista de espera, **en la misma transacción** (no puede quedar a medias);
- todos los dispositivos con el panel abierto ven el cambio en segundos.

### Descargar CSV

El botón de **descarga** (arriba a la derecha) baja `inscripciones-1er-anio-AAAA-MM-DD-HHMM.csv`:

- se abre directo en **Excel** (UTF-8 con BOM, separador `;`), con tildes y ñ;
- orden: Ingresantes, Lista de Espera, Fuera de zona (cada grupo por orden de llegada) y al final las anuladas;
- protegido contra **inyección de fórmulas**: si una celda empezara con `=`, `+`, `-` o `@`, se le antepone un apóstrofo y Excel la muestra como texto.

El archivo tiene datos de menores: guardalo en una carpeta con acceso restringido y borralo cuando ya no haga falta.

### Contraseña

- La primera vez se entra con `ADMIN_CLAVE` (`cambiar-esta-clave`) y el panel muestra un aviso naranja hasta que la cambies con el botón de **llave**.
- Al cambiarla, **los demás dispositivos se desconectan solos** y tienen que entrar con la nueva.
- Después de **5 contraseñas incorrectas** desde la misma conexión, esa conexión queda bloqueada **5 minutos**. Ojo: si todos usan el mismo Wi-Fi, el bloqueo es para todos.
- La sesión dura como mucho 12 horas y se cierra al cerrar el navegador o con **Salir**.

---

## 7. Seguridad y datos personales

| Medida | Dónde |
|---|---|
| Consultas preparadas (PDO, `?`), nunca texto pegado al SQL | `public/api/*.php` |
| Validación en el navegador **y** en el servidor (la que vale es la del servidor) | `form.js` / `_validar.php` |
| Datos mostrados siempre con `textContent`, nunca `innerHTML` | `form.js`, `admin.js` |
| CSP solo `'self'`: sin scripts en línea ni archivos de otros sitios | `public/.htaccess` |
| `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS con HTTPS | `public/.htaccess` |
| Archivos internos (`_config.php`, `_db.php`, etc.) bloqueados desde la web | `public/.htaccess` |
| Contraseña del panel cifrada con bcrypt; sesión HttpOnly + SameSite=Strict; bloqueo por IP | `_auth.php` |
| Pedidos del panel: JSON obligatorio + control de `Origin` / `Sec-Fetch-Site` | `_db.php`, `_auth.php` |
| Antiabuso: campo trampa, tiempo de llenado, límite por IP | `_antiabuso.php` |
| Usuario de base con permisos mínimos (sin `DELETE`, `DROP`, `ALTER`) | `schema.sql` |
| Borrado lógico (nunca `DELETE` desde la app) | `_cupos.php` |
| `CHECK` en la base para DNI y CP, además de la validación de PHP | `schema.sql` |

### Ley 25.326 (datos de menores)

- El **aviso de privacidad** está en el formulario, **antes** del botón Enviar, y hay que aceptarlo (también lo exige el servidor).
- Los datos se ven **solo con contraseña**. Ninguna página pública muestra datos de nadie ni cuántas vacantes quedan.
- El celular de la familia **no guarda** nada del alumno. Para volver a ver la constancia hacen falta DNI **y** fecha de nacimiento.
- El panel no guarda datos en el navegador: todo vive en memoria y se borra al tocar **Salir**.
- **Plazo de conservación:** el aviso dice que, al terminar el proceso del ciclo, se eliminan los datos de quienes no ingresen. La app no lo hace sola: es una tarea de la escuela con [este comando](#al-terminar-el-proceso-de-inscripción). **La escuela tiene que definir la fecha y quién lo hace.** Si no lo va a hacer, cambiá esa frase en `index.html`.

---

## 8. Comandos SQL útiles

Entrá siempre con `--default-character-set=utf8mb4`; si no, las tildes que escribas se guardan mal:

```bash
mysql -u root -p --default-character-set=utf8mb4 inscripcion_tec4
```

### Ver cómo están las vacantes

```sql
SELECT estado, anulada, COUNT(*) FROM inscripciones GROUP BY estado, anulada;
```

### Ver la lista de espera en orden

```sql
SELECT orden, id, alumno_apellido, alumno_nombre, dni
  FROM inscripciones WHERE estado = 'espera' AND anulada = 0 ORDER BY orden;
```

### Vaciar la base de prueba

⚠️ **Borra TODAS las inscripciones.** Solo para tu compu o antes de abrir la inscripción real.

```sql
DELETE FROM inscripciones;
UPDATE control_cupos SET ultimo_orden = 0 WHERE id = 1;
DELETE FROM limite_registro;
DELETE FROM intentos_login;
ALTER TABLE inscripciones AUTO_INCREMENT = 1;
```

(La contraseña del panel se mantiene. Para volver también a la inicial, ver más abajo.)

### Olvidé la contraseña del panel

```sql
DELETE FROM ajustes WHERE clave = 'admin_clave';
```

Vuelve a valer `ADMIN_CLAVE` de la configuración. Entrá y cambiala enseguida.

### Desbloquear una conexión después de 5 contraseñas incorrectas

```sql
UPDATE intentos_login SET fallos = 0, bloqueado_hasta = NULL;
```

### Agregar el teléfono a una base que ya existía (migración v2)

Si la base se creó antes de que el formulario pidiera teléfono, corré esto **como root y antes de publicar el código nuevo** (el usuario de la app no puede hacer `ALTER`):

```bash
mysql -u root -p --default-character-set=utf8mb4 < migracion-v2-telefono.sql
```

En Dokploy: terminal del servicio MariaDB → `mariadb -u root -p` → pegá el contenido del archivo (cambiando `inscripcion_tec4` por tu `DB_NAME` si es otro). Las inscripciones anteriores quedan sin teléfono.

### Backup

```bash
mysqldump -u root -p --single-transaction --default-character-set=utf8mb4 inscripcion_tec4 > inscripcion-$(date +%F).sql
```

El backup tiene datos de menores: guardalo cifrado o con acceso restringido, y borralo con los demás datos al terminar el proceso.

### Al terminar el proceso de inscripción

Cuando la escuela lo decida (por ejemplo, al empezar las clases), se eliminan los datos de quienes no ingresaron, como dice el aviso de privacidad. **Primero exportá el CSV si lo necesitan.**

```sql
DELETE FROM inscripciones WHERE anulada = 1 OR estado <> 'ingresante';
DELETE FROM limite_registro;
DELETE FROM intentos_login;
```

### Recuperar una inscripción anulada por error

No se recomienda tocarla a mano (podría quedar con más de 68 vacantes ocupadas). Lo correcto es que la familia **vuelva a enviar el formulario con el mismo DNI**: la fila se reactiva y entra al final de la fila, con el estado que le corresponda en ese momento.

---

## 9. Problemas comunes

| Síntoma | Causa y solución |
|---|---|
| `/admin` o `/api/...` dan **404** | Falta `mod_rewrite` o `AllowOverride All`. En XAMPP viene activo; en el servidor: `sudo a2enmod rewrite` y revisá el `<Directory>` |
| Todo da **500** | Mirá el log de Apache (`C:\xampp\apache\logs\error.log` o `/var/log/apache2/error.log`). Casi siempre es la contraseña de la base o que falta `pdo_mysql` |
| `/api/ping` dice `"base":false` | Datos de conexión mal en `_config.local.php`, MySQL apagado, o el usuario no tiene `GRANT` |
| El formulario dice "No se pudo guardar" y el log dice `Falta la fila de control_cupos` | Se borró la fila candado. Ejecutá `INSERT IGNORE INTO control_cupos (id, ultimo_orden) VALUES (1, (SELECT COALESCE(MAX(orden), 0) FROM inscripciones));` |
| Muchas familias ven "Hay demasiados envíos seguidos desde esta conexión" | Comparten IP (por ejemplo, el Wi-Fi de la escuela en una jornada de inscripción). Subí `LIMITE_MINUTO` en `_antiabuso.php` |
| Alguien ve "Hay muchas familias enviando al mismo tiempo" (503) | La base tardó más de lo normal en dar el turno (`innodb_lock_wait_timeout`). Los datos siguen cargados: que toque Enviar otra vez |
| El CSV se ve con caracteres raros en Excel | Abrilo con doble clic (no con "Importar texto"). Si tu Excel está en inglés y espera `,`, usá *Datos → Desde texto/CSV* y elegí UTF-8 y `;` |
| Las tildes que cargué por consola aparecen raras | Entraste a `mysql` sin `--default-character-set=utf8mb4` |
| El panel dice "Tu sesión terminó" | Pasaron 12 horas, alguien cambió la contraseña o se cerró la sesión. Volvé a entrar |

---

## 10. Cómo está hecho

```
inscripcion-tec4/
├── .htaccess                     # si se sirve la carpeta entera: solo public/ es accesible
├── .gitignore
├── schema.sql                    # base completa (ya incluye el teléfono)
├── migracion-v2-telefono.sql     # agrega el teléfono a una base creada antes
├── README.md
├── pruebas/
│   └── concurrencia.php          # prueba de 80 pedidos simultáneos (PHP de línea de comandos)
└── public/                       # DocumentRoot
    ├── .htaccess                 # rutas limpias, bloqueo de "_", cabeceras de seguridad
    ├── index.html                # formulario + aviso de privacidad + constancia
    ├── admin.html                # panel
    ├── assets/
    │   ├── styles.css            # estilos comunes (mobile-first)
    │   ├── form.js               # formulario: filtros, validación, envío, constancia
    │   ├── admin.css
    │   └── admin.js              # panel: login, polling, tabla, anular, CSV, contraseña
    └── api/
        ├── _config.php           # ÚNICO lugar de configuración (cupo, CP, ciclo, edades)
        ├── _config.local.example.php
        ├── _db.php               # PDO, transacciones con reintento, respuestas JSON
        ├── _validar.php          # reglas de cada campo
        ├── _auth.php             # contraseña bcrypt, sesión, bloqueo por IP
        ├── _antiabuso.php        # campo trampa, tiempo de llenado, límite por IP
        ├── _cupos.php            # candado, asignación de estado, promoción, contadores
        ├── ping.php              # GET  público: estado de la base + ciclo/rango de fechas
        ├── registro.php          # POST público: inscripción
        ├── login.php · logout.php · clave.php
        ├── cupos.php             # GET  admin: contadores
        ├── inscripciones.php     # GET  admin: lista + contadores (?since= para el polling)
        ├── anular.php            # POST admin: anular + promover
        └── exportar.php          # GET  admin: CSV
```

### Cómo funciona el control de cupos

El riesgo: dos familias envían el formulario en el mismo milisegundo cuando queda **una** vacante. Si cada pedido cuenta "hay 67, queda una" y después guarda, **los dos** entran: 69 ingresantes.

La solución está en `_cupos.php` y usa una **fila candado** (`control_cupos`, una sola fila):

```
BEGIN
  SELECT ultimo_orden FROM control_cupos WHERE id = 1 FOR UPDATE;   ← toma el candado
                                                                      (los demás esperan acá)
  SELECT ... FROM inscripciones WHERE dni = ? FOR UPDATE;           ← ¿ya estaba ese DNI?
  SELECT COUNT(*) ... estado = 'ingresante' LOCK IN SHARE MODE;     ← cuenta con el candado tomado
  INSERT ... estado = (cuenta < 68 ? 'ingresante' : 'espera'), orden = ultimo_orden + 1
  UPDATE control_cupos SET ultimo_orden = ultimo_orden + 1;
COMMIT                                                              ← suelta el candado
```

- Mientras un pedido tiene el candado, los otros **esperan en la primera línea**. Recién cuando hace `COMMIT` entra el siguiente, y ya ve lo que guardó el anterior. Así se atienden de a uno, en orden.
- El conteo se hace **dentro** del bloqueo; por eso no puede quedar viejo.
- La conexión usa `READ COMMITTED` y el conteo es una lectura con bloqueo: siempre ve lo último confirmado.
- **Anular** usa el mismo candado: anula, cuenta y promueve al primero de la espera (`ORDER BY orden`) en la misma transacción.
- Siempre se bloquea en el mismo orden (primero `control_cupos`, después las inscripciones), así no hay bloqueos mutuos entre inscribir y anular. Si igual la base devuelve un bloqueo mutuo (1213) o una espera vencida (1205), `transaccion()` reintenta hasta 3 veces.
- Hay redes de seguridad en la base: `UNIQUE (dni)` impide dos inscripciones del mismo alumno y `UNIQUE (orden)` impide dos con el mismo lugar en la fila.

### Por qué `orden` y no el `id`

El `id` (número de solicitud) es `AUTO_INCREMENT`: puede tener huecos si una transacción se deshace, y no cambia cuando un DNI anulado se vuelve a inscribir. El `orden` se asigna **dentro del candado**, así que es el orden de llegada real; la lista de espera se promueve por ese número.

### Actualización en tiempo real (polling)

El panel pide `/api/inscripciones?since=<cursor>` cada 3 segundos y recibe solo lo que cambió desde la última vez (más los contadores). El cursor tiene 2 segundos de margen para no perder escrituras confirmadas justo en ese momento. Si la pestaña está oculta, deja de consultar.

### Lo que se reutilizó de registro-muestra

Transacciones con `FOR UPDATE`, contraseña con bcrypt y bloqueo por IP, antiabuso (campo trampa, tiempo de llenado, límite por IP), borrado lógico, panel con polling `?since=`, configuración en `_config.local.php`, `.htaccess` con rutas limpias y cabeceras de seguridad, y la estructura de `public/api/` con archivos internos `_*.php`.

No se trajo: QR, check-in, detector de nombres de famosos, fusiones ni alias de alumnos.
