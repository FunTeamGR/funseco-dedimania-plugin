<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Funseco\Facades\Funseco;
use Funseco\Core\SharedKernel\Events\PlayerReady;
use Funseco\Dedimania\Presentation\DediRecordsWidget;

class PlayerReadyListener
{
    /** The join-time render may be dropped by a client still loading; draw again. */
    public function handle(PlayerReady $event): void
    {
        if (Funseco::getGameStatus() === 4 && CurrentMap::get()) {
            DediRecordsWidget::render($event->player);
        }
    }
}
