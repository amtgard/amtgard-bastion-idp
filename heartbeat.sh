#/bin/sh

/usr/sbin/service nginx start
/usr/sbin/service php8.3-fpm start
/usr/sbin/service memcached start
/usr/sbin/service redis-server start

while true; do sleep 1; done