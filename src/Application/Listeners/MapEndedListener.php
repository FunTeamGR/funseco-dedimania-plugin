<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Dedimania\Application\FetchRecords;
use Funseco\Dedimania\Application\RankingLine;
use Funseco\Dedimania\Facades\Dedimania;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class MapEndedListener
{
    public function handle(MapEnded $event)
    {
        \Funseco\Core\Services\ManiaLink\Facades\ManiaLink::resetAll('dedi_records'); // not on the podium

        // Take the records now so the next map starts clean, however long Dedimania takes.
        $records = DedimaniaRecords::allForMap($event->map->id);

        // The ranking needs the records and flags of this map; it stays quiet when Dedimania
        // never answered for it (offline), where it could only say "no records".
        $down = \Funseco\Dedimania\Application\Outage::down($event->map->id);
        if (!$down && !$event->wasWarmup && DedimaniaRecords::stats($event->map->id) !== []) {
            FetchRecords::announce((int) config('dedimania.database.show_recs_after', 1), RankingLine::AFTER, $event->map);
        }

        // Not cleared here: the score screen lists this map's Dedimania records; the next map's fetch replaces them.

        if ($down) {
            return;
        }

        Dedimania::challengeRaceTimes($event->challengeInfo, $records)
            ->catch(function (\Throwable $e) {
                \Funseco\Dedimania\Application\Outage::report($e, 'Failed to submit race times');
            });
    }
}
