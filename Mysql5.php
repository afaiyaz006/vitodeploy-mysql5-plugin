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
