<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Map;
use Funseco\Core\Services\Cache\ServerInfo;

/** Which maps and finishes Dedimania takes records for. */
final class Eligibility
{
    private const STUNTS = 4;
    /** Rounds, Team, Cup: the modes that can force a number of laps. */
    private const FORCED_LAPS_MODES = [0, 2, 5];
    private const MIN_AUTHOR_TIME = 6000;

    public static function map(?Map $map): bool
    {
        if ($map === null || ServerInfo::getGameMode() === self::STUNTS) {
            return false;
        }

        // forced laps change what a run is (the value is known once the widgets plugin read the game info)
        if (in_array(ServerInfo::getGameMode(), self::FORCED_LAPS_MODES, true) && ServerInfo::getRoundsForcedLaps() > 0) {
            return false;
        }

        // an unknown author time (0) is not a reason to refuse
        return !($map->author_time > 0 && $map->author_time < self::MIN_AUTHOR_TIME);
    }

    /** Only finishes whose checkpoints end on the finish time. */
    public static function checkpointsMatch(iterable $checkpoints, int $score): bool
    {
        $last = null;
        foreach ($checkpoints as $checkpoint) {
            $last = $checkpoint->score;
        }

        return $last === $score;
    }
}
