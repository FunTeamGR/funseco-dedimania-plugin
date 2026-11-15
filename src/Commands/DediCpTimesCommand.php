<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\UI\Window;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

/** /dedicptms: absolute checkpoint times of every Dedimania record. */
class DediCpTimesCommand extends DediCommand
{
    protected string $name = 'dedicptms';
    protected string $description = "Displays all Dedimania records' checkpoint times";
    protected string $headerKey = 'cptms_header';
    protected bool $sectors = false;

    private const MAX_COLUMNS = 12;

    public function handle(Player $player, array $args): void
    {
        $records = $this->recordsOrNone($player);
        if (!$records) {
            return;
        }

        $new = DedimaniaRecords::newIds();
        $count = $records->first()->checkpoints?->count() ?? 0;
        $shown = min($count, self::MAX_COLUMNS);
        $truncated = $count > self::MAX_COLUMNS;

        $rows = [];
        foreach ($records as $index => $record) {
            $row = [
                sprintf('%02d.', $index + 1),
                (in_array($record->playerId(), $new, true) ? '{#black}' : '').format_score($record->score()),
            ];
            $times = array_values($record->checkpoints?->toDedimaniaStruct() ?? []);
            for ($i = 0; $i < $shown; $i++) {
                $row[] = isset($times[$i])
                    ? '$n'.format_score($this->sectors && $i > 0 ? $times[$i] - $times[$i - 1] : $times[$i])
                    : '';
            }
            if ($truncated) {
                $row[] = '+';
            }
            $rows[] = $row;
        }

        $total = max(1.0, 0.28 + $shown * 0.1 + ($truncated ? 0.06 : 0));
        $widths = [$total, 0.1, 0.18, ...array_fill(0, $shown, 0.1), ...($truncated ? [0.06] : [])];
        $header = Messages::fill(config('dedimania.windows.'.$this->headerKey), [DedimaniaRecords::maxRank($player->login), $count]);

        Window::show($player, $header, $rows, $widths, ['BgRaceScore2', 'Podium']);
    }
}
