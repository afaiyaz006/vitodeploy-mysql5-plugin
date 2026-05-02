#!/bin/bash
set -e

# Tear down the systemd wrapper unit.
sudo systemctl stop mysql.service 2>/dev/null || true
sudo systemctl disable mysql.service 2>/dev/null || true
sudo rm -f /etc/systemd/system/mysql.service
sudo systemctl daemon-reload

# Remove the container and the image it used. Look up the image *before*
# removing the container so we can drop whichever 5.x tag was actually used
# (5.5 / 5.6 / 5.7) instead of hardcoding one.
if command -v docker >/dev/null 2>&1; then
    IMAGE=$(sudo docker inspect mysql5 --format '@{{.Config.Image}}' 2>/dev/null || true)
    sudo docker rm -f mysql5 2>/dev/null || true
    if [ -n "$IMAGE" ]; then
        sudo docker image rm "$IMAGE" 2>/dev/null || true
    fi
fi

# Wipe data, socket, credential files, and any tmpfiles.d entry left over
# from earlier plugin versions (current plugin handles this via systemd
# unit ExecStartPre hooks instead).
sudo rm -f /etc/tmpfiles.d/mysql5.conf
sudo rm -rf /var/lib/mysql5-data
sudo rm -rf /var/run/mysqld
sudo rm -f /root/.my.cnf
sudo rm -f /root/.mysql5_root_pw

# Deliberately leave Docker and the mysql client package installed — other
# plugins or services on this host may depend on them.
echo "Command executed"
