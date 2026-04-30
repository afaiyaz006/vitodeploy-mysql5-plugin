<?php

namespace App\Vito\Plugins\Siteway\VitodeployMysql5Plugin;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServiceType;
use App\Plugins\RegisterViews;

class Plugin extends AbstractPlugin
{
    protected string $name = 'MySQL 5';

    protected string $description = 'Adds support for installing legacy MySQL 5.x versions (5.7) via the MySQL APT repository.';

    public function boot(): void
    {
        RegisterViews::make('mysql5')
            ->path(__DIR__.'/views')
            ->register();

        RegisterServiceType::make(Mysql5::id())
            ->type(Mysql5::type())
            ->label('MySQL 5')
            ->handler(Mysql5::class)
            ->versions([
                '5.7',
            ])
            ->configPaths([
                [
                    'name' => 'my.cnf',
                    'path' => '/etc/mysql/my.cnf',
                    'sudo' => true,
                ],
            ])
            ->register();
    }
}
