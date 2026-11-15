<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\SharedKernel\CheckpointReference;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Dedimania\Application\DediCheckpoints;
use Funseco\Dedimania\Application\Eligibility;
use Funseco\Dedimania\Application\Outage;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;

class PlayerFinishedListener
{
    public function handle(PlayerFinished $event)
    {
        $player = $event->player;
        if (Outage::down($event->map->id) || !Eligibility::map($event->map) || !Eligibility::checkpointsMatch($event->checkpoints, $event->score)) {
            return;
        }
        if (DedimaniaRecords::isBanned($player->login)) {
            Messages::tell($player->login, Messages::get('banned_finish'));

            return;
        }

        $outcome = DedimaniaRecords::update($player, $event->score, $event->checkpoints);
        if ($outcome === null) {
            return;
        }

        // Setting 0 without a Dedimania record yet: the first record is the reference at once.
        if ($outcome['kind'] === 'first' && DediCheckpoints::setting($player) === DediCheckpoints::OWN_OR_LAST) {
            $reference = app(CheckpointReference::class);
            if ($reference->trackingEnabled($player)) {
                $reference->useReference($player, $event->checkpoints->toDedimaniaStruct());
            }
        }

        $this->announce($event, $outcome);
    }

    private function announce(PlayerFinished $event, array $outcome): void
    {
        $rank = $outcome['rank'];
        if (!config('dedimania.database.display_recs', true) || $rank > DedimaniaRecords::maxRank($event->player->login)) {
            return;
        }

        $nick  = Messages::plain($event->player->nickname);
        $label = config('dedimania.messages.time_label', 'Time');
        $time  = format_score($event->score);
        $key   = $rank <= (int) config('dedimania.database.limit_recs', 30) ? 'get' : 'whisper';

        $text = match ($outcome['kind']) {
            'first'  => Messages::$key('record_first', $nick, $rank, $label, $time),
            'equal'  => Messages::$key('record_equal', $nick, $rank, $label, $time),
            default  => Messages::$key(
                $rank < $outcome['oldRank'] ? 'record_new_rank' : 'record_new',
                $nick, $rank, $label, $time, $outcome['oldRank'], Messages::diff($event->score - $outcome['oldScore'])
            ),
        };

        $key === 'get' ? Messages::broadcast($text) : Messages::tell($event->player->login, $text);
    }
}
