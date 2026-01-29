<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class MinecraftModrinthProjectPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-packages';

    protected static ?string $slug = 'modrinth';

    protected static ?int $navigationSort = 30;

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && ModrinthProjectType::fromServer($server);
    }

    public static function getNavigationLabel(): string
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return ModrinthProjectType::fromServer($server)->getLabel();
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /**
     * @throws Exception
     */
    #[\Livewire\Attributes\Url]
    public string $currentView = 'browse';

    public bool $showIncompatible = false;

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        if ($this->currentView === 'installed') {
            return $table
                ->records(function (?string $search, int $page) use (&$availableUpdates) {
                    /** @var Server $server */
                    $server = Filament::getTenant();
                    $items = MinecraftModrinth::getInstalledProjects($server);

                    if ($search) {
                        $items = array_filter($items, function ($item) use ($search) {
                            return stripos($item['name'], $search) !== false;
                        });
                    }

                    // We need to pass available updates to actions, maybe store in a temporary property or recalculate?
                    // Recalculating efficiently might be tricky. Let's try to fetch them here and use closure scope?
                    // Actually, 'records' returns the data. Actions are defined on the table. 
                    // Let's use a cached/memoized fetch in the service?
                    // For now, let's just fetch them. Ideally this is slow so we might want a "Check Updates" button trigger.
                    // But user asked for it to appear.
    
                    return new LengthAwarePaginator(
                        $items, // Pagination handled by client? No, array slice.
                        count($items),
                        20,
                        1
                    );
                })
                ->records(function (?string $search, int $page) {
                    /** @var Server $server */
                    $server = Filament::getTenant();
                    $items = MinecraftModrinth::getInstalledProjects($server);

                    if ($search) {
                        $items = array_filter($items, function ($item) use ($search) {
                            return stripos($item['name'], $search) !== false;
                        });
                    }

                    $perPage = 20;
                    $offset = ($page - 1) * $perPage;
                    $itemsForCurrentPage = array_slice($items, $offset, $perPage);

                    return new LengthAwarePaginator(
                        $itemsForCurrentPage,
                        count($items),
                        $perPage,
                        $page
                    );
                })
                ->paginated([20])
                ->headerActions([
                    Action::make('update_all')
                        ->label(trans('pelican-mc-manager::strings.actions.update_all'))
                        ->icon('tabler-refresh')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->action(function () {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $updates = MinecraftModrinth::getAvailableUpdates($server); // This might be slow
            
                            foreach ($updates as $projectId => $newVersion) {
                                MinecraftModrinth::updateInstalledPlugin($server, $projectId, $newVersion);
                            }

                            Notification::make()->title(trans('pelican-mc-manager::strings.notifications.all_plugins_updated'))->success()->send();
                            $this->redirect(static::getUrl(['currentView' => 'installed']));
                        })
                        ->visible(function () {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            // Checking here might cause double API calls on render. 
                            // Optimization: cache getAvailableUpdates results for this request/short duration?
                            // Service already caches 'getModrinthVersions'. checking updates logic is fast as it uses cached versions.
                            return count(MinecraftModrinth::getAvailableUpdates($server)) > 0;
                        }),
                ])
                ->actions([
                    Action::make('update')
                        ->icon('tabler-refresh')
                        ->color('warning')
                        ->label(trans('pelican-mc-manager::strings.actions.update'))
                        ->requiresConfirmation()
                        ->action(function (array $record) {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $updates = MinecraftModrinth::getAvailableUpdates($server);

                            if (isset($updates[$record['project_id']])) {
                                MinecraftModrinth::updateInstalledPlugin($server, $record['project_id'], $updates[$record['project_id']]);
                                Notification::make()->title(trans('pelican-mc-manager::strings.notifications.plugin_updated'))->success()->send();
                                $this->redirect(static::getUrl(['currentView' => 'installed']));
                            }
                        })
                        ->visible(function (array $record) {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $updates = MinecraftModrinth::getAvailableUpdates($server);
                            return isset($updates[$record['project_id']]);
                        }),
                    Action::make('delete')
                        ->icon('tabler-trash')
                        ->color('danger')
                        ->label(trans('pelican-mc-manager::strings.actions.delete'))
                        ->requiresConfirmation()
                        ->action(function (array $record) {
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            try {
                                MinecraftModrinth::deleteInstalledPlugin($server, $record['project_id']);
                                Notification::make()->title(trans('pelican-mc-manager::strings.notifications.plugin_deleted'))->success()->send();
                                $this->redirect(static::getUrl(['currentView' => 'installed']));
                            } catch (\Throwable $e) {
                                Notification::make()->title(trans('pelican-mc-manager::strings.notifications.error_deleting_plugin'))->body($e->getMessage())->danger()->send();
                            }
                        }),
                ])
                ->columns([
                    ImageColumn::make('icon_url')
                        ->label(''),
                    TextColumn::make('name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('version_number')
                        ->label(trans('pelican-mc-manager::strings.table.columns.version'))
                        ->badge()
                        ->color(function (array $record) {
                            // Highlight outdated versions?
                            /** @var Server $server */
                            $server = Filament::getTenant();
                            $updates = MinecraftModrinth::getAvailableUpdates($server);
                            return isset($updates[$record['project_id']]) ? 'warning' : 'info';
                        })
                        ->sortable(),
                    TextColumn::make('description')
                        ->limit(50),
                    TextColumn::make('date_installed')
                        ->label(trans('pelican-mc-manager::strings.page.installed_date'))
                        ->icon('tabler-calendar')
                        ->formatStateUsing(fn($state) => Carbon::parse($state)->diffForHumans())
                        ->sortable(),
                    TextColumn::make('size')
                        ->formatStateUsing(fn($state) => convert_bytes_to_readable($state))
                        ->sortable(),
                ]);
        }

        return $table
            ->headerActions([
                Action::make('toggle_incompatible')
                    ->label(trans('pelican-mc-manager::strings.page.show_incompatible'))
                    ->icon(fn() => $this->showIncompatible ? 'tabler-eye' : 'tabler-eye-off')
                    ->color(fn() => $this->showIncompatible ? 'warning' : 'gray')
                    ->action(function () {
                        $this->showIncompatible = !$this->showIncompatible;
                    }),
            ])
            ->records(function (?string $search, int $page) {
                /** @var Server $server */
                $server = Filament::getTenant();

                $response = MinecraftModrinth::getModrinthProjects($server, $page, $search, $this->showIncompatible);

                return new LengthAwarePaginator($response['hits'], $response['total_hits'], 20, $page);
            })
            ->paginated([20])
            ->columns([
                ImageColumn::make('icon_url')
                    ->label(''),
                TextColumn::make('title')
                    ->searchable()
                    ->description(fn(array $record) => (strlen($record['description']) > 120) ? substr($record['description'], 0, 120) . '...' : $record['description']),
                TextColumn::make('author')
                    ->url(fn($state) => "https://modrinth.com/user/$state", true)
                    ->toggleable(),
                TextColumn::make('downloads')
                    ->icon('tabler-download')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('date_modified')
                    ->icon('tabler-calendar')
                    ->formatStateUsing(fn($state) => Carbon::parse($state, 'UTC')->diffForHumans())
                    ->tooltip(fn($state) => Carbon::parse($state, 'UTC')->timezone(user()->timezone ?? 'UTC')->format($table->getDefaultDateTimeDisplayFormat()))
                    ->toggleable(),
            ])
            ->recordUrl(fn(array $record) => "https://modrinth.com/{$record['project_type']}/{$record['slug']}", true)
            ->recordActions([
                Action::make('download')
                    ->schema(function (array $record) {
                        $schema = [];

                        /** @var Server $server */
                        $server = Filament::getTenant();

                        $versions = array_slice(MinecraftModrinth::getModrinthVersions($record['project_id'], $server, $this->showIncompatible), 0, 5);
                        foreach ($versions as $versionData) {
                            $files = $versionData['files'] ?? [];
                            $primaryFile = null;

                            foreach ($files as $fileData) {
                                if ($fileData['primary']) {
                                    $primaryFile = $fileData;
                                    break;
                                }
                            }

                            $schema[] = Section::make($versionData['name'])
                                ->description($versionData['version_number'] . ($primaryFile ? ' (' . convert_bytes_to_readable($primaryFile['size']) . ')' : ' (' . trans('pelican-mc-manager::strings.version.no_file_found') . ')'))
                                ->collapsed(!$versionData['featured'])
                                ->collapsible()
                                ->icon($versionData['version_type'] === 'alpha' ? 'tabler-circle-letter-a' : ($versionData['version_type'] === 'beta' ? 'tabler-circle-letter-b' : 'tabler-circle-letter-r'))
                                ->iconColor($versionData['version_type'] === 'alpha' ? 'danger' : ($versionData['version_type'] === 'beta' ? 'warning' : 'success'))
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('type')
                                        ->badge()
                                        ->color($versionData['version_type'] === 'alpha' ? 'danger' : ($versionData['version_type'] === 'beta' ? 'warning' : 'success'))
                                        ->state($versionData['version_type']),
                                    TextEntry::make('downloads')
                                        ->badge()
                                        ->state($versionData['downloads']),
                                    TextEntry::make('published')
                                        ->badge()
                                        ->state(Carbon::parse($versionData['date_published'], 'UTC')->diffForHumans())
                                        ->tooltip(Carbon::parse($versionData['date_published'], 'UTC')->timezone(user()->timezone ?? 'UTC')->format('M j, Y H:i:s')),
                                    TextEntry::make('changelog')
                                        ->columnSpanFull()
                                        ->markdown()
                                        ->state($versionData['changelog']),
                                ])
                                ->headerActions([
                                    Action::make('download')
                                        ->visible(!is_null($primaryFile))
                                        ->action(function (DaemonFileRepository $fileRepository) use ($server, $versionData, $primaryFile, $record) {
                                            try {
                                                $folder = ModrinthProjectType::fromServer($server)->getFolder();
                                                $fileRepository->setServer($server)->pull($primaryFile['url'], $folder);

                                                // Add ID alias for tracking
                                                $record['id'] = $record['project_id'];
                                                MinecraftModrinth::addInstalledPlugin($server, $record, $versionData, $primaryFile);

                                                Notification::make()
                                                    ->title(trans('pelican-mc-manager::strings.notifications.download_started'))
                                                    ->body($versionData['name'])
                                                    ->success()
                                                    ->send();
                                            } catch (Exception $exception) {
                                                report($exception);

                                                Notification::make()
                                                    ->title(trans('pelican-mc-manager::strings.notifications.download_failed'))
                                                    ->body($exception->getMessage())
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),
                                ]);
                        }

                        return $schema;
                    }),
            ]);
    }

    protected function getHeaderActions(): array
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        $folder = ModrinthProjectType::fromServer($server)->getFolder();

        return [
            Action::make('toggle_view')
                ->label($this->currentView === 'browse' ? trans('pelican-mc-manager::strings.page.view_installed') : trans('pelican-mc-manager::strings.page.browse_modrinth'))
                ->icon($this->currentView === 'browse' ? 'tabler-list' : 'tabler-search')
                ->action(function () {
                    $newView = $this->currentView === 'browse' ? 'installed' : 'browse';
                    $this->redirect(static::getUrl(['currentView' => $newView]));
                }),
            Action::make('open_folder')
                ->label(fn() => trans('pelican-mc-manager::strings.page.open_folder', ['folder' => $folder]))
                ->url(fn() => ListFiles::getUrl(['path' => $folder]), true),
        ];
    }

    public function content(Schema $schema): Schema
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $schema
            ->components([
                Grid::make(3)
                    ->schema([
                        TextEntry::make('minecraft_version')
                            ->label(trans('pelican-mc-manager::strings.page.minecraft_version'))
                            ->state(fn() => MinecraftModrinth::getMinecraftVersion($server) ?? trans('pelican-mc-manager::strings.page.not_installed'))
                            ->badge()
                            ->color(fn($state) => $state === trans('pelican-mc-manager::strings.page.not_installed') ? 'gray' : 'primary'),
                        TextEntry::make('loader')
                            ->label(trans('pelican-mc-manager::strings.page.loader'))
                            ->state(fn() => MinecraftLoader::fromServer($server)?->getLabel() ?? trans('pelican-mc-manager::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(fn() => trans('pelican-mc-manager::strings.page.installed', ['type' => ModrinthProjectType::fromServer($server)->getLabel()]))
                            ->state(function () use ($server) {
                                // Count tracked plugins
                                return count(MinecraftModrinth::getInstalledProjects($server));
                            })
                            ->badge(),
                    ]),
                EmbeddedTable::make(),
            ]);
    }
}
