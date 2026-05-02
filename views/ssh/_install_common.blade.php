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
# Pre-chown to UID 999 — the `mysql` user inside all official mysql:5.x
# images. The mysql:5.7 entrypoint chowns these itself, but 5.5 and 5.6
# don't, so without this mysqld dies on first start with
# "[ERROR] Can't start server: Bind on unix socket: Permission denied".
# Without docker userns-remap (the default), in-container UID 999 is
# host UID 999, so this is the right owner from both views. Same security
# model Vito's native MySQL install uses (specific UID owns data dir).
sudo mkdir -p /var/lib/mysql5-data /var/run/mysqld
sudo chown 999:999 /var/lib/mysql5-data /var/run/mysqld
sudo chmod 0755 /var/run/mysqld

# /var/run is on tmpfs and gets wiped on every reboot, taking /var/run/mysqld
# with it. Without this tmpfiles.d entry, after reboot Docker (--restart
# unless-stopped) auto-recreates the missing bind-mount source as root:root,
# which the in-container mysql user can't write to → mysqld crash-loops and
# the socket file never appears. systemd-tmpfiles-setup runs at
# sysinit.target, well before docker.service, so the directory is in place
# with the right owner by the time the container comes back up.
sudo tee /etc/tmpfiles.d/mysql5.conf >/dev/null <<'TMP'
d /var/run/mysqld 0755 999 999 -
TMP
sudo systemd-tmpfiles --create /etc/tmpfiles.d/mysql5.conf

# Preflight: refuse to proceed if the data dir was previously initialized by
# a *different* MySQL major version. InnoDB's on-disk format isn't compatible
# across 5.5 / 5.6 / 5.7 in either direction, so reusing the data dir would
# crash mysqld with a checksum-mismatch error after the entrypoint correctly
# decides to skip initialization (because /var/lib/mysql/mysql already
# exists). The marker file is written at the end of a successful install,
# so its presence confirms a clean prior install owns this data dir.
TARGET_VERSION="{{ $version }}"
VERSION_MARKER=/var/lib/mysql5-data/.mysql5-installed-version
DATA_DIR_PRE_EXISTED=0
if sudo test -d /var/lib/mysql5-data/mysql; then
    DATA_DIR_PRE_EXISTED=1
    INSTALLED_VERSION=""
    if sudo test -s "$VERSION_MARKER"; then
        INSTALLED_VERSION=$(sudo cat "$VERSION_MARKER")
    fi
    if [ -z "$INSTALLED_VERSION" ]; then
        echo "ERROR: /var/lib/mysql5-data already contains MySQL data, but no"
        echo "version marker is present. This usually means a previous install"
        echo "was not cleanly uninstalled (older plugin version, or interrupted"
        echo "cleanup). Reusing this data dir would crash mysqld with an InnoDB"
        echo "checksum error."
        echo ""
        echo "To recover, run on this host and then re-install via Vito:"
        echo "  sudo docker rm -f mysql5 2>/dev/null"
        echo "  sudo rm -rf /var/lib/mysql5-data /var/run/mysqld"
        echo "  sudo rm -f /root/.my.cnf /root/.mysql5_root_pw"
        echo 'VITO_SSH_ERROR' && exit 1
    elif [ "$INSTALLED_VERSION" != "$TARGET_VERSION" ]; then
        echo "ERROR: /var/lib/mysql5-data was initialized by MySQL"
        echo "$INSTALLED_VERSION but you are installing $TARGET_VERSION."
        echo "In-place version switching is not supported: InnoDB's on-disk"
        echo "format differs between 5.5 / 5.6 / 5.7."
        echo ""
        echo "To switch versions, back up first, then on this host run:"
        echo "  sudo docker rm -f mysql5 2>/dev/null"
        echo "  sudo rm -rf /var/lib/mysql5-data /var/run/mysqld"
        echo "  sudo rm -f /root/.my.cnf /root/.mysql5_root_pw"
        echo "Then re-install at $TARGET_VERSION via Vito and restore your dump."
        echo 'VITO_SSH_ERROR' && exit 1
    fi
fi

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
#
# The fast-fail "stored password mismatches data dir" guard only runs when
# the data dir was already populated *before* this install (i.e. we're
# re-using an existing data dir). On a fresh install, the entrypoint runs
# its multi-second init phase during which a temp mysqld is up on the same
# socket but root has no password yet — auth-with-$ROOT_PW failing during
# that window is normal, not a stale-data problem. Without this gate, the
# guard fires falsely on every fresh install.
READY=0
for i in $(seq 1 90); do
    if sudo docker exec mysql5 mysql -uroot -p"$ROOT_PW" -e "SELECT 1" >/dev/null 2>&1; then
        READY=1
        break
    fi
    if [ "$DATA_DIR_PRE_EXISTED" -eq 1 ] \
        && sudo docker exec mysql5 mysqladmin ping --silent >/dev/null 2>&1 \
        && [ "$i" -ge 5 ]; then
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
    sleep 2
done
if [ "$READY" -ne 1 ]; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

# Re-set the root password explicitly so /root/.my.cnf creds work regardless
# of what the entrypoint defaulted to. SQL syntax differs by version:
#   - 5.5/5.6: ALTER USER only supports PASSWORD EXPIRE — must use
#     `SET PASSWORD ... = PASSWORD('...')` (and rely on the default
#     mysql_native_password auth plugin, which is the only one loaded).
#   - 5.7+: `ALTER USER ... IDENTIFIED WITH mysql_native_password BY '...'`
#     is the idiomatic form and lets us pin the auth plugin explicitly.
@if (in_array($version, ['5.5', '5.6']))
if ! sudo docker exec -i mysql5 mysql -uroot -p"$ROOT_PW" <<SQL
SET PASSWORD FOR 'root'@'localhost' = PASSWORD('${ROOT_PW}');
FLUSH PRIVILEGES;
SQL
then
    echo 'VITO_SSH_ERROR' && exit 1
fi
@else
if ! sudo docker exec -i mysql5 mysql -uroot -p"$ROOT_PW" <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${ROOT_PW}';
FLUSH PRIVILEGES;
SQL
then
    echo 'VITO_SSH_ERROR' && exit 1
fi
@endif

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

# Stamp the data dir with the version we just installed. Subsequent installs
# read this back to refuse incompatible cross-version installs (5.5 ↔ 5.6 ↔
# 5.7) before they fail mid-flight with a confusing InnoDB checksum error.
echo "{{ $version }}" | sudo tee /var/lib/mysql5-data/.mysql5-installed-version >/dev/null
sudo chmod 0644 /var/lib/mysql5-data/.mysql5-installed-version
