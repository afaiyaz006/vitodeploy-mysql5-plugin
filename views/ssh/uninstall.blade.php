#!/bin/bash
set -e

# Tear down the systemd wrapper unit.
sudo systemctl stop mysql.service 2>/dev/null || true
sudo systemctl disable mysql.service 2>/dev/null || true
sudo rm -f /etc/systemd/system/mysql.service
sudo systemctl daemon-reload

# Remove the container and its image.
if command -v docker >/dev/null 2>&1; then
    sudo docker rm -f mysql5 2>/dev/null || true
    sudo docker image rm mysql:5.7 2>/dev/null || true
fi

# Wipe data, socket, and credential files.
sudo rm -rf /var/lib/mysql5-data
sudo rm -rf /var/run/mysqld
sudo rm -f /root/.my.cnf
sudo rm -f /root/.mysql5_root_pw

# Deliberately leave Docker and mariadb-client installed — other plugins
# or services on this host may depend on them.
echo "Command executed"
