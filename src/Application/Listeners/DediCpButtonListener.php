<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Dedimania\Presentation\DediCpButton;

/** Shows the DEDI CP button on a new map and to joiners; hides it at the end of the race. */
class DediCpButtonListener
{
    /** A restart leaves the old button on screen (maybe at an old position): draw it again. */
    public function startup(StartupEvent $event): void
    {
        DediCpButton::showAll(CurrentMap::get());
    }

    public function mapStarted(MapStarted $event): void
    {
        DediCpButton::showAll($event->map);
    }

    public function mapEnded(MapEnded $event): void
    {
        DediCpButton::hideAll();
    }

    public function playerConnected(PlayerConnected $event): void
    {
        DediCpButton::showTo($event->player, CurrentMap::get());
    }
}
