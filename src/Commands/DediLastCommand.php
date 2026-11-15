<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Dedimania\Messages;

class DediLastCommand extends DediCommand
{
    protected string $name = 'dedilast';
    protected string $description = 'Shows last Dedimania record on current track';

    public function handle(Player $player, array $args): void
    {
        if ($records = $this->recordsOrNone($player)) {
            $this->tell($player, Messages::get('last_record').$this->fragment($records->count(), $records->last()));
        }
    }
}
