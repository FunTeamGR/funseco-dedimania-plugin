<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\Commands\Command;
use Funseco\Core\SharedKernel\Commands\DeclaresCommand;
use Funseco\Core\SharedKernel\Commands\Described;
use Funseco\Core\SharedKernel\Values\Record;
use Funseco\Core\SharedKernel\Values\RecordCollection;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

/** The /dedi* commands: open to everyone, read the current map's Dedimania records. */
abstract class DediCommand implements Command, Described
{
    use DeclaresCommand;

    protected function records(): RecordCollection
    {
        return DedimaniaRecords::all()->values();
    }

    /** Records in rank order, or null after telling the player there are none. */
    protected function recordsOrNone(Player $player): ?RecordCollection
    {
        $records = $this->records();
        if ($records->isEmpty()) {
            Messages::tell($player->login, Messages::get('no_records'));

            return null;
        }

        return $records;
    }

    protected function tell(Player $player, string $message): void
    {
        Messages::tell($player->login, $message);
    }

    /** R(rank, nick, time) of a record. */
    protected function fragment(int $rank, Record $record): string
    {
        return Messages::fragment($rank, $record->player?->nickname, $record->score());
    }

    protected function diffMessage(string $a, string $b, int $ms): string
    {
        return Messages::get('diff_record', $a, $b, Messages::diff($ms));
    }
}
