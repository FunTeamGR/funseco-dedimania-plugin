<?php

namespace Funseco\Dedimania;

use Funseco\Core\SharedKernel\PublishesPluginConfig;
use Funseco\Core\SharedKernel\Settings\Setting;
use Funseco\Core\SharedKernel\Settings\SettingType;
use Funseco\Core\SharedKernel\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Funseco\Core\SharedKernel\UI\WidgetRegistry;
use Funseco\Dedimania\Presentation\DediCpButton;
use Funseco\Dedimania\Presentation\DediRecordsWidget;
use Funseco\Core\SharedKernel\Events\DedimaniaRecordsUpdated;
use Funseco\Core\Services\ManiaLink\ManiaLinkService;
use Funseco\Core\SharedKernel\Events\GameStatusChanged;
use Funseco\Core\SharedKernel\Events\MapEnded;
use Funseco\Core\SharedKernel\Events\MapStarted;
use Funseco\Core\SharedKernel\Events\PlayerConnected;
use Funseco\Core\SharedKernel\Events\PlayerFinished;
use Funseco\Core\SharedKernel\Events\StartupEvent;
use Funseco\Dedimania\Commands\HelpDediCommand;
use Funseco\Dedimania\Commands\DediRecsCommand;
use Funseco\Dedimania\Commands\DediNewCommand;
use Funseco\Dedimania\Commands\DediLiveCommand;
use Funseco\Dedimania\Commands\DediPbCommand;
use Funseco\Dedimania\Commands\DediFirstCommand;
use Funseco\Dedimania\Commands\DediLastCommand;
use Funseco\Dedimania\Commands\DediNextCommand;
use Funseco\Dedimania\Commands\DediDiffCommand;
use Funseco\Dedimania\Commands\DediRangeCommand;
use Funseco\Dedimania\Commands\DediCpsCommand;
use Funseco\Dedimania\Commands\DediStatsCommand;
use Funseco\Dedimania\Commands\DediCpTimesCommand;
use Funseco\Dedimania\Commands\DediSecTimesCommand;
use Funseco\Dedimania\Application\DediCheckpoints;
use Funseco\Core\SharedKernel\Events\CheckpointsSettingChanged;
use Funseco\Dedimania\Application\Listeners\DedimaniaRecordsUpdatedListener;
use Funseco\Dedimania\Application\Listeners\DediCpButtonListener;
use Funseco\Dedimania\Application\Listeners\GameStatusChangedListener;
use Funseco\Dedimania\Application\Listeners\MapEndedListener;
use Funseco\Dedimania\Application\Listeners\MapStartedListener;
use Funseco\Dedimania\Application\Listeners\PlayerConnectListener;
use Funseco\Dedimania\Application\Listeners\PlayerFinishedListener;
use Funseco\Dedimania\Application\Listeners\StartupListener;

class DedimaniaServiceProvider extends ServiceProvider
{
    use PublishesPluginConfig;

    private const COMMANDS = [
        HelpDediCommand::class, DediRecsCommand::class, DediNewCommand::class, DediLiveCommand::class,
        DediPbCommand::class, DediFirstCommand::class, DediLastCommand::class, DediNextCommand::class,
        DediDiffCommand::class, DediRangeCommand::class, DediCpsCommand::class, DediStatsCommand::class,
        DediCpTimesCommand::class, DediSecTimesCommand::class,
    ];

    /**
     * Register Dedimania plugin bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dedimania.php', 'dedimania');
        $this->publishPluginConfig(__DIR__.'/..');

        // url/nation/packmask go into the one-off session authentication, so they need a restart; the rest is read lazily.
        // Skipped: messages/windows/timing (texts and maps), links.records (template with {uid}), keys no code reads.
        $this->app->make(SettingsRegistry::class)->declare('dedimania', 'dedimania', [
            Setting::url('database.url', 'Dedimania server URL', restart: true),
            Setting::string('database.nation', 'Nation reported to Dedimania', restart: true),
            Setting::string('database.packmask', 'Packmask reported to Dedimania', restart: true),
            Setting::int('database.retry_minutes', 'Outage retry announcement interval', min: 1, unit: 'min'),
            Setting::bool('database.show_welcome', 'Show Dedimania welcome message'),
            Setting::int('database.show_min_recs', 'Records before ranking lines show', min: 0),
            Setting::int('database.show_recs_before', 'Ranking lines before race', min: 0),
            Setting::int('database.show_recs_after', 'Ranking lines after race', min: 0),
            Setting::bool('database.show_recs_range', 'Show record range'),
            Setting::bool('database.display_recs', 'Announce new records'),
            Setting::bool('database.show_rec_logins', 'Show logins in records window'),
            Setting::int('database.limit_recs', 'Records fetched / shown', min: 1, max: 100),
            Setting::url('links.site', 'Dedimania site link'),
            Setting::url('links.stats', 'Dedimania stats link'),
        ]);

        $this->app->singleton(DedimaniaService::class);
        $this->app->singleton(\Funseco\Core\SharedKernel\CurrentDedimaniaRecords::class, \Funseco\Dedimania\Infrastructure\CurrentDedimaniaRecordList::class);
    }

    /**
     * Bootstrap Dedimania plugin listeners and widget.
     */
    public function boot(): void
    {
        app(ManiaLinkService::class)->addActions(['dedi_records'], \Funseco\Dedimania\Application\OpenRecordsWindow::class);
        DediRecordsWidget::register(app(WidgetRegistry::class));

        // The "DEDI CP" button: shown on a new map and to joiners, hidden at the end of the race.
        app(ManiaLinkService::class)->addActions([DediCpButton::NAME], \Funseco\Dedimania\Application\OpenCpTimesWindow::class);
        DediCpButton::register(app(WidgetRegistry::class));
        Event::listen(StartupEvent::class, [DediCpButtonListener::class, 'startup']);
        Event::listen(MapStarted::class, [DediCpButtonListener::class, 'mapStarted']);
        Event::listen(MapEnded::class, [DediCpButtonListener::class, 'mapEnded']);
        Event::listen(PlayerConnected::class, [DediCpButtonListener::class, 'playerConnected']);
        Event::listen(\Funseco\Core\SharedKernel\Events\PlayerReady::class, [\Funseco\Dedimania\Application\Listeners\PlayerReadyListener::class, 'handle']);

        Event::listen(\Funseco\Core\SharedKernel\Events\RecordWidgetsToggled::class, fn ($e) => DediRecordsWidget::render($e->player));
        Event::listen(MapStarted::class,              MapStartedListener::class);
        Event::listen(MapEnded::class,                MapEndedListener::class);
        Event::listen(PlayerConnected::class,         PlayerConnectListener::class);
        Event::listen(PlayerFinished::class,          PlayerFinishedListener::class);
        Event::listen(DedimaniaRecordsUpdated::class, DedimaniaRecordsUpdatedListener::class);
        Event::listen(StartupEvent::class,            StartupListener::class);
        Event::listen(GameStatusChanged::class,       GameStatusChangedListener::class);
        // /cps (any form) resets the Dedimania checkpoints setting
        Event::listen(CheckpointsSettingChanged::class, fn (CheckpointsSettingChanged $e) => DediCheckpoints::reset($e->player));

        app(\Funseco\Core\SharedKernel\MenuIndicators::class)->register('cps.dedi',
            fn (\Funseco\Core\Models\Player $p) => DediCheckpoints::setting($p) !== DediCheckpoints::OFF);

        foreach (self::COMMANDS as $command) {
            \Chat::register($command);
        }
    }
}
