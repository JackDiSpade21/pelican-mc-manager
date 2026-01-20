<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use Filament\Pages\Page;
use App\Models\Server;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Facades\Filament;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Components\TextInput;

use Filament\Forms\Components\Select;
use Illuminate\Pagination\LengthAwarePaginator;
use Filament\Infolists\Components\TextEntry;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;

class CoreManagementPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-cpu';

    protected static ?string $slug = 'core-management';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Core Management';

    public $selectedProject = 'paper';
    public $selectedMajorVersion = null;
    public $selectedMinorVersion = null;


    public function getTitle(): string
    {
        return 'Core Management';
    }

    public function mount(): void
    {
        // Init logic
        $this->selectedProject = 'paper';

        $server = $this->getServer();
        $core = \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($server);

        // 1. Try to use installed core info
        if (!empty($core['version'])) {
            if (!empty($core['project'])) {
                $this->selectedProject = $core['project'];
            }

            // Basic parsing attempt, assuming format 1.x.y
            $parts = explode('.', $core['version']);
            if (count($parts) >= 2) {
                // Paper API usually uses versions like "1.21" for major
                // If version is "1.21.1", major is likely "1.21"
                // Ideally we would double check against API, but for pre-select best effort is fine.
                $this->selectedMajorVersion = $parts[0] . '.' . $parts[1];
                $this->selectedMinorVersion = $core['version'];
            }
        } else {
            // 2. If not installed, try to detect from server tags (Egg)
            $loader = \Boy132\MinecraftModrinth\Enums\MinecraftLoader::fromServer($server);
            if ($loader === \Boy132\MinecraftModrinth\Enums\MinecraftLoader::Velocity) {
                $this->selectedProject = 'velocity';
            }
        }
    }

    // Helper to get server (Page usually has record/server context?)
    // In Pelican/Filament Server pages, usually $record or helper exists.
    // Based on ProjectPage code ` MinecraftLoader::fromServer($server)`, let's look at how it gets server.
    // It uses `$server` passed in closure.
    // WE need to get the server instance. `Server\Pages` implies it's scoped to a server.
    // Typically `public $record;` or similar is available if using `InteractsWithRecord`.
    // Let's assume we can get it via route parameter or helper for now. 
    // Wait, checking `MinecraftModrinthProjectPage`, it doesn't seem to store $server property explicitly in the snippet I saw.
    // BUT checking the `content` method: `function content(Schema $schema): Schema`.
    // And `MinecraftModrinthProjectPage` uses `$server` variable inside closures.
    // Standard Pterodactyl/Pelican panel plugins usually have `public $server` or use `getRecord()`.
    // Let's assume `$this->record` is the server if it uses standard Filament Resource page patterns, 
    // OR look at `MinecraftModrinthPlugin` discovery.
    // Let's assume `Server` model binding. I will use `request()->route()->parameter('server')` or similar if needed, 
    // but usually `$this->record` works if it's a Resource Page. 
    // IF it is a simple Page rooted in a panel, we might need to find the server.
    // `MinecraftModrinthProjectPage` calls `MinecraftModrinth::getMinecraftVersion($server)`.
    // Let's look at `MinecraftModrinthProjectPage` again to see how it gets `$server`.
    // Ah, I don't see `mount` in the snippet.
    // I will try to use `request()->route('server')` as a fallback.

    protected function getServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!$server) {
            throw new \Exception("Server not found (Tenant is null)");
        }

        return $server;
    }

    public function table(Table $table): Table
    {
        $server = $this->getServer();
        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();

        return $table
            ->headerActions([
                Action::make('updateToLatest')
                    ->label('Update to latest')
                    ->visible(fn() => $this->checkUpdateAvailable())
                    ->action(fn() => $this->updateToLatest()),
            ])
            ->records(function (?string $search, int $page) use ($server, $service) {
                $builds = [];
                try {
                    if ($this->selectedProject && $this->selectedMajorVersion && $this->selectedMinorVersion) {
                        $response = $service->getPaperBuilds($this->selectedProject, $this->selectedMinorVersion);

                        // Handle varied response structures safely
                        $rawBuilds = $response['builds'] ?? $response ?? [];
                        if (isset($rawBuilds['builds'])) {
                            $rawBuilds = $rawBuilds['builds'];
                        }

                        // Verify it's an array before processing
                        if (is_array($rawBuilds)) {
                            // Sort builds descending (newest first)
                            usort($rawBuilds, function ($a, $b) {
                                $idA = is_array($a) ? ($a['id'] ?? 0) : $a;
                                $idB = is_array($b) ? ($b['id'] ?? 0) : $b;
                                return $idB <=> $idA;
                            });

                            // Map API response to Filament table structure
                            $builds = array_map(function ($item) {
                                if (is_array($item)) {
                                    // Full object response (API v3)
                                    // Map 'id' to 'build' for table column compatibility
                                    $item['build'] = $item['id'] ?? 'Unknown';
                                    return $item;
                                } else {
                                    // Simple integer response (fallback)
                                    return ['build' => $item, 'id' => $item];
                                }
                            }, $rawBuilds);
                        }
                    }
                } catch (\Throwable $e) {
                    report($e);
                    // Return empty list on error
                    $builds = [];
                }

                // Manual pagination similar to reference implementation
                $perPage = 10;
                $offset = ($page - 1) * $perPage;
                $itemsForCurrentPage = array_slice($builds, $offset, $perPage);

                return new LengthAwarePaginator(
                    $itemsForCurrentPage,
                    count($builds),
                    $perPage,
                    $page
                );
            })
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('build')
                    ->label('Build')
                    ->searchable()
                    ->sortable()
                    ->html()
                    ->formatStateUsing(function (string $state, array $record) {
                        $core = app(\Boy132\MinecraftModrinth\Services\MinecraftModrinthService::class)->getInstalledCore(Filament::getTenant());
                        if (
                            ($core['build'] ?? null) == $record['build'] &&
                            ($core['version'] ?? null) == $this->selectedMinorVersion
                        ) {
                            return $state . ' <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-medium bg-blue-50 text-blue-700 dark:bg-blue-400/10 dark:text-blue-400 ring-1 ring-inset ring-blue-700/10 dark:ring-blue-400/20 ml-2">Installed</span>';
                        }
                        return $state;
                    }),

                TextColumn::make('channel')
                    ->badge()
                    ->color(function (array $record) {
                        return match (strtoupper($record['channel'] ?? 'UNKNOWN')) {
                            'STABLE', 'RECOMMENDED' => 'success',
                            'BETA' => 'warning',
                            'ALPHA' => 'danger',
                            default => 'gray',
                        };
                    })
                    ->formatStateUsing(fn(string $state) => strtoupper($state)),

                TextColumn::make('time')
                    ->label('Release Date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('commits.0.message')
                    ->label('Changes')
                    ->limit(50)
                    ->tooltip(fn($record) => $record['commits'][0]['message'] ?? '')
                    ->wrap(),
            ])
            ->actions([
                Action::make('install')
                    ->label(function (array $record) use ($server, $service) {
                        $core = $service->getInstalledCore($server);
                        if (
                            ($core['build'] ?? null) == $record['build'] &&
                            ($core['version'] ?? null) == $this->selectedMinorVersion
                        ) {
                            return 'Reinstall';
                        }
                        return 'Install';
                    })
                    ->button()
                    ->color(function (array $record) use ($server, $service) {
                        $core = $service->getInstalledCore($server);
                        if (
                            ($core['build'] ?? null) == $record['build'] &&
                            ($core['version'] ?? null) == $this->selectedMinorVersion
                        ) {
                            return 'warning';
                        }
                        return 'primary';
                    })
                    ->requiresConfirmation()
                    ->modalHeading('Install Core')
                    ->modalDescription(function (array $record) use ($server, $service) {
                        $core = $service->getInstalledCore($server);
                        $isInstalled = ($core['build'] ?? null) == $record['build'] && ($core['version'] ?? null) == $this->selectedMinorVersion;
                        return $isInstalled
                            ? 'Are you sure you want to reinstall this core version? The server will be restarted.'
                            : 'Are you sure you want to install this core version? The server will be restarted.';
                    })
                    ->action(function (array $record) use ($server, $service) {
                        // Paper API v3 uses 'server:default' or 'application'
                        $downloads = $record['downloads'] ?? [];
                        $download = $downloads['server:default'] ?? $downloads['application'] ?? null;

                        // Fallback to first element if specific keys missing
                        if (!$download && !empty($downloads)) {
                            $download = reset($downloads);
                        }

                        if (!$download || !isset($download['url'])) {
                            Notification::make()->title('Download URL not found')->danger()->send();
                            return;
                        }

                        $url = $download['url'];
                        $checksum = $download['checksums']['sha256'] ?? '';

                        $service->installPaperCore(
                            $server,
                            $this->selectedProject,
                            $this->selectedMinorVersion,
                            $record['build'],
                            $url,
                            $checksum
                        );

                        Notification::make()->title('Core installed')->success()->send();
                        $this->dispatch('refresh');
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextEntry::make('Minecraft Version')
                            ->state(fn() => MinecraftModrinth::getMinecraftVersion($server) ?? 'Not installed')
                            ->badge()
                            ->color(fn($state) => $state === 'Not installed' ? 'gray' : 'primary'),

                        TextEntry::make('Loader')
                            ->state(fn() => MinecraftLoader::fromServer($server)?->getLabel() ?? 'Unknown')
                            ->badge(),

                        TextEntry::make('installed_build')
                            ->label('Installed Build')
                            ->state(fn() => MinecraftModrinth::getInstalledCore($server)['build'] ?? 'None')
                            ->badge()
                            ->color(fn($state) => $state === 'None' ? 'gray' : 'success'),
                    ]),

                Section::make('Version Selection')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                Select::make('selectedMajorVersion')
                                    ->label('Major Version')
                                    ->live()
                                    ->options(function () {
                                        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();
                                        $projectData = $service->getPaperProject($this->selectedProject ?? 'paper');
                                        $versions = array_keys($projectData['versions'] ?? []);
                                        usort($versions, function ($a, $b) {
                                            return version_compare($b, $a);
                                        });
                                        return array_combine($versions, $versions);
                                    })
                                    ->afterStateUpdated(function () {
                                        $this->selectedMinorVersion = null;
                                    }),

                                Select::make('selectedMinorVersion')
                                    ->label('Minor Version')
                                    ->live()
                                    ->options(function () {
                                        if (!$this->selectedMajorVersion)
                                            return [];
                                        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();
                                        $projectData = $service->getPaperProject($this->selectedProject ?? 'paper');
                                        $minors = $projectData['versions'][$this->selectedMajorVersion] ?? [];
                                        usort($minors, function ($a, $b) {
                                            return version_compare($b, $a);
                                        });
                                        return array_combine($minors, $minors);
                                    }),
                            ]),
                    ]),

                EmbeddedTable::make(),
            ]);
    }

    protected function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    protected function checkUpdateAvailable(): bool
    {
        $server = $this->getServer();
        $core = \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($server);
        if (empty($core['version']) || empty($core['build']))
            return false;

        // Fetch latest build for this version
        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();
        $response = $service->getPaperBuilds($core['project'] ?? 'paper', $core['version']);

        $rawBuilds = $response['builds'] ?? $response ?? [];
        if (isset($rawBuilds['builds'])) {
            $rawBuilds = $rawBuilds['builds'];
        }

        if (empty($rawBuilds) || !is_array($rawBuilds))
            return false;

        $latest = end($rawBuilds);
        // If it's an API v3 object, get 'id', otherwise it's the build ID itself (v2)
        $latestId = is_array($latest) ? ($latest['id'] ?? $latest['build'] ?? 0) : $latest;

        $installedId = $core['build'];

        return (string) $latestId !== (string) $installedId;
    }

    protected function updateToLatest(): void
    {
        $server = $this->getServer();
        $core = \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($server);
        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();

        $response = $service->getPaperBuilds($core['project'], $core['version']);

        $rawBuilds = $response['builds'] ?? $response ?? [];
        if (isset($rawBuilds['builds'])) {
            $rawBuilds = $rawBuilds['builds'];
        }

        if (empty($rawBuilds) || !is_array($rawBuilds)) {
            \Filament\Notifications\Notification::make()->title('No builds found')->danger()->send();
            return;
        }

        $latest = end($rawBuilds);

        // Handle API v3 downloads structure
        if (isset($latest['downloads'])) {
            $downloads = $latest['downloads'];
            $download = $downloads['application'] ?? $downloads['server:default'] ?? null;
        } else {
            // Fallback for API v2 if builds are just IDs (shouldn't happen with getPaperBuilds but good to be safe)
            \Filament\Notifications\Notification::make()->title('Cannot determine download URL')->danger()->send();
            return;
        }

        if ($download) {
            // Get build ID safely
            $latestId = is_array($latest) ? ($latest['id'] ?? $latest['build'] ?? 'latest') : 'latest';

            $service->installPaperCore(
                $server,
                $core['project'],
                $core['version'],
                $latestId,
                $download['url'],
                $download['checksums']['sha256'] ?? ''
            );

            \Filament\Notifications\Notification::make()->title('Updated to build ' . $latestId)->success()->send();
            $this->dispatch('refresh');
        } else {
            \Filament\Notifications\Notification::make()->title('Download not found for latest build')->danger()->send();
        }
    }
}
