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

    public function getHeaderActions(): array
    {
        return [
            Action::make('update_latest')
                ->label('Update to latest')
                ->color('success')
                ->visible(fn() => $this->checkUpdateAvailable())
                ->action(fn() => $this->updateToLatest()),
        ];
    }

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
                        $channel = $record['channel'] ?? 'UNKNOWN';
                        $color = match (strtoupper($channel)) {
                            'STABLE', 'RECOMMENDED' => 'success',
                            'BETA' => 'warning',
                            'ALPHA' => 'danger',
                            default => 'gray',
                        };

                        // Using Filament's badge classes or inline styles for simplicity and consistency
                        // Assuming Tailwind is available as it's Filament
                        return sprintf(
                            '%s <span style="--c-50:var(--%s-50);--c-400:var(--%s-400);--c-600:var(--%s-600);" class="fi-badge flex items-center justify-center gap-x-1 rounded-md text-xs font-medium ring-1 ring-inset px-2 min-w-[theme(spacing.6)] py-1 fi-color-custom bg-custom-50 text-custom-600 ring-custom-600/10 dark:bg-custom-400/10 dark:text-custom-400 dark:ring-custom-400/30 ml-2 inline-flex">%s</span>',
                            $state,
                            $color,
                            $color,
                            $color,
                            $channel
                        );
                    }),

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
                    ->label('Install')
                    ->button()
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Install Core')
                    ->modalDescription('Are you sure you want to install this core version? The server will be restarted.')
                    ->visible(function (array $record) use ($server, $service) {
                        $core = $service->getInstalledCore($server);
                        // Hide if currently installed
                        return !(
                            ($core['build'] ?? null) == $record['build'] &&
                            ($core['version'] ?? null) == $this->selectedMinorVersion
                        );
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
        $builds = $response['builds'] ?? [];
        if (empty($builds))
            return false;

        $latest = end($builds);

        return $latest['build'] > $core['build'];
    }

    protected function updateToLatest(): void
    {
        $server = $this->getServer();
        $core = \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($server);
        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();

        $response = $service->getPaperBuilds($core['project'], $core['version']);
        $builds = $response['builds'] ?? [];

        $latest = !empty($builds) ? end($builds) : null;

        if ($latest) {
            $download = $latest['downloads']['application'] ?? $latest['downloads']['server:default'] ?? null;
            if ($download) {
                $service->installPaperCore(
                    $server,
                    $core['project'],
                    $core['version'],
                    $latest['build'],
                    $download['url'],
                    $download['checksums']['sha256'] ?? ''
                );

                \Filament\Notifications\Notification::make()->title('Updated to build ' . $latest['build'])->success()->send();
            }
        }
    }
}
