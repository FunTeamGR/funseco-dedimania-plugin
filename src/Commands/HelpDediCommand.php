<?php
namespace Funseco\Dedimania\Commands;

use Funseco\Core\Models\Player;
use Funseco\Core\SharedKernel\UI\Window;

class HelpDediCommand extends DediCommand
{
    protected string $name = 'helpdedi';
    protected array $aliases = ['dedihelp'];
    protected string $description = 'Displays info about the Dedimania records system';

    public function handle(Player $player, array $args): void
    {
        $lines = [];
        foreach ((array) config('dedimania.windows.help', []) as $line) {
            // "{site}" / "{stats}" lines only exist while that link is configured
            foreach (['site', 'stats'] as $link) {
                if (str_starts_with($line, '{'.$link.'}')) {
                    $url = config("dedimania.links.$link");
                    $line = $url ? str_replace(['{'.$link.'}', '{url}'], ['', $url], $line) : null;
                    break;
                }
            }
            if ($line !== null) {
                $lines[] = $line === '' ? [] : [$line];
            }
        }

        Window::show($player, config('dedimania.windows.help_header'), $lines, [0.95], ['Icons64x64_1', 'TrackInfo', -0.01]);
    }
}
