#!/bin/bash
set -e

# Stop and purge any existing MySQL installation
sudo systemctl stop mysql 2>/dev/null || true
sudo systemctl stop mysql.service 2>/dev/null || true

sudo DEBIAN_FRONTEND=noninteractive apt-get purge -y mysql-server mysql-server-* mysql-client mysql-common mysql-community-server 2>/dev/null || true
sudo DEBIAN_FRONTEND=noninteractive apt-get autoremove -y 2>/dev/null || true
sudo DEBIAN_FRONTEND=noninteractive apt-get autoclean 2>/dev/null || true

# Remove old repository and keys
sudo rm -f /etc/apt/sources.list.d/mysql.list
sudo rm -f /usr/share/keyrings/mysql-archive-keyring.gpg
sudo rm -f /etc/apt/trusted.gpg.d/mysql.gpg

sudo DEBIAN_FRONTEND=noninteractive apt-get update

sudo DEBIAN_FRONTEND=noninteractive apt-get install -y wget lsb-release gnupg debconf-utils

# Fetch the MySQL GPG key from keyserver and export to proper keyring location
gpg --keyserver hkps://keyserver.ubuntu.com --recv-keys B7B3B788A8D3785C 467B942D3A79BD29 A8D3785C
gpg --export B7B3B788A8D3785C | sudo tee /usr/share/keyrings/mysql-archive-keyring.gpg > /dev/null

# Detect Ubuntu codename. MySQL 5.7 is only officially packaged for bionic/focal.
# For newer releases (jammy+), fall back to focal packages.
CODENAME=$(lsb_release -sc)
case "$CODENAME" in
    bionic|focal)
        REPO_CODENAME="$CODENAME"
        ;;
    *)
        REPO_CODENAME="focal"
        ;;
esac

# MySQL 5.7 links against libssl1.1, which was dropped from jammy+ main repos.
# Pull the latest 1.1.1f-1ubuntu2.x build from focal-security when not already present.
if ! dpkg -s libssl1.1 >/dev/null 2>&1; then
    ARCH=$(dpkg --print-architecture)
    LIBSSL_DEB="/tmp/libssl1.1.deb"
    LIBSSL_INDEX="/tmp/libssl1.1.index.html"
    LIBSSL_FILE=""
    if wget -qO "$LIBSSL_INDEX" "http://security.ubuntu.com/ubuntu/pool/main/o/openssl/"; then
        LIBSSL_FILE=$(grep -oE "libssl1\.1_1\.1\.1f-1ubuntu2\.[0-9]+_${ARCH}\.deb" "$LIBSSL_INDEX" \
            | sort -V | tail -n1)
    fi
    rm -f "$LIBSSL_INDEX"
    if [ -z "$LIBSSL_FILE" ]; then
        LIBSSL_FILE="libssl1.1_1.1.1f-1ubuntu2.24_${ARCH}.deb"
    fi
    sudo wget -qO "$LIBSSL_DEB" "http://security.ubuntu.com/ubuntu/pool/main/o/openssl/${LIBSSL_FILE}"
    sudo dpkg -i "$LIBSSL_DEB" || sudo DEBIAN_FRONTEND=noninteractive apt-get install -fy
    sudo rm -f "$LIBSSL_DEB"
fi

# Add MySQL 5.7 repository
echo "deb [signed-by=/usr/share/keyrings/mysql-archive-keyring.gpg] http://repo.mysql.com/apt/ubuntu ${REPO_CODENAME} mysql-5.7" | sudo tee /etc/apt/sources.list.d/mysql.list
echo "deb [signed-by=/usr/share/keyrings/mysql-archive-keyring.gpg] http://repo.mysql.com/apt/ubuntu ${REPO_CODENAME} mysql-apt-config" | sudo tee -a /etc/apt/sources.list.d/mysql.list

# Pin MySQL 5.7 above 8.0 to ensure 5.7 packages are selected
sudo tee /etc/apt/preferences.d/mysql-5.7 > /dev/null <<'EOF'
Package: mysql-community-server mysql-community-client mysql-client mysql-server mysql-common libmysqlclient20
Pin: version 5.7.*
Pin-Priority: 1001
EOF

sudo DEBIAN_FRONTEND=noninteractive apt-get update

# Pre-seed an empty root password so the install doesn't prompt
sudo debconf-set-selections <<'EOF'
mysql-community-server mysql-community-server/root-pass password
mysql-community-server mysql-community-server/re-root-pass password
EOF

sudo DEBIAN_FRONTEND=noninteractive \
    apt-get -o Dpkg::Options::="--force-confdef" \
            -o Dpkg::Options::="--force-confold" \
    install -y mysql-community-server=5.7.* mysql-community-client=5.7.* mysql-client=5.7.* mysql-server=5.7.*

sudo systemctl unmask mysql.service
sudo systemctl enable mysql
sudo systemctl start mysql

# Switch root@localhost to auth_socket so subsequent `sudo mysql` commands work without password
if ! sudo mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED WITH auth_socket;"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi
