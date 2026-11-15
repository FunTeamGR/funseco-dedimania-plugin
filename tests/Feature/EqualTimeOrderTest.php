<?php
namespace Funseco\Dedimania\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Funseco\Dedimania\Tests\TestCase;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Dedimania\DedimaniaService;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;

/** On equal scores the earlier record keeps the better place; a later equal time never overtakes it. */
class EqualTimeOrderTest extends TestCase
{
    use RefreshDatabase;
    use \Funseco\Core\Testing\FakesServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        Event::forget(DedimaniaRecordsUpdated::class);
        CurrentMap::set(Map::factory()->create());
    }

    private function logins(): array
    {
        return DedimaniaRecords::all()->map(fn ($r) => $r->player->login)->values()->all();
    }

    private function fetched(array $bests): void
    {
        $struct = [];
        foreach ($bests as $login => $best) {
            Player::firstOrCreate(['login' => $login], ['nickname' => $login]);
            $struct[] = ['Login' => $login, 'NickName' => $login, 'Best' => $best, 'Checks' => [$best]];
        }
        DedimaniaRecords::setForMap(CurrentMap::get()->id, DedimaniaService::fromDedimaniaStruct($struct));
    }

    public function test_improving_to_an_existing_time_ranks_below_the_earlier_one(): void
    {
        $this->fetched(['top' => 29000, 'gus' => 32000, 'nexo' => 30000]);
        $gus = Player::firstOrCreate(['login' => 'gus'], ['nickname' => 'gus']);

        DedimaniaRecords::update($gus, 30000, new CheckpointCollection());

        $this->assertSame(['top', 'nexo', 'gus'], $this->logins());
    }

    public function test_new_equal_time_ranks_below_fifty_equal_times(): void
    {
        $bests = [];
        for ($i = 1; $i <= 50; $i++) {
            $bests["p$i"] = 30000;
        }
        $this->fetched($bests);
        $late = Player::firstOrCreate(['login' => 'late'], ['nickname' => 'late']);

        DedimaniaRecords::update($late, 30000, new CheckpointCollection());

        $logins = $this->logins();
        $this->assertSame('late', end($logins));
        $this->assertCount(51, $logins);
    }

    public function test_two_improvements_to_the_same_time_keep_their_order(): void
    {
        $this->fetched(['a' => 33000, 'b' => 34000]);
        $a = Player::firstOrCreate(['login' => 'a'], ['nickname' => 'a']);
        $b = Player::firstOrCreate(['login' => 'b'], ['nickname' => 'b']);

        DedimaniaRecords::update($b, 30000, new CheckpointCollection());
        DedimaniaRecords::update($a, 30000, new CheckpointCollection());

        $this->assertSame(['b', 'a'], $this->logins());
    }

    public function test_pending_finish_merged_after_fetch_ranks_below_an_equal_fetched_time(): void
    {
        $late = Player::firstOrCreate(['login' => 'late'], ['nickname' => 'late']);
        DedimaniaRecords::update($late, 30000, new CheckpointCollection()); // finished while the fetch was in flight

        $this->fetched(['first' => 30000]);

        $this->assertSame(['first', 'late'], $this->logins());
    }
}
