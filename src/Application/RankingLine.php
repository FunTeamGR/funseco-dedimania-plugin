<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Map;
use Funseco\Core\SharedKernel\Values\RecordCollection;
use Funseco\Dedimania\Messages;

/** The "Dedimania Record rankings" chat message: a header, then a line of record fragments. */
final class RankingLine
{
    public const ONLY_NEW = 'only_new';   // /dedinew
    public const DURING = 'during';       // /dedilive
    public const BEFORE = 'before';       // map start
    public const AFTER = 'after';         // map end

    /**
     * @param int[]    $newIds  player ids whose record is new this map
     * @param string[] $online  logins of the players on the server
     */
    public static function build(string $mode, RecordCollection $records, array $newIds, array $online, Map $map, ?string $uid = null, bool $private = false): string
    {
        $head = fn (string $key, ...$values) => $private ? Messages::whisper($key, ...$values) : Messages::get($key, ...$values);
        $link   = Messages::link(Messages::recordsUrl($uid ?? $map->uid), Messages::plain($map->name));
        $timing = (string) config('dedimania.timing.'.($mode === self::ONLY_NEW ? self::DURING : $mode), $mode);

        $all = $records->values();
        if ($all->isEmpty()) {
            return $head('ranking_none', $link, $timing);
        }

        $minRecs = (int) config('dedimania.database.show_min_recs', 8);
        $count   = $all->count();
        $entries = [];
        $new     = 0;

        foreach ($all as $index => $record) {
            $rank     = $index + 1;
            $isNew    = in_array($record->playerId(), $newIds, true);
            $isOnline = in_array($record->player?->login, $online, true);
            $new += $isNew ? 1 : 0;

            $include = match ($mode) {
                self::ONLY_NEW => $isNew,
                self::DURING   => $isNew || $isOnline || $rank < $minRecs - 2 || $rank === $count,
                self::BEFORE   => $isNew || $isOnline || $rank < $minRecs || $rank === $count,
                self::AFTER    => $isNew || $rank < $minRecs || $rank === $count,
            };
            if (!$include) {
                continue;
            }

            $key       = $isNew ? 'ranking_record_new_on' : ($isOnline ? 'ranking_record_on' : 'ranking_record');
            $entries[] = Messages::get($key, $rank, Messages::plain($record->player?->nickname), format_score($record->score()));
        }

        if ($new > 0) {
            $header = $head('ranking_new', $link, $timing, $new);
        } elseif ($entries === []) {
            $header = $head('ranking_nonew', $link, $timing);
        } elseif (config('dedimania.database.show_recs_range', true)) {
            $range  = Messages::diff($all->last()->score() - $all->first()->score());
            $header = $head('ranking_range', $link, $timing, $range);
        } else {
            $header = $head('ranking', $link, $timing);
        }

        return $entries === [] ? $header : $header."\n".'$n'.implode(', ', $entries);
    }
}
