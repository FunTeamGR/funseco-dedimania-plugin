<?php
namespace Funseco\Dedimania\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Funseco\Dedimania\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Dedimania\Application\Eligibility;

class EligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_laps_in_rounds_team_and_cup_make_a_map_ineligible(): void
    {
        $map = new Map(['author_time' => 30000]);

        foreach ([0, 2, 5, 1, 3] as $mode) {
            ServerInfo::setGameMode($mode);
            ServerInfo::setRoundsForcedLaps(0);
            $this->assertTrue(Eligibility::map($map), "mode $mode without forced laps");

            ServerInfo::setRoundsForcedLaps(3);
            $this->assertSame(!in_array($mode, [0, 2, 5], true), Eligibility::map($map), "mode $mode with forced laps");
        }
        ServerInfo::setRoundsForcedLaps(0);
    }

    public function test_the_dedicps_setting_is_read_from_the_database_once_per_login(): void
    {
        $player = \Funseco\Core\Models\Player::factory()->create(['login' => 'cachy']);
        \Funseco\Core\Models\PlayerExtra::create(['player_id' => $player->id, 'dedi' => 3]);
        $this->assertSame(3, \Funseco\Dedimania\Application\DediCheckpoints::setting($player));

        \Illuminate\Support\Facades\DB::table('player_extras')->update(['dedi' => 7]);
        $this->assertSame(3, \Funseco\Dedimania\Application\DediCheckpoints::setting($player), 'served from the cache');

        \Funseco\Dedimania\Application\DediCheckpoints::change($player, 5);
        $this->assertSame(5, \Funseco\Dedimania\Application\DediCheckpoints::setting($player), 'change() keeps it current');
    }
}
