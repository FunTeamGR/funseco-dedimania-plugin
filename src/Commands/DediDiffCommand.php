<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class DediDiffCommand extends DediCommand
{
    protected string $name = 'dedidiff';
    protected string $description = 'Shows your difference to first Dedimania record';

    public function handle(Player $player, array $args): void
    {
        $records = $this->records();
        $rank = DedimaniaRecords::rankOf($player->id, $records);
        if (!$rank) {
            $this->tell($player, Messages::get('diff_pb_none'));

            return;
        }
        $own = $records[$rank - 1];
        $first = $records->first();
        $this->tell($player, $this->diffMessage($this->fragment($rank, $own), $this->fragment(1, $first), $own->score() - $first->score()));
    }
}
