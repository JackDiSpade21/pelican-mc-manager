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

        // Try to pre-select based on installed version
        $server = $this->getServer();
        $core = \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($server);

        if (!empty($core['version'])) {
            // Basic parsing attempt, assuming format 1.x.y
            // The API returns major versions like "1.21" which contains "1.21.1"
            // We'll perform a best effort match when fetching data
            $parts = explode('.', $core['version']);
            if (count($parts) >= 2) {
                $this->selectedMajorVersion = $parts[0] . '.' . $parts[1]; // 1.21
                $this->selectedMinorVersion = $core['version'];
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
            ->records(function () use ($server, $service) {
                // Fetch project data (cached or explicit?)
                // Note: The UI separates project/version selection from the table list.
                // The table only shows BUILDS if specific major/minor is selected.
    
                $builds = [];
                if ($this->selectedProject && $this->selectedMajorVersion && $this->selectedMinorVersion) {
                    $response = $service->getPaperBuilds($this->selectedProject, $this->selectedMinorVersion);
                    $rawBuilds = $response['builds'] ?? $response ?? []; // API usually returns {builds: [...]} or [...] array
    
                    if (isset($rawBuilds['builds'])) {
                        $rawBuilds = $rawBuilds['builds'];
                    }

                    // Reverse to show latest first
                    $builds = array_reverse($rawBuilds);
                }

                // Return paginator as expected by Filament (even if we just put everything in one page for now)
                return new LengthAwarePaginator(
                    $builds,
                    count($builds),
                    count($builds) > 0 ? count($builds) : 10,
                    1
                );
            })
            ->columns([
                TextColumn::make('build')
                    ->label('Build ID'),
                TextColumn::make('channel')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'default', 'STABLE' => 'success',
                        'experimental', 'BETA' => 'warning',
                        'ALPHA' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('time')
                    ->dateTime(),
                TextColumn::make('changes.0.message')
                    ->label('Message')
                    ->limit(50)
                    ->tooltip(fn($record) => $record['changes'][0]['message'] ?? ''),
                TextColumn::make('downloads.application.size')
                    ->label('Size')
                    ->formatStateUsing(fn($state, $record) => $this->formatSize($record['downloads']['application']['size'] ?? $record['downloads']['server:default']['size'] ?? 0)),
            ])
            ->actions([
                Action::make('install')
                    ->label('Install')
                    ->button()
                    ->color('primary')
                    ->requiresConfirmation()
                    ->action(function (array $record) use ($server, $service) {
                        $download = $record['downloads']['application'] ?? $record['downloads']['server:default'] ?? null;
                        if (!$download) {
                            Notification::make()->title('Download not found')->danger()->send();
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

                Action::make('installed')
                    ->label('Installed')
                    ->badge()
                    ->color('gray')
                    ->visible(function (array $record) use ($server, $service) {
                        $core = $service->getInstalledCore($server);
                        return ($core['build'] ?? null) == $record['build']
                            && ($core['version'] ?? null) == $this->selectedMinorVersion;
                    })
                    ->disabled(),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Core Info')
                    ->columns(4)
                    ->schema([
                        ViewField::make('info_box')
                            ->view('filament.forms.components.placeholder')
                            ->hidden(),

                        TextInput::make('current_version')
                            ->label('Minecraft Version')
                            ->disabled()
                            ->formatStateUsing(fn() => \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getMinecraftVersion($this->getServer()) ?? 'Not installed'),

                        TextInput::make('loader')
                            ->label('Loader')
                            ->disabled()
                            ->default('Paper'),

                        TextInput::make('installed_build')
                            ->label('Installed Build')
                            ->disabled()
                            ->formatStateUsing(fn() => \Boy132\MinecraftModrinth\Facades\MinecraftModrinth::getInstalledCore($this->getServer())['build'] ?? 'None'),


                    ]),

                Section::make('Version Selection')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Select::make('selectedProject')
                                    ->label('Project')
                                    ->options([
                                        'paper' => 'Paper',
                                        'velocity' => 'Velocity',
                                    ])
                                    ->live()
                                    ->afterStateUpdated(function () {
                                        $this->selectedMajorVersion = null;
                                        $this->selectedMinorVersion = null;
                                    }),

                                Select::make('selectedMajorVersion')
                                    ->label('Major Version')
                                    ->live()
                                    ->options(function () {
                                        $service = new \Boy132\MinecraftModrinth\Services\MinecraftModrinthService();
                                        $projectData = $service->getPaperProject($this->selectedProject ?? 'paper');
                                        $versions = array_keys($projectData['versions'] ?? []);
                                        rsort($versions);
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
