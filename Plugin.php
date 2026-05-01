<?php

namespace App\Vito\Plugins\Afaiyaz006\VitodeployMysql5Plugin;

use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterServiceType;
use App\Plugins\RegisterViews;

class Plugin extends AbstractPlugin
{
    protected string $name = 'MySQL 5';

    protected string $description = 'Adds support for installing legacy MySQL 5.x (5.5 / 5.6 / 5.7) as Docker containers, exposing the daemon only over a host-shared Unix socket.';

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
                '5.6',
                '5.5',
            ])
            ->register();
    }
}
