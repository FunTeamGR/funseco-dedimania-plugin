<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Player;

/** Click on the DEDI CP button: same as typing /dedicptms. */
class OpenCpTimesWindow
{
    public function handle(Player $player, string $action): void
    {
        \Chat::run($player, 'dedicptms');
    }
}
