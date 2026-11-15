<?php
namespace Funseco\Dedimania\Application\Listeners;

use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Funseco\Facades\Funseco;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Dedimania\Application\Outage;
use Funseco\Dedimania\Facades\Dedimania;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use Funseco\Dedimania\Messages;
use Funseco\Dedimania\Presentation\DediRecordsWidget;

class PlayerConnectListener
{
    public function handle(PlayerConnected $event)
    {
        $player = $event->player;

        Dedimania::playerArrive($player, $event->playerInfo)
            ->then(function (array $arrival) use ($player, $event) {
                DedimaniaRecords::setPlayerMaxRank($player->login, $arrival['max_rank']);
                DedimaniaRecords::refreshNickname($player->login, $event->playerInfo->nickname ?: $player->nickname);

                if ($arrival['banned']) {
                    $this->ban($player->login, $event->playerInfo->nickname ?: $player->nickname);

                    return;
                }
                if (config('dedimania.database.show_welcome', true)) {
                    $site = config('dedimania.links.site');
                    Messages::tell($player->login, Messages::get('welcome', $site ? ' at '.Messages::link($site, $site) : ''));
                }
            })
            ->catch(function (\Throwable $e) {
                Outage::report($e, 'Failed to announce player');
            });

        if (Funseco::getGameStatus() === 4 && CurrentMap::get()) {
            DediRecordsWidget::render($player);
        }
    }

    /** Banned by Dedimania: say so, remember it (finishes are ignored) and ban from the server too. */
    private function ban(string $login, ?string $nickname): void
    {
        DedimaniaRecords::markBanned($login);
        Messages::broadcast(Messages::get('banned_login', Messages::plain($nickname), $login));
        \Server::query()->Ban($login);
    }
}
