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
     */
    protected function getScriptView(string $script): string
    {
        if (str_starts_with($script, 'install-') || $script === 'uninstall') {
            return 'mysql5::ssh.'.$script;
        }

        return 'ssh.services.database.mysql.'.$script;
    }
}
