<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Player;

/** Click on the Dedimania widget: opens the full Dedimania Records window (/estat dedirecs). */
class OpenRecordsWindow
{
    public function handle(Player $player, string $action): void
    {
        \Chat::run($player, 'estat', ['dedirecs']);
    }
}
