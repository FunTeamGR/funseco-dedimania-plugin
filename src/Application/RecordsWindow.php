<?php
namespace Funseco\Dedimania\Application;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Core\SharedKernel\Values\RecordCollection;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

/** The paged "Current TOP n Dedimania Records" window (/dedirecs, and map start/end when show_recs_* is 2). */
final class RecordsWindow
{
    /** show_recs_before / show_recs_after: 0 off, 1 chat, 2 (or the +4 flag) the window. */
    public static function wantsWindow(int $setting): bool
    {
        return $setting === 2 || $setting >= 4;
    }

    public static function open(Player $player, RecordCollection $records, array $new): void
    {
        $logins = config('dedimania.database.show_rec_logins', true);
        $color = config('funseco.lists_colornicks', true);
        $rows = [];
        foreach ($records as $index => $record) {
            $nick = (string) $record->player?->nickname;
            $nick = $color ? str_replace(["\r", "\n"], '', $nick) : Messages::plain($nick);
            $time = (in_array($record->playerId(), $new, true) ? '{#black}' : '').format_score($record->score());
            $row = [sprintf('%02d.', $index + 1), '{#black}'.$nick];
            if ($logins) {
                $row[] = '{#login}'.$record->player?->login;
            }
            $rows[] = [...$row, $time];
        }

        $widths = $logins ? [1.4, 0.1, 0.65, 0.4, 0.25] : [1.0, 0.1, 0.65, 0.25];
        $header = Messages::fill(config('dedimania.windows.recs_header'), [DedimaniaRecords::maxRank($player->login)]);
        Window::show($player, $header, $rows, $widths, ['BgRaceScore2', 'Podium']);
    }

    /** The window for every connected player (map start / end). */
    public static function openForAll(RecordCollection $records, array $new): void
    {
        if ($records->isEmpty()) {
            return;
        }
        foreach (CurrentPlayers::all() as $player) {
            self::open($player, $records, $new);
        }
    }
}
