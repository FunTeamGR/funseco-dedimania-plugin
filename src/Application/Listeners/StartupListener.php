<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Dedimania\Application\FetchRecords;
use Funseco\Dedimania\Facades\Dedimania;

class StartupListener
{
    public function handle(StartupEvent $event)
    {
        Dedimania::authenticate()
            ->then(function () {
                if ($map = CurrentMap::get()) {
                    FetchRecords::for($map);
                }
            })
            ->catch(function (\Throwable $e) {
                \Funseco\Dedimania\Application\Outage::report($e, 'Authentication failed');
            });
    }
}
