<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Player;
use Funseco\Core\Models\PlayerExtra;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\SharedKernel\CheckpointReference;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;

/**
 * /dedicps: which Dedimania run the checkpoints panel compares with.
 * Setting -1 = off, 0 = the player's own record or the last one, N = record N.
 */
final class DediCheckpoints
{
    public const OFF = -1;
    public const OWN_OR_LAST = 0;

    public static function setting(Player $player): int
    {
        // read on every finish and record update: remembered per login (change() keeps it current)
        $key = 'dedi:cps:'.$player->login;
        if (($cached = state()->get($key)) !== null) {
            return (int) $cached;
        }

        $stored = PlayerExtra::where('player_id', $player->id)->value('dedi');
        $setting = $stored === null
            ? (config('funseco.auto_enable_dedicps', false) ? self::OWN_OR_LAST : self::OFF)
            : (int) $stored;
        state()->forever($key, $setting);

        return $setting;
    }

    public static function change(Player $player, int $setting): void
    {
        PlayerExtra::updateOrCreate(['player_id' => $player->id], ['dedi' => $setting]);
        state()->forever('dedi:cps:'.$player->login, $setting);
    }

    /** The checkpoints' reset: any /cps form turns Dedimania tracking off. */
    public static function reset(Player $player): void
    {
        self::change($player, self::OFF);
    }

    /** Dedimania records arrived: swap in the Dedimania run for everyone who asked for it. */
    public static function applyToAll(): void
    {
        foreach (CurrentPlayers::all() as $player) {
            self::apply($player);
        }
    }

    public static function apply(Player $player): void
    {
        $setting = self::setting($player);
        $records = DedimaniaRecords::all()->values();
        $reference = app(CheckpointReference::class);
        if ($setting < self::OWN_OR_LAST || $records->isEmpty() || !$reference->trackingEnabled($player)) {
            return;
        }

        if ($setting === self::OWN_OR_LAST) {
            $rank = DedimaniaRecords::rankOf($player->id, $records);
            $record = $rank ? $records[$rank - 1] : $records->last();
        } else {
            $record = $records[min($setting, $records->count()) - 1];
        }

        $checkpoints = $record->checkpoints?->toDedimaniaStruct() ?? [];
        if ($checkpoints !== []) {
            $reference->useReference($player, array_values($checkpoints));
        }
    }
}
