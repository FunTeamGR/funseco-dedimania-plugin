<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Map;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Dedimania\Facades\Dedimania;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

/**
 * Fetches a map's Dedimania records in the background and stores them under
 * that map, even if the server has moved on by the time they arrive.
 */
class FetchRecords
{
    /** @param bool $announce show the "records before the race" ranking when the records arrive */
    public static function for(Map $map, bool $announce = false): void
    {
        $mapId = $map->id;

        Dedimania::currentChallengeInfo($map)
            ->then(function (array $info) use ($map, $mapId, $announce) {
                DedimaniaRecords::setForMap($mapId, $info['records']);
                DedimaniaRecords::setStats($mapId, [
                    'uid'             => $info['uid'] ?? $map->uid,
                    'total_races'     => $info['total_races'],
                    'total_players'   => $info['total_players'],
                    'server_max_rank' => $info['server_max_rank'],
                ]);
                event(new DedimaniaRecordsUpdated());

                if (CurrentMap::get()?->id !== $mapId) {
                    return;
                }
                DediCheckpoints::applyToAll();
                if ($announce && !Outage::down($mapId)) {
                    self::announce((int) config('dedimania.database.show_recs_before', 1), RankingLine::BEFORE, $map);
                }
            })
            ->catch(function (\Throwable $e) {
                Outage::report($e, 'Failed to fetch records');
            });
    }

    /** Map start/end summary: 0 nothing, 1 the chat line, 2 (or +4) the records window. */
    public static function announce(int $setting, string $mode, Map $map): void
    {
        if ($setting <= 0) {
            return;
        }
        if (RecordsWindow::wantsWindow($setting)) {
            RecordsWindow::openForAll(DedimaniaRecords::allForMap($map->id), DedimaniaRecords::newIds($map->id));

            return;
        }
        Messages::broadcast(self::ranking($mode, $map));
    }

    public static function ranking(string $mode, Map $map): string
    {
        return RankingLine::build(
            $mode,
            DedimaniaRecords::allForMap($map->id),
            DedimaniaRecords::newIds($map->id),
            CurrentPlayers::all()->pluck('login')->all(),
            $map,
            DedimaniaRecords::stats($map->id)['uid'] ?? null,
        );
    }
}
