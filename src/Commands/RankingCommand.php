<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Dedimania\Application\RankingLine;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;

/** /dedinew and /dedilive: the ranking chat line, privately. */
abstract class RankingCommand extends DediCommand
{
    protected string $mode;

    public function handle(Player $player, array $args): void
    {
        $map = CurrentMap::get();
        if (!$map) {
            return;
        }
        $line = RankingLine::build(
            $this->mode,
            $this->records(),
            DedimaniaRecords::newIds(),
            CurrentPlayers::all()->pluck('login')->all(),
            $map,
            DedimaniaRecords::stats()['uid'] ?? null,
            private: true,
        );
        $this->tell($player, $line);
    }
}
