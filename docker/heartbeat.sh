#/bin/sh

mkdir -p /var/www/idp.amtgard.com/logs /var/www/idp.amtgard.com/config/cache/twig
chown www-data:www-data /var/www/idp.amtgard.com/logs /var/www/idp.amtgard.com/config/cache/twig 2>/dev/null || true

/usr/sbin/service nginx start
/usr/sbin/service php8.4-fpm start
/usr/sbin/service memcached start

while true; do sleep 1; done
