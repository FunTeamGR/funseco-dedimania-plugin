<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\Events\GameStatusChanged;
use Funseco\Dedimania\Presentation\DediRecordsWidget;

class GameStatusChangedListener
{
    public function handle(GameStatusChanged $event)
    {
        if (!$event->isPlaying()) {
            ManiaLink::resetAll('dedi_records');
        } elseif (CurrentMap::get()) {
            foreach (CurrentPlayers::all() as $player) {
                DediRecordsWidget::render($player);
            }
        }
    }
}
