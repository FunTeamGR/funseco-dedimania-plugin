<?php

namespace Funseco\Dedimania\Infrastructure;

use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Core\SharedKernel\Values\Record;
use Funseco\Core\SharedKernel\Values\RecordCollection;

class DedimaniaRecords
{
    public const DEFAULT_MAX_RANK = 30;

    public static function all(): RecordCollection
    {
        return self::allForMap((int) CurrentMap::get()?->id);
    }

    public static function allForMap(int $mapId): RecordCollection
    {
        return state()->get(self::key($mapId), new RecordCollection());
    }

    /**
     * Store records fetched from Dedimania for a map. Finishes recorded while
     * the fetch was in flight are merged in, so none are lost.
     */
    public static function setForMap(int $mapId, RecordCollection $fetched): void
    {
        $records = $fetched->sortByRank();

        foreach (self::allForMap($mapId) as $pending) {
            $records = self::merge($records, $pending->player, $pending->score(), $pending->checkpoints);
        }

        state()->forever(self::key($mapId), $records);
    }

    /**
     * Register a finish. Returns what happened to the player's record, or null when the
     * finish was slower than their Dedimania record: ['kind' => 'first'|'improved'|'equal',
     * 'rank', 'oldRank', 'oldScore'] (ranks are 1-based positions, unbounded by max rank).
     */
    public static function update(Player $player, int $score, CheckpointCollection $checkpoints): ?array
    {
        $mapId    = (int) CurrentMap::get()?->id;
        $current  = self::allForMap($mapId);
        $previous = self::find($current, $player->id);
        $oldRank  = $previous->hasNoTime ? null : self::rankIn($current, $player->id);
        $updated  = self::merge($current, $player, $score, $checkpoints);

        if ($updated === $current) {
            return !$previous->hasNoTime && $score === $previous->score()
                ? ['kind' => 'equal', 'rank' => $oldRank, 'oldRank' => $oldRank, 'oldScore' => $previous->score()]
                : null;
        }

        state()->forever(self::key($mapId), $updated);
        self::markNew($mapId, $player->id);
        event(new DedimaniaRecordsUpdated);

        return [
            'kind'     => $previous->hasNoTime ? 'first' : 'improved',
            'rank'     => self::rankIn($updated, $player->id),
            'oldRank'  => $oldRank,
            'oldScore' => $previous->hasNoTime ? null : $previous->score(),
        ];
    }

    /** Did this player improve on the server during the current map? ("NewBest") */
    public static function isNew(int $playerId, ?int $mapId = null): bool
    {
        return in_array($playerId, state()->get(self::newKey($mapId ?? (int) CurrentMap::get()?->id), []), true);
    }

    /** @return int[] */
    public static function newIds(?int $mapId = null): array
    {
        return state()->get(self::newKey($mapId ?? (int) CurrentMap::get()?->id), []);
    }

    /** 1-based rank of the player in the current records, or null. */
    public static function rankOf(int $playerId, ?RecordCollection $records = null): ?int
    {
        return self::rankIn($records ?? self::all(), $playerId);
    }

    /**
     * Track statistics from the current-challenge response, per map.
     *
     * @param array{uid?: string, total_races?: int, total_players?: int, server_max_rank?: int} $stats
     */
    public static function setStats(int $mapId, array $stats): void
    {
        state()->forever(self::statsKey($mapId), $stats);
    }

    public static function stats(?int $mapId = null): array
    {
        return state()->get(self::statsKey($mapId ?? (int) CurrentMap::get()?->id), []);
    }

    public static function serverMaxRank(?int $mapId = null): int
    {
        return (int) (self::stats($mapId)['server_max_rank'] ?? self::DEFAULT_MAX_RANK);
    }

    /** A player's record shows their newer nickname once they join with it. */
    public static function refreshNickname(string $login, ?string $nickname): void
    {
        if (!$nickname) {
            return;
        }
        $records = self::all();
        foreach ($records as $record) {
            if ($record->player?->login === $login && $record->player->nickname !== $nickname) {
                $record->player->nickname = $nickname;
                state()->forever(self::key((int) CurrentMap::get()?->id), $records);
            }
        }
    }

    public static function markBanned(string $login): void
    {
        state()->forever('dedimaniaBanned:' . $login, true);
    }

    public static function isBanned(string $login): bool
    {
        return (bool) state()->get('dedimaniaBanned:' . $login, false);
    }

    public static function setPlayerMaxRank(string $login, int $rank): void
    {
        state()->forever('dedimaniaMaxRank:' . $login, $rank);
    }

    public static function playerMaxRank(string $login): int
    {
        return (int) state()->get('dedimaniaMaxRank:' . $login, self::DEFAULT_MAX_RANK);
    }

    /** max(server max rank, the player's max rank) */
    public static function maxRank(?string $login = null): int
    {
        return max(self::serverMaxRank(), $login ? self::playerMaxRank($login) : 0);
    }

    private static function markNew(int $mapId, int $playerId): void
    {
        $ids = self::newIds($mapId);
        if (!in_array($playerId, $ids, true)) {
            $ids[] = $playerId;
            state()->forever(self::newKey($mapId), $ids);
        }
    }

    private static function rankIn(RecordCollection $records, int $playerId): ?int
    {
        $index = $records->values()->search(fn ($r) => $r->playerId() === $playerId);

        return $index === false ? null : $index + 1;
    }

    public static function clear(): void
    {
        self::clearForMap((int) CurrentMap::get()?->id);
    }

    public static function clearForMap(int $mapId): void
    {
        state()->forget(self::key($mapId));
        state()->forget(self::newKey($mapId));
        state()->forget(self::statsKey($mapId));
    }

    public static function get(int $playerId): Record
    {
        return self::find(self::all(), $playerId);
    }

    /**
     * Returns $records with the player's time if it beats their previous one,
     * or the same instance unchanged.
     */
    private static function merge(RecordCollection $records, Player $player, int $score, ?CheckpointCollection $checkpoints): RecordCollection
    {
        $previous = self::find($records, $player->id);

        if (!$previous->hasNoTime && $score >= $previous->score()) {
            return $records;
        }

        // A new or improved time ranks after every existing record with the same score: the earlier one keeps its place.
        $tiebreaker          = $records->nextTiebreaker();
        $record              = new Record($player->id, $score, $tiebreaker);
        $record->player      = $player;
        $record->checkpoints = $checkpoints;

        return $records
            ->keyBy(fn($r) => $r->playerId())
            ->put($player->id, $record)
            ->sortByRank();
    }

    private static function find(RecordCollection $records, int $playerId): Record
    {
        return $records->first(
            fn($record) => $record->playerId() === $playerId,
            new Record(0, 0, 0, true)
        );
    }

    private static function key(int $mapId): string
    {
        return 'dedimaniaRecords:' . $mapId;
    }

    private static function newKey(int $mapId): string
    {
        return 'dedimaniaNew:' . $mapId;
    }

    private static function statsKey(int $mapId): string
    {
        return 'dedimaniaStats:' . $mapId;
    }
}
