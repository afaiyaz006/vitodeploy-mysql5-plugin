<?php

namespace App\Vito\Plugins\Afaiyaz006\VitodeployMysql5Plugin;

use App\Services\Database\Mysql;

class Mysql5 extends Mysql
{
    protected string $defaultCharset = 'utf8mb4';

    public static function id(): string
    {
        return 'mysql5';
    }

    public function unit(): string
    {
        return 'mysql';
    }

    /**
     * Report the version of the MySQL server inside the container, not the
     * host-side `mysql -V` binary (which is the MySQL 8 client we install
     * for compatibility — reporting it would always say 8.x). Asking the
     * server directly via SELECT VERSION() also doubles as a connectivity
     * check, since it traverses the same socket + /root/.my.cnf pathway
     * every other Vito database operation uses.
     */
    public function version(): string
    {
        $output = $this->service->server->ssh()->exec(
            "sudo mysql -BN -e 'SELECT VERSION()'",
            'get-mysql5-version'
        );

        if (preg_match('/[0-9]+\.[0-9]+\.[0-9]+/', $output, $matches) === 1) {
            return $matches[0];
        }

        return trim($output);
    }

    /**
     * Use plugin views for version-specific install scripts and the uninstall
     * script, and reuse core MySQL views for everything else (SQL syntax is
     * identical between 5.7 and 8.x for create/user/link/backup/restore ops).
     *
     * MySQL 5.5 and 5.6 lack several syntax additions made in 5.7
     * (CREATE USER IF NOT EXISTS — 5.7.6, DROP USER IF EXISTS — 5.7.8,
     * ALTER USER ... IDENTIFIED BY — 5.7.6). Override those user-management
     * views with pre-5.7 equivalents (GRANT USAGE / DELETE-from-mysql.user
     * / SET PASSWORD).
     */
    protected function getScriptView(string $script): string
    {
        if (str_starts_with($script, 'install-') || $script === 'uninstall') {
            return 'mysql5::ssh.'.$script;
        }

        if (
            in_array($this->service->version, ['5.5', '5.6'], true)
            && in_array($script, ['create-user', 'delete-user', 'update-user'], true)
        ) {
            return 'mysql5::ssh.legacy.'.$script;
        }

        return 'ssh.services.database.mysql.'.$script;
    }
}
