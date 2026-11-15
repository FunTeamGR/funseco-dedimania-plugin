<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\CheckpointReference;
use Funseco\Dedimania\Application\DediCheckpoints;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

/** /dedicps: use a Dedimania record as the reference of the checkpoints panel (from the next map). */
class DediCpsCommand extends DediCommand
{
    protected string $name = 'dedicps';
    protected string $description = 'Sets Dedimania record checkspoints tracking';
    protected string $usage = '[off|record #]';

    public function handle(Player $player, array $args): void
    {
        if (!config('funseco.display_checkpoints', true)) {
            $this->tell($player, Messages::get('cps_disabled'));

            return;
        }
        if (!app(CheckpointReference::class)->trackingEnabled($player)) {
            $this->tell($player, Messages::get('cps_need_local'));

            return;
        }

        $param = strtolower(trim((string) ($args[0] ?? '')));
        if ($param === 'off') {
            DediCheckpoints::change($player, DediCheckpoints::OFF);
            $this->tell($player, Messages::get('cps_off'));
        } elseif ($param === '') {
            DediCheckpoints::change($player, DediCheckpoints::OWN_OR_LAST);
            $this->tell($player, Messages::get('cps_on'));
        } elseif (ctype_digit($param) && (int) $param >= 1 && (int) $param <= DedimaniaRecords::maxRank($player->login)) {
            DediCheckpoints::change($player, (int) $param);
            $this->tell($player, Messages::get('cps_record', (int) $param));
        } else {
            $this->tell($player, Messages::get('cps_no_record', $param));
        }
    }
}
