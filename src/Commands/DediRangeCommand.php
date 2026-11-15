<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;

class DediRangeCommand extends DediCommand
{
    protected string $name = 'dedirange';
    protected string $description = 'Shows difference first to last Dedimania record';

    public function handle(Player $player, array $args): void
    {
        if ($records = $this->recordsOrNone($player)) {
            $first = $records->first();
            $last = $records->last();
            $this->tell($player, $this->diffMessage(
                $this->fragment(1, $first), $this->fragment($records->count(), $last), $last->score() - $first->score()
            ));
        }
    }
}
