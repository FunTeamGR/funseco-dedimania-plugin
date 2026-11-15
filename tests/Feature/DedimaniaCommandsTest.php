<?php
namespace Funseco\Dedimania\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Mockery;
use React\Promise\Deferred;
use Funseco\Core\Testing\FakesServer;
use Funseco\Dedimania\Tests\TestCase;
use Funseco\Checkpoints\CheckpointTracker;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Models\PlayerExtra;
use Funseco\Core\Servers\TmForever\Values\Checkpoint;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Servers\TmForever\Values\PlayerInfo;
use Funseco\Core\Services\Cache\CurrentMap;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Chat\ChatService;
use Funseco\Core\SharedKernel\CheckpointReference;
use Funseco\Core\SharedKernel\Events\CheckpointsSettingChanged;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Core\SharedKernel\FinishHistory;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\PlayerRankingsCollection;
use Funseco\Core\SharedKernel\Values\RecordCollection;
use Funseco\Dedimania\Application\FetchRecords;
use Funseco\Dedimania\Application\Listeners\MapEndedListener;
use Funseco\Dedimania\Application\Listeners\PlayerConnectListener;
use Funseco\Dedimania\Application\Listeners\PlayerFinishedListener;
use Funseco\Dedimania\DedimaniaService;
use Funseco\Dedimania\Infrastructure\DedimaniaRecords;
use function React\Promise\reject;
use function React\Promise\resolve;

class DedimaniaCommandsTest extends TestCase
{
    use FakesServer;
    use RefreshDatabase;

    private Player $alice;
    private Player $bob;
    private Player $carol;
    private Map $map;
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeServer();
        Event::forget(DedimaniaRecordsUpdated::class);   // widget rendering needs a socket
        Cache::store('state')->flush();
        CurrentPlayers::clear();
        ServerInfo::setGameMode(1);

        $this->alice = Player::factory()->create(['login' => 'alice', 'nickname' => '$f00Alice']);
        $this->bob = Player::factory()->create(['login' => 'bob', 'nickname' => "Bo\nb"]);
        $this->carol = Player::factory()->create(['login' => 'carol', 'nickname' => 'Carol']);
        CurrentPlayers::add($this->alice);
        CurrentPlayers::add($this->carol);
        $this->map = Map::factory()->create(['uid' => 'UID1', 'name' => '$oMy Map', 'author_time' => 30000]);
        CurrentMap::set($this->map);

        $this->service = Mockery::mock(DedimaniaService::class);
        $this->app->instance(DedimaniaService::class, $this->service);
        \Funseco\Dedimania\Facades\Dedimania::clearResolvedInstance(DedimaniaService::class);
    }

    private function run_(Player $player, string $command, array $args = []): void
    {
        $this->calls = [];
        app(ChatService::class)->run($player, $command, $args);
    }

    /** @param array<string, array{0: int, 1?: int[]}> $rows login => [best, checkpoints] in rank order */
    private function seedRecords(array $rows): void
    {
        $struct = [];
        foreach ($rows as $login => [$best, $checks]) {
            $struct[] = ['Login' => $login, 'NickName' => $login, 'Best' => $best, 'Checks' => $checks ?? [$best - 1000, $best]];
        }
        DedimaniaRecords::setForMap($this->map->id, DedimaniaService::fromDedimaniaStruct($struct));
        DedimaniaRecords::setStats($this->map->id, ['uid' => 'UID1', 'total_races' => 40, 'total_players' => 10, 'server_max_rank' => 30]);
    }

    private function cps(int ...$scores): CheckpointCollection
    {
        return new CheckpointCollection(array_map(fn ($s, $i) => new Checkpoint($s, $i), $scores, array_keys($scores)));
    }

    /** Message text without $ colour/style codes. */
    private function plain(string $text): string
    {
        return preg_replace('/\$(?:[0-9a-f]{3}|[a-z])/i', '', $text);
    }

    private function lastRaw(): string
    {
        $all = $this->messages();

        return (string) end($all);
    }

    private function lastMessage(): string
    {
        $all = $this->messages();

        return $this->plain((string) end($all));
    }

    // ---- /helpdedi, /dedirecs ----

    public function test_helpdedi_window_has_no_dead_links_by_default()
    {
        $this->run_($this->alice, 'helpdedi');
        $this->assertStringContainsString('Dedimania information:', $this->window());
        $this->assertStringContainsString('/dedirecs', $this->window());
        $this->assertStringNotContainsString('dedimania.com', $this->window());

        $this->run_($this->alice, 'dedihelp');
        $this->assertStringContainsString('Dedimania information:', $this->window());
    }

    public function test_helpdedi_shows_configured_links()
    {
        config(['dedimania.links.site' => 'http://dedi.example/site']);
        $this->run_($this->alice, 'helpdedi');
        $this->assertStringContainsString('http://dedi.example/site', $this->window());
    }

    public function test_dedirecs_lists_records_with_logins_and_new_flag()
    {
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31000, null]]);
        DedimaniaRecords::update($this->alice, 30500, $this->cps(29500, 30500));

        $this->run_($this->alice, 'dedirecs');
        $xml = $this->window();
        $this->assertStringContainsString('Current TOP 30 Dedimania Records:', $xml);
        $this->assertStringContainsString('01.', $xml);
        $this->assertStringContainsString('02.', $xml);
        $this->assertStringContainsString('0:30.50', $xml);
        $this->assertStringContainsString('alice', $xml);        // login column
        $this->assertStringContainsString('Podium', $xml);
    }

    public function test_dedirecs_without_records_says_so()
    {
        $this->run_($this->alice, 'dedirecs');
        $this->assertStringContainsString('No Dedimania records found!', $this->lastMessage());
    }

    public function test_dedirecs_help_window_and_option_dispatch()
    {
        $this->seedRecords(['alice' => [30000, null]]);
        $this->run_($this->alice, 'dedirecs', ['help']);
        $this->assertStringContainsString('shows Dedimania records and relations', $this->window());
        $this->assertStringContainsString('Without an option', $this->window());

        $this->run_($this->alice, 'dedirecs', ['PB']);
        $this->assertStringContainsString('Dedimania Personal Best', $this->lastMessage());
    }

    // ---- single-line commands ----

    public function test_pb_first_last_range_diff()
    {
        $this->seedRecords(['bob' => [30000, null], 'alice' => [30500, null], 'carol' => [31250, null]]);

        $this->run_($this->alice, 'dedipb');
        $this->assertSame('> Dedimania Personal Best: 0:30.50(2)', $this->lastMessage());

        $this->run_($this->alice, 'dedifirst');
        $this->assertStringContainsString('The first Dedimania record is: 1.Bob[0:30.00]', $this->lastMessage());

        $this->run_($this->alice, 'dedilast');
        $this->assertStringContainsString('The last Dedimania record is: 3.Carol[', $this->lastMessage());

        $this->run_($this->alice, 'dedirange');
        $this->assertStringContainsString('1.Bob[', $this->lastMessage());
        $this->assertStringContainsString('3.Carol[', $this->lastMessage());
        $this->assertStringContainsString('is: 1.25', $this->lastMessage());

        $this->run_($this->alice, 'dedidiff');
        $this->assertStringContainsString('2.Alice[', $this->lastMessage());
        $this->assertStringContainsString('is: 0.50', $this->lastMessage());
    }

    public function test_nicknames_are_plain_in_fragments()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        DedimaniaRecords::refreshNickname('bob', "\$f00Bo\nb");
        $this->run_($this->alice, 'dedifirst');
        $this->assertStringContainsString('1.Bob[', $this->lastMessage());
    }

    public function test_pb_and_diff_without_record()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        $this->run_($this->alice, 'dedipb');
        $this->assertStringContainsString("You don't have a Dedimania record on this track yet...", $this->lastMessage());
        $this->run_($this->alice, 'dedidiff');
        $this->assertStringContainsString('yet... use /dedilast', $this->lastMessage());
    }

    public function test_next_with_record_and_rank_one()
    {
        $this->seedRecords(['bob' => [30000, null], 'alice' => [30500, null]]);
        $this->run_($this->alice, 'dedinext');
        $this->assertStringContainsString('2.Alice[', $this->lastMessage());
        $this->assertStringContainsString('1.Bob[', $this->lastMessage());
        $this->assertStringContainsString('is: 0.50', $this->lastMessage());

        $this->run_($this->bob, 'dedinext');
        $this->assertStringContainsString('is: 0.00', $this->lastMessage());
    }

    public function test_next_without_record_uses_local_best_from_finish_history()
    {
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31000, null]]);
        $mapId = $this->map->id;
        $this->app->instance(FinishHistory::class, new class($mapId) implements FinishHistory {
            public function __construct(private int $mapId) {}
            public function finishedMapIds(Player $player): array { return [$this->mapId]; }
            public function bestScores(Player $player): array { return [$this->mapId => 30500]; }
            public function finishCount(Player $player, Map $map): int { return 1; }
            public function averageScore(Player $player, Map $map, int $last): ?int { return null; }
        });

        $this->run_($this->alice, 'dedinext');
        $this->assertStringContainsString('PB.Alice[', $this->lastMessage());
        $this->assertStringContainsString('2.Carol[', $this->lastMessage());
        $this->assertStringContainsString('is: -0.50', $this->lastMessage());
    }

    public function test_next_without_record_or_history_uses_verbatim_message()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        $this->run_($this->alice, 'dedinext');
        $this->assertStringContainsString("You don't have Dedimania a record on this track yet... use /dedilast", $this->lastMessage());
    }

    public function test_empty_records_reply_for_first_last_range()
    {
        foreach (['dedifirst', 'dedilast', 'dedirange'] as $command) {
            $this->run_($this->alice, $command);
            $this->assertStringContainsString('No Dedimania records found!', $this->lastMessage());
        }
    }

    // ---- ranking line ----

    public function test_dedinew_and_dedilive()
    {
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31000, null]]);

        $this->run_($this->alice, 'dedinew');
        $this->assertStringContainsString('none new so far', $this->lastMessage());

        DedimaniaRecords::update($this->alice, 30500, $this->cps(29500, 30500));
        $this->run_($this->alice, 'dedinew');
        $text = $this->lastMessage();
        $this->assertStringContainsString('1 new):', $text);
        $this->assertStringContainsString('during', $text);
        $this->assertStringContainsString("\n2.Alice[", $text);
        $this->assertStringNotContainsString('Bob', $text);

        $this->run_($this->alice, 'dedilive');
        $text = $this->lastMessage();
        $this->assertStringContainsString('1.Bob[', $text);
        $this->assertStringContainsString('3.Carol[', $text);
        $this->assertStringStartsWith('> Dedimania', $text);   // private: one ">"
    }

    public function test_dedilive_without_new_shows_range()
    {
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31250, null]]);
        $this->run_($this->alice, 'dedilive');
        $this->assertStringContainsString('(range 1.25):', $this->lastMessage());
    }

    public function test_ranking_none_without_records()
    {
        $this->run_($this->alice, 'dedilive');
        $this->assertStringContainsString('no records!', $this->lastMessage());
    }

    public function test_map_name_link_only_when_configured()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        $this->run_($this->alice, 'dedilive');
        $this->assertStringNotContainsString('$l[', $this->lastRaw());

        config(['dedimania.links.records' => 'http://dedi.example/records?Uid={uid}']);
        $this->run_($this->alice, 'dedilive');
        $this->assertStringContainsString('$l[http://dedi.example/records?Uid=UID1]My Map$l', $this->lastRaw());
    }

    // ---- stats, cp times ----

    public function test_dedistats_window()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        DedimaniaRecords::setPlayerMaxRank('alice', 50);
        $this->run_($this->alice, 'dedistats');
        $xml = $this->window();
        $this->assertStringContainsString('Dedimania Stats:', $xml);
        $this->assertStringContainsString('Total Races', $xml);
        $this->assertStringContainsString('text="50"', str_replace('{#black}', '', $xml) . 'text="50"');
        $this->assertStringContainsString('0.25', $xml);               // 10 / 40
        $this->assertStringNotContainsString('View all Dedimania records', $xml);

        config(['dedimania.links.records' => 'http://dedi.example/?Uid={uid}']);
        $this->run_($this->alice, 'dedistats');
        $this->assertStringContainsString('View all Dedimania records for this track', $this->window());
    }

    public function test_cp_times_and_sector_times()
    {
        $this->seedRecords(['bob' => [30000, [10000, 20000, 30000]], 'carol' => [31000, [11000, 21000, 31000]]]);

        $this->run_($this->alice, 'dedicptms');
        $this->assertStringContainsString('Current TOP 30 Dedimania CP Times (3):', $this->window());
        $this->assertStringContainsString('0:20.00', $this->window());

        $this->run_($this->alice, 'dedisectms');
        $this->assertStringContainsString('Sector Times (3):', $this->window());
    }

    public function test_cp_times_truncate_after_twelve_checkpoints()
    {
        $checks = range(1000, 14000, 1000);   // 14 checkpoints
        $this->seedRecords(['bob' => [14000, $checks]]);
        $this->run_($this->alice, 'dedicptms');
        $xml = $this->window();
        $this->assertStringContainsString('(14):', $xml);
        $this->assertStringContainsString('text="+"', $xml);
    }

    // ---- /dedicps ----

    private function fakeCheckpoints(bool $tracking = true): object
    {
        $fake = new class($tracking) implements CheckpointReference {
            public array $used = [];
            public function __construct(public bool $tracking) {}
            public function trackingEnabled(Player $player): bool { return $this->tracking; }
            public function useReference(Player $player, array $checkpoints): void { $this->used[$player->login] = $checkpoints; }
        };
        $this->app->instance(CheckpointReference::class, $fake);

        return $fake;
    }

    public function test_dedicps_requires_local_cps_tracking()
    {
        $this->fakeCheckpoints(false);
        $this->run_($this->alice, 'dedicps');
        $this->assertStringContainsString('You must first enable checkpoints tracking with  /cps', $this->lastMessage());
    }

    public function test_dedicps_disabled_by_server()
    {
        config(['funseco.display_checkpoints' => false]);
        $this->run_($this->alice, 'dedicps');
        $this->assertStringContainsString('permanently disabled by server', $this->lastMessage());
    }

    public function test_dedicps_settings_are_saved()
    {
        $this->fakeCheckpoints();
        $this->run_($this->alice, 'dedicps', ['3']);
        $this->assertStringContainsString('checkpoints tracking record: 3', $this->lastMessage());
        $this->assertSame(3, (int) PlayerExtra::where('player_id', $this->alice->id)->value('dedi'));

        $this->run_($this->alice, 'dedicps');
        $this->assertStringContainsString('ON (your own or the last record)', $this->lastMessage());
        $this->assertSame(0, (int) PlayerExtra::where('player_id', $this->alice->id)->value('dedi'));

        $this->run_($this->alice, 'dedicps', ['off']);
        $this->assertStringContainsString('tracking: OFF', $this->lastMessage());
        $this->assertSame(-1, (int) PlayerExtra::where('player_id', $this->alice->id)->value('dedi'));

        $this->run_($this->alice, 'dedicps', ['99']);
        $this->assertStringContainsString('No such Dedimania record  99', $this->lastMessage());
        $this->run_($this->alice, 'dedicps', ['abc']);
        $this->assertStringContainsString('No such Dedimania record  abc', $this->lastMessage());
    }

    public function test_arriving_records_replace_the_panel_reference()
    {
        $fake = $this->fakeCheckpoints();
        PlayerExtra::create(['player_id' => $this->alice->id, 'dedi' => 0]);     // own or last
        PlayerExtra::create(['player_id' => $this->carol->id, 'dedi' => 1]);     // record 1
        $this->service->shouldReceive('currentChallengeInfo')->andReturn(resolve([
            'records' => DedimaniaService::fromDedimaniaStruct([
                ['Login' => 'bob', 'NickName' => 'bob', 'Best' => 30000, 'Checks' => [15000, 30000]],
                ['Login' => 'carol', 'NickName' => 'carol', 'Best' => 31000, 'Checks' => [16000, 31000]],
            ]),
            'uid' => 'UID1', 'total_races' => 1, 'total_players' => 1, 'server_max_rank' => 30,
        ]));

        FetchRecords::for($this->map, announce: true);

        $this->assertSame([16000, 31000], $fake->used['alice']);   // no record of her own: the last one
        $this->assertSame([15000, 30000], $fake->used['carol']);   // asked for record 1
    }

    public function test_first_finish_becomes_reference_with_setting_zero()
    {
        $fake = $this->fakeCheckpoints();
        PlayerExtra::create(['player_id' => $this->alice->id, 'dedi' => 0]);
        $this->seedRecords(['bob' => [30000, null]]);

        (new PlayerFinishedListener())->handle(new PlayerFinished($this->alice, $this->map, 31000, $this->cps(15000, 31000)));

        $this->assertSame([15000, 31000], $fake->used['alice']);
    }

    public function test_cps_setting_change_resets_dedicps()
    {
        PlayerExtra::create(['player_id' => $this->alice->id, 'dedi' => 4]);
        event(new CheckpointsSettingChanged($this->alice));
        $this->assertSame(-1, (int) PlayerExtra::where('player_id', $this->alice->id)->value('dedi'));
    }

    public function test_checkpoints_plugin_implements_the_reference_contract()
    {
        $this->assertInstanceOf(CheckpointTracker::class, app(CheckpointReference::class));
    }

    // ---- finishes ----

    private function finish(Player $player, int $score): void
    {
        $this->calls = [];
        (new PlayerFinishedListener())->handle(new PlayerFinished($player, $this->map, $score, $this->cps($score - 1000, $score)));
    }

    public function test_first_record_is_announced_to_everyone()
    {
        $this->finish($this->alice, 30000);
        $text = $this->plain($this->messages('ChatSendServerMessage')[0]);
        $this->assertStringContainsString('Alice claimed the 1. Dedimania Record!  Time: 0:30.00', $text);
    }

    public function test_improvement_moving_up_and_same_rank_and_equal()
    {
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31000, null], 'alice' => [32000, null]]);

        $this->finish($this->alice, 30500);   // rank 3 -> 2
        $text = $this->messages('ChatSendServerMessage')[0];
        $this->assertStringContainsString('gained the', $text);
        $this->assertStringContainsString('2', $this->plain($text));
        $this->assertStringContainsString('-1.50', $text);

        $this->finish($this->alice, 30400);   // rank 2 -> 2
        $this->assertStringContainsString('secured his/her', $this->messages('ChatSendServerMessage')[0]);

        $this->finish($this->alice, 30400);   // equal
        $this->assertStringContainsString('equaled his/her', $this->messages('ChatSendServerMessage')[0]);

        $this->finish($this->alice, 39000);   // slower: nothing
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    public function test_record_below_limit_is_announced_privately()
    {
        config(['dedimania.database.limit_recs' => 1]);
        $this->seedRecords(['bob' => [30000, null]]);
        $this->finish($this->alice, 31000);   // rank 2 > limit 1, maxrank 30
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        $this->assertStringContainsString('claimed the', $this->messages()[0]);
        $this->assertStringStartsWith('> ', $this->plain($this->messages()[0]));
    }

    public function test_finish_beyond_max_rank_is_not_announced()
    {
        $this->seedRecords(['bob' => [30000, null]]);
        DedimaniaRecords::setStats($this->map->id, ['server_max_rank' => 1]);
        DedimaniaRecords::setPlayerMaxRank('alice', 1);
        $this->finish($this->alice, 31000);
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        $this->assertSame([], $this->messages());
    }

    public function test_no_announcement_when_display_recs_off_stunts_or_short_author_time()
    {
        config(['dedimania.database.display_recs' => false]);
        $this->finish($this->alice, 30000);
        $this->assertSame([], $this->messages('ChatSendServerMessage'));

        config(['dedimania.database.display_recs' => true]);
        ServerInfo::setGameMode(4);
        $this->finish($this->carol, 30000);
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
        $this->assertNull(DedimaniaRecords::rankOf($this->carol->id));

        ServerInfo::setGameMode(1);
        $this->map->update(['author_time' => 5000]);
        CurrentMap::set($this->map->fresh());
        (new PlayerFinishedListener())->handle(new PlayerFinished($this->carol, $this->map->fresh(), 30000, $this->cps(29000, 30000)));
        $this->assertNull(DedimaniaRecords::rankOf($this->carol->id));
    }

    public function test_finish_with_mismatching_checkpoints_is_ignored()
    {
        (new PlayerFinishedListener())->handle(new PlayerFinished($this->alice, $this->map, 30000, $this->cps(10000, 20000)));
        $this->assertCount(0, DedimaniaRecords::all());
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    // ---- arrival, banned, outage ----

    private function connect(Player $player, array $arrival): void
    {
        $this->calls = [];
        $this->service->shouldReceive('playerArrive')->once()->andReturn(resolve($arrival));
        (new PlayerConnectListener())->handle(new PlayerConnected($player, new PlayerInfo(['Login' => $player->login, 'NickName' => 'New '.$player->login])));
    }

    public function test_welcome_is_sent_after_arrival_without_dead_link()
    {
        $this->connect($this->alice, ['banned' => false, 'max_rank' => 40]);
        $this->assertStringContainsString('Welcome to the Dedimania world record system - see /helpdedi', $this->plain($this->messages()[0]));
        $this->assertSame(40, DedimaniaRecords::playerMaxRank('alice'));
        $this->assertSame(40, DedimaniaRecords::maxRank('alice'));
        $this->assertSame(30, DedimaniaRecords::maxRank('carol'));
    }

    public function test_welcome_can_be_switched_off()
    {
        config(['dedimania.database.show_welcome' => false]);
        $this->connect($this->alice, ['banned' => false, 'max_rank' => 30]);
        $this->assertSame([], $this->messages());
    }

    public function test_nickname_on_record_follows_the_join_nickname()
    {
        $this->seedRecords(['alice' => [30000, null]]);
        $this->connect($this->alice, ['banned' => false, 'max_rank' => 30]);
        $this->assertSame('New alice', DedimaniaRecords::all()->first()->player->nickname);
    }

    public function test_banned_player_is_announced_banned_from_server_and_finishes_ignored()
    {
        $query = Mockery::mock(\Funseco\Core\Servers\TmForever\Query::class)->shouldIgnoreMissing();
        $query->shouldReceive('Ban')->once()->with('alice');
        $server = Mockery::mock();
        $server->shouldReceive('query')->andReturn($query);
        \Funseco\Core\Servers\TmForever\Facades\Server::swap($server);
        $query->shouldReceive('ChatSendServerMessage')->once()->with(Mockery::on(fn ($m) => str_contains($m, 'is banned from Dedimania!') && str_contains($m, 'alice')));
        $query->shouldReceive('ChatSendServerMessageToLogin')->once()->with(Mockery::on(fn ($m) => str_contains($m, 'Finish ignored by Dedimania as you are banned!')), 'alice');

        $this->service->shouldReceive('playerArrive')->once()->andReturn(resolve(['banned' => true, 'max_rank' => 30]));
        (new PlayerConnectListener())->handle(new PlayerConnected($this->alice, new PlayerInfo(['Login' => 'alice', 'NickName' => 'Alice'])));

        (new PlayerFinishedListener())->handle(new PlayerFinished($this->alice, $this->map, 30000, $this->cps(29000, 30000)));
        $this->assertCount(0, DedimaniaRecords::all());
    }

    public function test_offline_dedimania_says_so_once_and_stays_quiet()
    {
        $this->service->shouldReceive('playerArrive')->andReturn(reject(new \RuntimeException('down')));
        $this->service->shouldReceive('currentChallengeInfo')->andReturn(reject(new \RuntimeException('down')));

        (new PlayerConnectListener())->handle(new PlayerConnected($this->alice, new PlayerInfo(['Login' => 'alice'])));
        FetchRecords::for($this->map, announce: true);
        (new PlayerConnectListener())->handle(new PlayerConnected($this->carol, new PlayerInfo(['Login' => 'carol'])));

        $broadcasts = $this->messages('ChatSendServerMessage');
        $this->assertCount(1, $broadcasts);
        $this->assertStringContainsString('Dedimania is not answering - Dedimania records are disabled for this track', $this->plain($broadcasts[0]));
        $this->assertSame([], $this->messages());   // no welcome, no ranking
        $this->assertCount(0, DedimaniaRecords::all());
    }

    public function test_outage_switches_dedimania_off_for_that_map_only()
    {
        $this->service->shouldReceive('currentChallengeInfo')->andReturn(reject(new \RuntimeException('down')));
        FetchRecords::for($this->map, announce: true);
        $this->assertTrue(\Funseco\Dedimania\Application\Outage::down($this->map->id));

        (new PlayerFinishedListener())->handle(new PlayerFinished($this->alice, $this->map, 30000, $this->cps(29000, 30000)));
        $this->assertCount(0, DedimaniaRecords::all());      // nothing recorded
        $this->assertCount(1, $this->messages('ChatSendServerMessage')); // only the outage notice, no record message

        $this->assertFalse(\Funseco\Dedimania\Application\Outage::down($this->map->id + 1)); // next map tries again
    }

    // ---- map start / end rankings ----

    public function test_ranking_at_map_start_and_end()
    {
        $this->service->shouldReceive('currentChallengeInfo')->andReturn(resolve([
            'records' => DedimaniaService::fromDedimaniaStruct([
                ['Login' => 'bob', 'NickName' => 'bob', 'Best' => 30000, 'Checks' => [29000, 30000]],
            ]),
            'uid' => 'UID1', 'total_races' => 5, 'total_players' => 2, 'server_max_rank' => 25,
        ]));

        FetchRecords::for($this->map, announce: true);
        $start = $this->plain($this->messages('ChatSendServerMessage')[0]);
        $this->assertStringContainsString('rankings on My Map before', $start);
        $this->assertSame(25, DedimaniaRecords::serverMaxRank());
        $this->assertSame(['uid' => 'UID1', 'total_races' => 5, 'total_players' => 2, 'server_max_rank' => 25], DedimaniaRecords::stats());

        $this->service->shouldReceive('challengeRaceTimes')->once()->andReturn(resolve(new RecordCollection()));
        $this->calls = [];
        (new MapEndedListener())->handle(new MapEnded($this->map, new ChallengeInfo(array_fill(0, 14, 0)), new PlayerRankingsCollection(), false, false));
        $this->assertStringContainsString('rankings on My Map after', $this->plain($this->messages('ChatSendServerMessage')[0]));
    }

    public function test_summary_setting_two_opens_the_records_window_for_everybody()
    {
        config(['dedimania.database.show_recs_before' => 2, 'dedimania.database.show_recs_after' => 2]);
        $this->seedRecords(['bob' => [30000, null], 'carol' => [31000, null]]);

        $this->calls = [];
        FetchRecords::announce(2, \Funseco\Dedimania\Application\RankingLine::BEFORE, $this->map);
        $this->assertSame([], $this->messages('ChatSendServerMessage'), 'no chat line');
        $logins = array_map(fn ($a) => $a[0], $this->sent('SendDisplayManialinkPageToLogin'));
        $this->assertContains('alice', $logins);
        $this->assertStringContainsString('Current TOP 30 Dedimania Records:', $this->window());
        $this->assertStringContainsString('0:31.00', $this->window());

        $this->calls = [];
        FetchRecords::announce(1, \Funseco\Dedimania\Application\RankingLine::BEFORE, $this->map);
        $this->assertNotSame([], $this->messages('ChatSendServerMessage'), '1 stays chat');
        $this->assertSame([], $this->sent('SendDisplayManialinkPageToLogin'));

        $this->calls = [];
        FetchRecords::announce(0, \Funseco\Dedimania\Application\RankingLine::BEFORE, $this->map);
        $this->assertSame([], $this->calls);
    }

    public function test_ranking_at_map_start_can_be_switched_off()
    {
        config(['dedimania.database.show_recs_before' => 0]);
        $this->service->shouldReceive('currentChallengeInfo')->andReturn(resolve([
            'records' => new RecordCollection(), 'uid' => 'UID1', 'total_races' => 0, 'total_players' => 0, 'server_max_rank' => 30,
        ]));
        FetchRecords::for($this->map, announce: true);
        $this->assertSame([], $this->messages('ChatSendServerMessage'));
    }

    public function test_commands_are_open_to_everyone_and_listed()
    {
        $names = array_column(app(ChatService::class)->commands($this->alice), 'name');
        $this->assertContains('helpdedi', $names);
        $this->assertContains('dedisectms', $names);
        $this->assertContains('dedicps', $names);
        $this->assertContains('dedirecs', $names);
    }
}
