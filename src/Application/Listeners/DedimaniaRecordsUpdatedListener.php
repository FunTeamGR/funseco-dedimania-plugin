<?php

namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Funseco\Facades\Funseco;
use Funseco\Dedimania\Presentation\DediRecordsWidget;

class DedimaniaRecordsUpdatedListener
{
    public function handle(DedimaniaRecordsUpdated $event)
    {
        if (Funseco::getGameStatus() !== 4 || !CurrentMap::get()) {
            return;
        }

        foreach (CurrentPlayers::all() as $player) {
            DediRecordsWidget::render($player);
        }
    }
}
