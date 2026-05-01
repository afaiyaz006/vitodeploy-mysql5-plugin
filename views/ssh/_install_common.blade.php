#!/bin/bash
set -euo pipefail

# Defensive purge of any prior native MySQL install on this host.
sudo systemctl stop mysql 2>/dev/null || true
sudo systemctl stop mysql.service 2>/dev/null || true
sudo DEBIAN_FRONTEND=noninteractive apt-get purge -y \
    'mysql-server*' 'mysql-client*' 'mysql-community-*' 'mysql-common' 2>/dev/null || true
sudo DEBIAN_FRONTEND=noninteractive apt-get autoremove -y 2>/dev/null || true
sudo rm -f /etc/apt/sources.list.d/mysql.list \
           /etc/apt/preferences.d/mysql-5.7 \
           /usr/share/keyrings/mysql-archive-keyring.gpg \
           /etc/apt/trusted.gpg.d/mysql.gpg

# Install Docker from Docker's official apt repo if it's not already present.
if ! command -v docker >/dev/null 2>&1; then
    sudo DEBIAN_FRONTEND=noninteractive apt-get update
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
        ca-certificates curl gnupg lsb-release
    sudo install -m 0755 -d /etc/apt/keyrings
    if [ ! -s /etc/apt/keyrings/docker.gpg ]; then
        curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
            | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
        sudo chmod a+r /etc/apt/keyrings/docker.gpg
    fi
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" \
        | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
    sudo DEBIAN_FRONTEND=noninteractive apt-get update
    if ! sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
        docker-ce docker-ce-cli containerd.io; then
        echo 'VITO_SSH_ERROR' && exit 1
    fi
fi
sudo systemctl enable --now docker

# Host-side mysql client. We specifically want the MySQL 8 client, not
# mariadb-client (which is what `default-mysql-client` resolves to on jammy+).
# The 8.0 client talks to a 5.x server fine — wire protocol is backward
# compatible. The `[mysqldump] column-statistics=0` entry written to
# /root/.my.cnf below is what makes mysqldump 8 work against a 5.x server
# (its default tries to read information_schema.COLUMN_STATISTICS which
# only exists in 8.x).
if ! command -v mysql >/dev/null 2>&1; then
    if ! sudo DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-client-core-8.0; then
        echo "ERROR: could not install mysql-client-core-8.0 from apt."
        echo "The package lives in 'universe' on Ubuntu — make sure that"
        echo "component is enabled, or install a MySQL 8 client manually."
        echo 'VITO_SSH_ERROR' && exit 1
    fi
fi

# Persist a generated root password so re-running install doesn't break the
# stored data dir's credentials. Owned by root, mode 0600.
ROOT_PW_FILE=/root/.mysql5_root_pw
if ! sudo test -s "$ROOT_PW_FILE"; then
    sudo install -d -m 0700 /root
    head -c 32 /dev/urandom | base64 | tr -d '/+=\n' | head -c 24 \
        | sudo tee "$ROOT_PW_FILE" >/dev/null
    sudo chmod 0600 "$ROOT_PW_FILE"
fi
ROOT_PW=$(sudo cat "$ROOT_PW_FILE")

# Host directories: persistent data + shared socket directory.
sudo mkdir -p /var/lib/mysql5-data /var/run/mysqld
sudo chmod 0755 /var/run/mysqld

# Pull image, recreate container. No port mapping, no network — only the
# Unix socket bind-mounted to the host.
sudo docker pull {{ $image }}
sudo docker rm -f mysql5 2>/dev/null || true
if ! sudo docker run -d \
    --name mysql5 \
    --restart unless-stopped \
    --network none \
    -e MYSQL_ROOT_PASSWORD="$ROOT_PW" \
    -e MYSQL_ROOT_HOST=localhost \
    -v /var/lib/mysql5-data:/var/lib/mysql \
    -v /var/run/mysqld:/var/run/mysqld \
    {{ $image }} \
    --socket=/var/run/mysqld/mysqld.sock \
    --skip-networking; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

# Wait until mysqld is actually ready AND auth works. Note: `mysqladmin ping`
# returns success even on access-denied (it only checks server liveness), so
# we use a real authenticated SELECT here instead.
READY=0
for i in $(seq 1 90); do
    if sudo docker exec mysql5 mysql -uroot -p"$ROOT_PW" -e "SELECT 1" >/dev/null 2>&1; then
        READY=1
        break
    fi
    # If the server is up but auth fails, the data dir was previously
    # initialized with a different password — bail out fast with guidance
    # rather than waiting the full 90 iterations.
    if sudo docker exec mysql5 mysqladmin ping --silent >/dev/null 2>&1; then
        if [ "$i" -ge 5 ]; then
            echo "ERROR: mysql container is up but the stored root password in"
            echo "/root/.mysql5_root_pw does not match the password baked into the"
            echo "existing data dir at /var/lib/mysql5-data. The {{ $image }} entrypoint"
            echo "skips initialization (and ignores MYSQL_ROOT_PASSWORD) when the data"
            echo "dir already contains a 'mysql/' subdirectory."
            echo ""
            echo "To recover, run on this host and then re-install via Vito:"
            echo "  sudo docker rm -f mysql5"
            echo "  sudo rm -rf /var/lib/mysql5-data"
            echo "  sudo rm -f /root/.mysql5_root_pw /root/.my.cnf"
            echo 'VITO_SSH_ERROR' && exit 1
        fi
    fi
    sleep 2
done
if [ "$READY" -ne 1 ]; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

# Force native password auth on root@localhost so /root/.my.cnf creds work
# regardless of what the entrypoint defaulted to. Safe to run because we just
# proved the password is correct above.
if ! sudo docker exec -i mysql5 mysql -uroot -p"$ROOT_PW" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${ROOT_PW}';
FLUSH PRIVILEGES;
SQL
then
    echo 'VITO_SSH_ERROR' && exit 1
fi

# /root/.my.cnf — host-side `sudo mysql` and `sudo mysqldump -u root` pick
# this up automatically, which is how every Vito core database view works.
sudo tee /root/.my.cnf >/dev/null <<EOF
[client]
user=root
password=${ROOT_PW}
socket=/var/run/mysqld/mysqld.sock

[mysqldump]
user=root
password=${ROOT_PW}
socket=/var/run/mysqld/mysqld.sock
# The MySQL 8 client's mysqldump defaults to querying
# information_schema.COLUMN_STATISTICS, which only exists on 8.x — disabling
# it lets the same binary back up a 5.x server cleanly.
column-statistics=0
EOF
sudo chmod 0600 /root/.my.cnf

# systemd wrapper unit. Type=oneshot + RemainAfterExit=yes makes
# `systemctl status mysql` print "Active: active (exited)", which still
# satisfies Vito's Service::validateInstall substring check on
# "Active: active". The actual mysqld lifetime is owned by Docker
# (--restart unless-stopped); this unit just exposes start/stop hooks.
sudo tee /etc/systemd/system/mysql.service >/dev/null <<'UNIT'
[Unit]
Description=MySQL {{ $version }} (Docker container wrapper)
Requires=docker.service
After=docker.service network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/bin/docker start mysql5
ExecStartPost=/bin/sh -c 'for i in $(seq 1 60); do /usr/bin/docker exec mysql5 mysqladmin ping --silent && exit 0; sleep 2; done; exit 1'
ExecStop=/usr/bin/docker stop mysql5
TimeoutStartSec=180

[Install]
WantedBy=multi-user.target
UNIT

sudo systemctl daemon-reload
sudo systemctl enable mysql.service
sudo systemctl restart mysql.service

# Smoke test from the host. This is the same shape every Vito core view
# uses (`sudo mysql -e "..."`), so if it works here it works for create,
# delete, link, unlink, backup, restore, list, etc.
if ! sudo mysql -e "SELECT 1"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
