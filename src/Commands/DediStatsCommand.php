<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class DediStatsCommand extends DediCommand
{
    protected string $name = 'dedistats';
    protected string $description = 'Displays Dedimania track statistics';

    public function handle(Player $player, array $args): void
    {
        $map = CurrentMap::get();
        if (!$map) {
            return;
        }
        $stats = DedimaniaRecords::stats();
        $races = (int) ($stats['total_races'] ?? 0);
        $players = (int) ($stats['total_players'] ?? 0);
        $uid = $stats['uid'] ?? $map->uid;
        $black = fn ($value) => '{#black}'.$value;

        $rows = [
            ['Server MaxRank', $black(DedimaniaRecords::serverMaxRank())],
            ['Your MaxRank', $black(DedimaniaRecords::playerMaxRank($player->login))],
            [],
            ['UID', $black($uid)],
            ['Total Races', $black($races)],
            ['Total Players', $black($players)],
            ['Avg. Players', $black($races > 0 ? round($players / $races, 2) : 0)],
        ];
        if ($url = Messages::recordsUrl($uid)) {
            $rows[] = [];
            $rows[] = [str_repeat(' ', 15).'{#black}'.Messages::link($url, config('dedimania.windows.stats_link'))];
        }

        Window::show(
            $player,
            Messages::fill(config('dedimania.windows.stats_header'), [Messages::plain($map->name)]),
            $rows,
            [1.0, 0.3, 0.7],
            ['Icons64x64_1', 'Maximize', -0.01],
            card: true,
        );
    }
}
