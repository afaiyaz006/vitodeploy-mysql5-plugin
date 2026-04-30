# Vito Deploy — MySQL 5 Plugin

Adds support for installing legacy MySQL 5.x versions in Vito Deploy.

## Supported versions

- **MySQL 5.7** — installs from the official MySQL APT repository

> MySQL 5.7 reached end-of-life in October 2023 and no longer receives security updates from Oracle. Use only for legacy applications that cannot be upgraded.

## What it does

- Registers a new database service named **MySQL 5** (id: `mysql5`)
- Installs MySQL 5.7 via the `repo.mysql.com/apt/ubuntu` repository, pinned above 8.x
- Reuses Vito's core MySQL scripts for create/delete/user/backup/restore operations (SQL syntax is identical between 5.7 and 8.x for these)

## Compatibility

- **Ubuntu 18.04 (bionic)**: ✅ native MySQL 5.7 packages
- **Ubuntu 20.04 (focal)**: ✅ native MySQL 5.7 packages
- **Ubuntu 22.04+**: ⚠️ falls back to focal packages — works but unsupported by Oracle

## Installation

Drop this directory into `storage/plugins/` and enable it via the Vito plugin manager.

## Notes

- Only one database service can be installed per server — uninstall any existing MySQL/MariaDB/PostgreSQL service first.
- The systemd unit name (`mysql`) is the same as MySQL 8.x, so the two cannot coexist on the same server.
