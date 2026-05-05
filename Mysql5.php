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
     * Use plugin views for the install / uninstall scripts and for every
     * user-management op (create / update / delete / link / unlink). Reuse
     * core MySQL views for everything else — list, backup, restore, charsets
     * — where the SQL is identical and there's no plugin-specific behavior.
     *
     * Two reasons the user-management views diverge from core:
     *
     * 1. SQL dialect. MySQL 5.5/5.6 lack `CREATE USER IF NOT EXISTS` (5.7.6),
     *    `DROP USER IF EXISTS` (5.7.8), and `ALTER USER ... IDENTIFIED BY`
     *    (5.7.6). The plugin views use pre-5.7-compatible idioms
     *    (`GRANT USAGE`, mysql.user pre-check + `DROP USER`, `SET PASSWORD
     *    = PASSWORD()`), with runtime version detection where 5.7's syntax
     *    diverges from 5.5/5.6.
     *
     * 2. Dual-host grants. This plugin installs mysqld with
     *    `--bind-address=127.0.0.1` and `--network host`, so TCP listens on
     *    loopback only. MySQL's `'user'@'localhost'` matches *only* socket
     *    connections (mysqld special-cases the literal string "localhost"),
     *    so an app connecting via `-h 127.0.0.1` against a `'@localhost'`
     *    grant gets `ERROR 1130: Host '127.0.0.1' is not allowed`. The
     *    plugin views always create both `'@localhost'` and `'@127.0.0.1'`
     *    rows per Vito user (plus the user-supplied host if remote), and
     *    update/delete/link/unlink discover all matching hosts at SQL
     *    runtime and apply the operation to each row.
     */
    protected function getScriptView(string $script): string
    {
        if (str_starts_with($script, 'install-') || $script === 'uninstall') {
            return 'mysql5::ssh.'.$script;
        }

        if (in_array($script, ['create-user', 'update-user', 'delete-user', 'link', 'unlink'], true)) {
            return 'mysql5::ssh.legacy.'.$script;
        }

        return 'ssh.services.database.mysql.'.$script;
    }
}
