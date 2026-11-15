<?php
namespace Funseco\Dedimania\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use React\Http\Message\Response;
use React\Promise\Deferred;
use Funseco\Dedimania\Tests\TestCase;
use Funseco\Dedimania\Tests\Support\XmlRpcEncoder;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Dedimania\Application\FetchRecords;
use Funseco\Dedimania\DedimaniaException;
use Funseco\Dedimania\DedimaniaService;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use function React\Promise\resolve;

class DedimaniaAsyncTest extends TestCase
{
    use RefreshDatabase;
    use \Funseco\Core\Testing\FakesServer;

    private $http;
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();

        ServerInfo::set(['server_name' => 'srv', 'server_comment' => '', 'hidden' => false, 'max_players' => 32, 'max_spectators' => 32]);
        ServerInfo::setGameMode(1);

        $this->http = Mockery::mock(AsyncHttpClient::class);
        $this->app->instance(AsyncHttpClient::class, $this->http);
        Event::forget(DedimaniaRecordsUpdated::class); // widget rendering needs a socket
    }

    /** Next POST answers with a decoded multicall result of [Authenticate, call]. */
    private function respond(array $callResult, array $headers = []): void
    {
        $this->http->shouldReceive('post')->once()->andReturnUsing(function ($url, $body, $hdrs) use ($callResult, $headers) {
            $this->posts[] = ['body' => $body, 'headers' => $hdrs];
            return resolve(new Response(200, $headers, XmlRpcEncoder::response([[true], [$callResult]])));
        });
    }

    private function deferPost(): Deferred
    {
        $deferred = new Deferred();
        $this->http->shouldReceive('post')->once()->andReturn($deferred->promise());
        return $deferred;
    }

    private static function encoded(array $records): string
    {
        return XmlRpcEncoder::response([[true], [['Records' => $records]]]);
    }

    private static function dediRecord(string $login, int $best): array
    {
        return ['Login' => $login, 'NickName' => $login, 'Best' => $best, 'Checks' => [$best - 1000, $best]];
    }

    public function test_current_challenge_sends_map_and_parses_records()
    {
        $map = Map::factory()->make(['id' => 1, 'uid' => 'UID123']);
        $this->respond(['Records' => [self::dediRecord('alice', 30000), self::dediRecord('bob', 31000)]]);

        $records = null;
        app(DedimaniaService::class)->currentChallenge($map)->then(function ($r) use (&$records) { $records = $r; });

        $this->assertStringContainsString('dedimania.Authenticate', $this->posts[0]['body']);
        $this->assertStringContainsString('dedimania.CurrentChallenge', $this->posts[0]['body']);
        $this->assertStringContainsString('UID123', $this->posts[0]['body']);
        $this->assertSame([30000, 31000], $records->map->score()->all());
    }

    public function test_nation_and_packmask_come_from_config()
    {
        config(['dedimania.database.nation' => 'FRA', 'dedimania.database.packmask' => 'Island']);
        $this->respond(['Login' => 'x']);

        app(DedimaniaService::class)->playerArrive(Player::factory()->make(['login' => 'x']), new \Funseco\Core\Servers\TmForever\Values\PlayerInfo([]));

        $this->assertStringContainsString('<string>FRA</string>', $this->posts[0]['body']);
        $this->assertStringContainsString('<string>Island</string>', $this->posts[0]['body']);
    }

    public function test_session_cookie_is_replayed()
    {
        $this->respond(['Login' => 'x'], ['Set-Cookie' => 'PHPSESSID=abc123; path=/']);
        $this->respond(['Login' => 'x']);
        $service = app(DedimaniaService::class);
        $player  = Player::factory()->make(['login' => 'x']);
        $info    = new \Funseco\Core\Servers\TmForever\Values\PlayerInfo([]);

        $service->playerArrive($player, $info);
        $service->playerArrive($player, $info);

        $this->assertArrayNotHasKey('Cookie', $this->posts[0]['headers']);
        $this->assertSame('PHPSESSID=abc123', $this->posts[1]['headers']['Cookie']);
    }

    public function test_failed_authentication_rejects()
    {
        $this->http->shouldReceive('post')->andReturn(resolve(new Response(200, [], XmlRpcEncoder::response([[false], [['Status' => false]]]))));

        $error = null;
        app(DedimaniaService::class)->authenticate()->catch(function ($e) use (&$error) { $error = $e; });

        $this->assertInstanceOf(DedimaniaException::class, $error);
    }

    public function test_garbage_response_rejects()
    {
        $this->http->shouldReceive('post')->andReturn(resolve(new Response(200, [], '<html>502</html>')));

        $error = null;
        app(DedimaniaService::class)->authenticate()->catch(function ($e) use (&$error) { $error = $e; });

        $this->assertInstanceOf(DedimaniaException::class, $error);
    }

    public function test_finish_during_fetch_is_kept()
    {
        $map = Map::factory()->make(['id' => 1]);
        CurrentMap::set($map);
        $alice = Player::factory()->create(['login' => 'alice']);
        $fetch = $this->deferPost();

        FetchRecords::for($map);
        // Alice finishes before Dedimania answers, beating her stored 30000.
        DedimaniaRecords::update($alice, 29000, new CheckpointCollection());
        $fetch->resolve(new Response(200, [], self::encoded([self::dediRecord('alice', 30000), self::dediRecord('bob', 31000)])));

        $this->assertSame([29000, 31000], DedimaniaRecords::allForMap(1)->map->score()->values()->all());
    }

    public function test_slower_finish_during_fetch_does_not_replace_better_dedi_time()
    {
        $map = Map::factory()->make(['id' => 1]);
        CurrentMap::set($map);
        $alice = Player::factory()->create(['login' => 'alice']);
        $fetch = $this->deferPost();

        FetchRecords::for($map);
        DedimaniaRecords::update($alice, 35000, new CheckpointCollection());
        $fetch->resolve(new Response(200, [], self::encoded([self::dediRecord('alice', 30000)])));

        $this->assertSame([30000], DedimaniaRecords::allForMap(1)->map->score()->values()->all());
    }

    public function test_records_for_skipped_map_land_on_that_map()
    {
        $mapA = Map::factory()->make(['id' => 1]);
        $mapB = Map::factory()->make(['id' => 2]);
        CurrentMap::set($mapA);
        $fetch = $this->deferPost();

        FetchRecords::for($mapA);
        CurrentMap::set($mapB); // admin skipped before Dedimania answered
        $fetch->resolve(new Response(200, [], self::encoded([self::dediRecord('alice', 30000)])));

        $this->assertCount(1, DedimaniaRecords::allForMap(1));
        $this->assertCount(0, DedimaniaRecords::all());
    }

    public function test_map_end_keeps_records_for_the_score_screen()
    {
        $map = Map::factory()->make(['id' => 1]);
        CurrentMap::set($map);
        DedimaniaRecords::update(Player::factory()->create(['login' => 'alice']), 30000, new CheckpointCollection());
        $submit = $this->deferPost();

        app(\Funseco\Dedimania\Application\Listeners\MapEndedListener::class)
            ->handle(new MapEnded($map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));

        $this->assertCount(1, DedimaniaRecords::allForMap(1)); // the podium lists them; the next map start clears
        $submit->reject(new \RuntimeException('timeout')); // logged, not thrown
        $this->assertStringContainsString('Dedimania records are disabled for this track', $this->messages('ChatSendServerMessage')[0]);
    }
}
