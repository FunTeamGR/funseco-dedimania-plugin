<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class DediRecsCommand extends DediCommand
{
    protected string $name = 'dedirecs';
    protected string $description = 'Displays all Dedimania records on current track';
    protected string $usage = '[help|pb|new|live|first|last|next|diff|range]';

    private const OPTIONS = [
        'pb' => 'dedipb', 'new' => 'dedinew', 'live' => 'dedilive', 'first' => 'dedifirst',
        'last' => 'dedilast', 'next' => 'dedinext', 'diff' => 'dedidiff', 'range' => 'dedirange',
    ];

    public function handle(Player $player, array $args): void
    {
        $option = strtolower((string) ($args[0] ?? ''));

        if ($option === 'help') {
            $this->help($player);
        } elseif (isset(self::OPTIONS[$option])) {
            \Chat::run($player, self::OPTIONS[$option]);
        } else {
            $this->list($player);
        }
    }

    private function help(Player $player): void
    {
        $lines = array_map(fn ($row) => ['...', '{#black}'.$row[0], $row[1]], config('dedimania.windows.recs_help'));
        $lines[] = [];
        $lines[] = [config('dedimania.windows.recs_help_footer')];

        Window::show($player, config('dedimania.windows.recs_help_header'), $lines, [1.2, 0.05, 0.3, 0.85], ['Icons64x64_1', 'TrackInfo', -0.01]);
    }

    private function list(Player $player): void
    {
        $records = $this->recordsOrNone($player);
        if (!$records) {
            return;
        }

        \Funseco\Dedimania\Application\RecordsWindow::open($player, $records, DedimaniaRecords::newIds());
    }
}
