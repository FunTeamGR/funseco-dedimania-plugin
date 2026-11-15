<?php

namespace Funseco\Dedimania;

use Psr\Http\Message\ResponseInterface;
use React\Promise\PromiseInterface;
use Funseco\Core\Models\Map;
use Funseco\Core\Models\Player;
use Funseco\Core\Servers\TmForever\Ixr\IxrRequest;
use Funseco\Core\Servers\TmForever\Values\ChallengeInfo;
use Funseco\Core\Servers\TmForever\Values\CheckpointCollection;
use Funseco\Core\Servers\TmForever\Values\PlayerInfo;
use Funseco\Core\Services\Cache\CurrentPlayers;
use Funseco\Core\Services\Cache\CurrentSpectators;
use Funseco\Core\Services\Cache\ServerInfo;
use Funseco\Core\Services\Http\AsyncHttpClient;
use Funseco\Core\SharedKernel\Values\Record;
use Funseco\Core\SharedKernel\Values\RecordCollection;

/**
 * Dedimania XML-RPC client. Every call is non-blocking and returns a promise;
 * failures reject with DedimaniaException (or a transport error).
 */
class DedimaniaService
{
    /** @var array<string, string> session cookies, name => value */
    private array $cookies = [];

    public function __construct(private AsyncHttpClient $http)
    {
    }

    /**
     * @return PromiseInterface<RecordCollection>
     */
    public function currentChallenge(Map $map): PromiseInterface
    {
        return $this->currentChallengeInfo($map)->then(fn (array $info) => $info['records']);
    }

    /**
     * Records plus the track statistics of the response.
     *
     * @return PromiseInterface<array{records: RecordCollection, uid: ?string, total_races: int, total_players: int, server_max_rank: int}>
     */
    public function currentChallengeInfo(Map $map): PromiseInterface
    {
        $serverInfo = ServerInfo::get();
        $data       = [
            [
                'methodName' => 'dedimania.CurrentChallenge',
                'params'     => [
                    $map->uid,
                    $map->name,
                    $map->environment,
                    $map->author,
                    'TMF',
                    ServerInfo::getGameMode(),
                    [
                        'SrvName'    => $serverInfo['server_name'],
                        'Comment'    => $serverInfo['server_comment'],
                        'Private'    => (bool) $serverInfo['hidden'],
                        'NumPlayers' => CurrentPlayers::all()->count() - CurrentSpectators::all()->count(),
                        'MaxPlayers' => $serverInfo['max_players'],
                        'NumSpecs'   => CurrentSpectators::all()->count(),
                        'MaxSpecs'   => $serverInfo['max_spectators'],
                    ],
                    config('dedimania.database.limit_recs'),
                    CurrentPlayers::all()->map(function ($player) {
                        return [
                            'Login'  => $player->login,
                            'IsSpec' => CurrentSpectators::has($player->login),
                        ];
                    })->values()
                        ->toArray(),
                ],
            ],
        ];

        return $this->request($data)->then(function (array $response) {
            if (!isset($response[1][0]['Records'])) {
                throw new DedimaniaException('Error fetching records: '
                    .json_encode($response));
            }

            $result = $response[1][0];

            return [
                'records'         => self::fromDedimaniaStruct($result['Records']),
                'uid'             => $result['Uid'] ?? null,
                'total_races'     => (int) ($result['TotalRaces'] ?? 0),
                'total_players'   => (int) ($result['TotalPlayers'] ?? 0),
                'server_max_rank' => (int) ($result['ServerMaxRecords'] ?? 30) ?: 30,
            ];
        });
    }

    /**
     * @return PromiseInterface<RecordCollection>
     */
    public function challengeRaceTimes(ChallengeInfo $challengeInfo, RecordCollection $records): PromiseInterface
    {
        $data     = [
            [
                'methodName' => 'dedimania.ChallengeRaceTimes',
                'params'     => [
                    $challengeInfo->uid,
                    $challengeInfo->name,
                    $challengeInfo->environment,
                    $challengeInfo->author,
                    'TMF',
                    ServerInfo::getGameMode(),
                    $challengeInfo->cp_count,
                    config('dedimania.database.limit_recs'),
                    $records->map(function (Record $record) {
                        return [
                            'Login'  => $record->player->login,
                            'Best'   => $record->score,
                            'Checks' => $record->checkpoints->toDedimaniaStruct(),
                        ];
                    })->values()->toArray(),
                ],
            ],
        ];
        return $this->request($data)->then(function (array $response) {
            if (!isset($response[1][0]['Records'])) {
                throw new DedimaniaException('Error submitting race times: '
                    .json_encode($response));
            }

            return self::fromDedimaniaStruct($response[1][0]['Records']);
        });
    }

    /**
     * @return PromiseInterface<array{banned: bool, max_rank: int}>
     */
    public function playerArrive(Player $player, PlayerInfo $playerInfo): PromiseInterface
    {
        $data = [
            [
                'methodName' => 'dedimania.PlayerArrive',
                'params'     => [
                    'TMF',
                    $player->login,
                    $playerInfo->nickname,
                    $playerInfo->nation,
                    $player->team_name,
                    $playerInfo->ladder_ranking,
                    $playerInfo->is_spectator,
                    $playerInfo->is_in_official_mode,
                ],
            ],
        ];

        return $this->request($data)->then(function (array $response) {
            if (!isset($response[1][0]['Login'])) {
                throw new DedimaniaException('Error announcing player arrival: '
                    .json_encode($response));
            }

            return [
                'banned'   => (bool) ($response[1][0]['Banned'] ?? false),
                'max_rank' => (int) ($response[1][0]['MaxRank'] ?? 30) ?: 30,
            ];
        });
    }

    /**
     * @return PromiseInterface<void>
     */
    public function authenticate(): PromiseInterface
    {
        $data = [
            [
                'methodName' => 'dedimania.ValidateAccount',
                'params'     => [],
            ],
            [
                'methodName' => 'dedimania.WarningsAndTTR',
                'params'     => [],
            ],
        ];

        return $this->request($data)->then(function (array $response) {
            if (($response[0][0] ?? null) !== true || ($response[1][0]['Status'] ?? null) !== true) {
                throw new DedimaniaException('Error authenticating with Dedimania: '
                    .json_encode($response));
            }

            \Log::info('Dedimania authenticated');
        });
    }

    public static function fromDedimaniaStruct(array $records): RecordCollection
    {
        return new RecordCollection(array_map(
            fn($record, $index) => self::recordFromDedimania($record, $index),
            $records,
            array_keys($records)
        ));
    }

    private static function recordFromDedimania(array $data, int $tiebreaker): Record
    {
        $player = Player::where('login', $data['Login'])->first() ?: new Player([
            'login'    => $data['Login'],
            'nickname' => $data['NickName'],
        ]);

        if (!$player->id) {
            $player->id = crc32($data['Login']);
        }

        $record             = new Record($player->id, $data['Best'], $tiebreaker);
        $record->player     = $player;
        $record->checkpoints = CheckpointCollection::fromDedimaniaStruct($data['Checks']);

        return $record;
    }

    /**
     * Sends a system.multicall with dedimania.Authenticate prepended.
     *
     * @return PromiseInterface<array> decoded multicall results
     */
    private function request(array $data): PromiseInterface
    {
        $data = [
            [
                'methodName' => 'dedimania.Authenticate',
                'params'     => [
                    [
                        'Game'     => 'TMF',
                        'Login'    => config('funseco.master_server.login'),
                        'Password' => config('funseco.master_server.password'),
                        'Packmask' => config('dedimania.database.packmask'),
                        'Tool'     => 'XASECO',
                        'Version'  => '1.16',
                        'Nation'   => config('dedimania.database.nation'),
                    ],
                ],
            ], ...$data,
        ];

        $request = new IxrRequest('system.multicall', [$data]);
        $headers = ['Content-Type' => 'text/xml; charset=UTF8'];
        if ($this->cookies) {
            $headers['Cookie'] = implode('; ', array_map(
                fn($name, $value) => "$name=$value",
                array_keys($this->cookies),
                $this->cookies
            ));
        }

        return $this->http->post(config('dedimania.database.url'), $request->getCleanXml(), $headers)
            ->then(function (ResponseInterface $response) {
                $this->storeCookies($response);

                $decoded = xmlrpc_decode((string) $response->getBody());
                if (!is_array($decoded)) {
                    throw new DedimaniaException('Invalid Dedimania response: '.$response->getBody());
                }

                return $decoded;
            });
    }

    private function storeCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            [$pair] = explode(';', $header, 2);
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $this->cookies[$name] = $value;
        }
    }
}
