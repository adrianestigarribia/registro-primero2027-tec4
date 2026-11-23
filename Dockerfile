# Inscripción a 1° año – E.E.S.T. N°4 Berazategui
# Imagen para Dokploy (o cualquier servidor con Docker). Es un solo archivo: no necesita
# nada más que el código del repo.
#
# - Apache + PHP 8.2 (imagen oficial). Apache y no Nginx porque la app depende de los
#   .htaccess (rutas /admin y /api/..., bloqueo de los _*.php, cabeceras de seguridad).
# - El sitio público es SOLO la carpeta public/.
# - La configuración llega por VARIABLES DE ENTORNO de Dokploy (DB_HOST, DB_PASSWORD, ...).
# - La base NO va acá: es un servicio MariaDB aparte en Dokploy.
#
# "bookworm" fija la versión de Debian, así el build da siempre lo mismo.
FROM php:8.2-apache-bookworm

# Extensión para MariaDB, módulos de Apache y cliente de MariaDB (solo para crear la base
# una vez desde la terminal del contenedor; la app no lo usa).
#   rewrite  -> rutas limpias (.htaccess)
#   headers  -> cabeceras de seguridad (.htaccess)
#   remoteip -> IP real de cada familia detrás del proxy de Dokploy
RUN apt-get update \
 && apt-get install -y --no-install-recommends mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers remoteip \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# ---------- PHP (se suma a php.ini-production) ----------
RUN printf '%s\n' \
  '; Ajustes de la app de inscripción' \
  'expose_php = Off' \
  'display_errors = Off' \
  'display_startup_errors = Off' \
  'log_errors = On' \
  'date.timezone = America/Argentina/Buenos_Aires' \
  'session.use_strict_mode = 1' \
  'session.use_only_cookies = 1' \
  'session.cookie_httponly = 1' \
  'session.cookie_samesite = Strict' \
  'session.gc_maxlifetime = 43200' \
  'file_uploads = Off' \
  'post_max_size = 1M' \
  > "$PHP_INI_DIR/conf.d/zz-inscripcion.ini"

# ---------- Apache ----------
# Detrás del proxy de Dokploy (Traefik), sin esto la app vería a TODAS las familias con la
# misma IP (el límite de envíos y el bloqueo del login serían para todos juntos) y creería
# que la conexión es HTTP (cookie sin "Secure", sin HSTS).
#   RemoteIP...: toma la IP real de X-Forwarded-For, pero SOLO si el pedido viene de una IP
#                interna (redes de Docker). Desde internet nadie puede inventarse una IP.
#                Si delante hay Cloudflare con la nube naranja, agregar sus rangos con
#                RemoteIPTrustedProxy.
#   SetEnvIf...: si el proxy avisa que la conexión original era HTTPS, PHP lo sabe.
RUN printf '%s\n' \
  'ServerTokens Prod' \
  'ServerSignature Off' \
  'TraceEnable Off' \
  'RemoteIPHeader X-Forwarded-For' \
  'RemoteIPInternalProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.1' \
  'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on' \
  '' \
  '<VirtualHost *:80>' \
  '    ServerName localhost' \
  '    DocumentRoot /var/www/app/public' \
  '    <Directory /var/www/app/public>' \
  '        Options -Indexes +FollowSymLinks' \
  '        AllowOverride All' \
  '        Require all granted' \
  '    </Directory>' \
  '    # Logs a la consola del contenedor (pestaña Logs de Dokploy), con la IP real (%a)' \
  '    LogFormat "%a %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" ip_real' \
  '    ErrorLog ${APACHE_LOG_DIR}/error.log' \
  '    CustomLog ${APACHE_LOG_DIR}/access.log ip_real' \
  '</VirtualHost>' \
  > /etc/apache2/sites-available/000-default.conf

# ---------- Código ----------
# Dueño root y grupo www-data: Apache puede LEER los archivos, pero no modificarlos.
# Nunca se copia un _config.local.php: en Dokploy se usan variables de entorno.
COPY --chown=root:www-data . /var/www/app
RUN rm -rf /var/www/app/.git /var/www/app/public/api/_config.local.php \
 && find /var/www/app -type d -exec chmod 750 {} + \
 && find /var/www/app -type f -exec chmod 640 {} +

EXPOSE 80

# Dokploy usa esto para saber si el contenedor está sano (revisa que Apache y PHP respondan)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD php -r 'exit(strpos((string) @file_get_contents("http://127.0.0.1/api/ping"), "\"ok\":true") === false ? 1 : 0);'
