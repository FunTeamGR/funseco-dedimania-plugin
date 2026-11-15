<?php
namespace Funseco\Dedimania\Application;

use Illuminate\Support\Facades\Cache;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Dedimania\Messages;

/**
 * Dedimania did not answer. It is switched off for the map that is running (no records, no record
 * messages, no submission) and everybody is told once for that map. The next map tries again.
 */
final class Outage
{
    private const ANY = 'dedimania:outage:startup';

    public static function report(\Throwable $e, string $what): void
    {
        \Log::error("Dedimania: $what: ".$e->getMessage());

        // No map yet (failed at startup): off until the next map starts.
        $mapId = CurrentMap::get()?->id;
        $minutes = (int) config('dedimania.database.retry_minutes', 30);
        if (Cache::store('state')->add($mapId === null ? self::ANY : self::key($mapId), true, now()->addMinutes($minutes))
            && config('dedimania.database.announce_timeout', true)) {
            Messages::broadcast(Messages::get('down'));
        }
    }

    /** Dedimania is switched off for this map. */
    public static function down(?int $mapId): bool
    {
        $state = Cache::store('state');

        return $state->has(self::ANY) || ($mapId !== null && $state->has(self::key($mapId)));
    }

    /** A new map starts: forget an outage that had no map to belong to. */
    public static function newMap(): void
    {
        Cache::store('state')->forget(self::ANY);
    }

    private static function key(int $mapId): string
    {
        return "dedimania:outage:map:$mapId";
    }
}
