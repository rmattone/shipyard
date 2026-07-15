#!/bin/sh
# Creates the test database used by the Laravel test suite (phpunit.xml points
# DB_DATABASE at server_management_testing) and grants the application user
# access to it. Runs automatically on first initialization of the MySQL volume.
# For existing volumes, run the same statements manually (see README).
set -e

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<EOSQL
CREATE DATABASE IF NOT EXISTS server_management_testing;
GRANT ALL PRIVILEGES ON server_management_testing.* TO '$MYSQL_USER'@'%';
FLUSH PRIVILEGES;
EOSQL
