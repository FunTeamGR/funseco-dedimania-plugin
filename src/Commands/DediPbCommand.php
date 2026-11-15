<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class DediPbCommand extends DediCommand
{
    protected string $name = 'dedipb';
    protected string $description = 'Shows your Dedimania personal best on current track';

    public function handle(Player $player, array $args): void
    {
        $records = $this->records();
        $rank = DedimaniaRecords::rankOf($player->id, $records);
        $this->tell($player, $rank
            ? Messages::get('pb', format_score($records[$rank - 1]->score()), $rank)
            : Messages::get('pb_none'));
    }
}
