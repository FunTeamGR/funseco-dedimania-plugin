<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Dedimania\Messages;

class DediFirstCommand extends DediCommand
{
    protected string $name = 'dedifirst';
    protected string $description = 'Shows first Dedimania record on current track';

    public function handle(Player $player, array $args): void
    {
        if ($records = $this->recordsOrNone($player)) {
            $this->tell($player, Messages::get('first_record').$this->fragment(1, $records->first()));
        }
    }
}
