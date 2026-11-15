<?php

namespace Funseco\Dedimania\Presentation;

use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Services\ManiaLink\Facades\ManiaLink;
use Funseco\Core\SharedKernel\UI\Grid\Overflow;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\Dedimania\Application\Eligibility;

/** The small "DEDI CP" button in the top-left corner: one click opens /dedicptms. */
class DediCpButton
{
    /** WidgetRegistry name, ManiaLink action name and config key under manialinks.php. */
    public const NAME = 'dedi_cp_button';

    public const WIDTH = 4.6;
    public const HEIGHT = 6.5;

    public static function register(WidgetRegistry $registry): void
    {
        $registry->widget(self::NAME, 'buttons', order: 50, width: self::WIDTH, height: self::HEIGHT, overflow: Overflow::Wrap);
    }

    /** Everybody, when Dedimania takes records for the map (and the gamemode shows the widget). */
    public static function showAll(?Map $map): void
    {
        $xml = self::xml($map);
        $xml === null ? self::hideAll() : ManiaLink::displayAll($xml);
    }

    public static function showTo(Player $player, ?Map $map): void
    {
        if (($xml = self::xml($map)) !== null) {
            ManiaLink::displayLogin($player, $xml);
        }
    }

    public static function hideAll(): void
    {
        ManiaLink::resetAll(self::NAME);
    }

    private static function xml(?Map $map): ?string
    {
        if (!Eligibility::map($map)) {
            return null;
        }
        $placement = app(WidgetRegistry::class)->placement(self::NAME);
        if ($placement === null) {
            return null;
        }

        $id = ManiaLink::getAction(self::NAME);

        return '<manialink id="'.$id.'">'
            .'<frame posn="'.$placement->x.' '.$placement->y.' 1">'
            .'<quad posn="0 0 0" sizen="'.self::WIDTH.' '.self::HEIGHT.'" style="BgsPlayerCard" substyle="BgCardSystem" action="'.$id.'"/>'
            .'<quad posn="0.7 -0.3 0.002" sizen="3.35 3" style="Icons128x128_1" substyle="Rankings"/>'
            .'<label posn="2.3 -3.4 0.1" sizen="6.65 2" halign="center" textsize="1" scale="0.9" textcolor="FFFF" text="DEDI"/>'
            .'<label posn="2.3 -4.9 0.1" sizen="3.65 2" halign="center" textsize="1" scale="0.6" textcolor="FC0F" text="CP"/>'
            .'</frame>'
            .'</manialink>';
    }
}
