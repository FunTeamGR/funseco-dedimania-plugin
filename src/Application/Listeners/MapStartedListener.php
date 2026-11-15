<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Dedimania\Application\FetchRecords;

class MapStartedListener
{
    public function handle(MapStarted $event)
    {
        \Funseco\Dedimania\Application\Outage::newMap();
        \Funseco\Dedimania\Infrastructure\DedimaniaRecords::clearForMap((int) $event->map->id); // the podium kept the last run's
        FetchRecords::for($event->map, announce: true);
    }
}
