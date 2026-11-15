<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class DediNextCommand extends DediCommand
{
    protected string $name = 'dedinext';
    protected string $description = 'Shows next better Dedimania record to beat';

    public function handle(Player $player, array $args): void
    {
        $records = $this->records();
        $rank = DedimaniaRecords::rankOf($player->id, $records);

        if ($rank) {
            $own = $records[$rank - 1];
            $next = $records[max(1, $rank - 1) - 1];
            $this->tell($player, $this->diffMessage($this->fragment($rank, $own), $this->fragment(max(1, $rank - 1), $next), $own->score() - $next->score()));

            return;
        }

        // no Dedimania record: compare the player's best local finish with the last record
        $map = CurrentMap::get();
        $best = $map ? (app(FinishHistory::class)->bestScores($player)[$map->id] ?? null) : null;
        if ($best === null || $records->isEmpty()) {
            $this->tell($player, Messages::get('next_pb_none'));

            return;
        }
        $last = $records->last();
        $this->tell($player, $this->diffMessage(
            Messages::fragment('PB', $player->nickname, $best), $this->fragment($records->count(), $last), $best - $last->score()
        ));
    }
}
