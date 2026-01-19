<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\MinecraftLoader;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Exception;
use Illuminate\Support\Facades\Http;

class MinecraftModrinthService
{
    public function getMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        if (!$version || $version === 'latest') {
            return config('minecraft-modrinth.latest_minecraft_version');
        }

        return $version;
    }

    /** @return array{hits: array<int, array<string, mixed>>, total_hits: int} */
    public function getModrinthProjects(Server $server, int $page = 1, ?string $search = null): array
    {
        $projectType = ModrinthProjectType::fromServer($server)?->value;
        $minecraftLoader = MinecraftLoader::fromServer($server)?->value;

        if (!$projectType || !$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => "[[\"categories:$minecraftLoader\"],[\"versions:$minecraftVersion\"],[\"project_type:{$projectType}\"]]",
        ];

        $key = "modrinth_projects:{$projectType}:$minecraftVersion:$minecraftLoader:$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ":$search";
        }

        return cache()->remember($key, now()->addMinutes(30), function () use ($data) {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/search', $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [
                    'hits' => [],
                    'total_hits' => 0,
                ];
            }
        });
    }

    /** @return array<int, mixed> */
    public function getModrinthVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = MinecraftLoader::fromServer($server)?->value;

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);

        $data = [
            'game_versions' => "[\"$minecraftVersion\"]",
            'loaders' => "[\"$minecraftLoader\"]",
        ];

        return cache()->remember("modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader", now()->addMinutes(30), function () use ($projectId, $data) {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get("https://api.modrinth.com/v2/project/$projectId/version", $data)
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }
    /** @return array<int, array<string, mixed>> */
    public function getInstalledProjects(Server $server): array
    {
        $lockFile = $this->getLockFileContent($server);
        return array_values($lockFile['plugins'] ?? []);
    }

    public function addInstalledPlugin(Server $server, array $project, array $version, array $file): void
    {
        $lockFile = $this->getLockFileContent($server);

        // Calculate relative path for storage
        $projectType = ModrinthProjectType::fromServer($server);
        $folder = $projectType ? $projectType->getFolder() : '';
        $filePath = $folder . '/' . $file['filename'];

        $lockFile['plugins'][$project['id']] = [
            'name' => $project['title'],
            'project_id' => $project['id'],
            'version_id' => $version['id'],
            'version_number' => $version['version_number'],
            'file_name' => $file['filename'],
            'file_path' => $filePath, // Storing path allows future support for server jars etc.
            'size' => $file['size'] ?? 0,
            'date_installed' => now()->toIso8601String(),
            'icon_url' => $project['icon_url'],
            'description' => $project['description'],
            'primary' => true, // Flag to identify this is the main logical entry
        ];

        $this->saveLockFile($server, $lockFile);
    }

    public function getAvailableUpdates(Server $server): array
    {
        $installed = $this->getInstalledProjects($server);
        $updates = [];

        foreach ($installed as $plugin) {
            $versions = $this->getModrinthVersions($plugin['project_id'], $server);

            if (empty($versions)) {
                continue;
            }

            // Assume first version is latest compatible due to filters in getModrinthVersions
            $latestVersion = $versions[0];

            if ($latestVersion['id'] !== $plugin['version_id']) {
                $updates[$plugin['project_id']] = $latestVersion;
            }
        }

        return $updates;
    }

    public function updateInstalledPlugin(Server $server, string $projectId, array $newVersion): void
    {
        $lockFile = $this->getLockFileContent($server);

        if (!isset($lockFile['plugins'][$projectId])) {
            throw new Exception("Plugin not found in lock file.");
        }

        $currentPlugin = $lockFile['plugins'][$projectId];

        /** @var \App\Repositories\Daemon\DaemonFileRepository $fileRepository */
        $fileRepository = app(\App\Repositories\Daemon\DaemonFileRepository::class);
        $fileRepository->setServer($server);

        // 1. Delete old file
        if (isset($currentPlugin['file_path'])) {
            try {
                $fileRepository->deleteFiles('/', [$currentPlugin['file_path']]);
            } catch (\Throwable $e) {
                // Ignore deletion errors (file might be already gone), but log it
                report($e);
            }
        }

        // 2. Find primary file for new version
        $primaryFile = null;
        foreach ($newVersion['files'] as $file) {
            if ($file['primary']) {
                $primaryFile = $file;
                break;
            }
        }

        if (!$primaryFile) {
            // Fallback to first file if no primary
            $primaryFile = $newVersion['files'][0] ?? null;
        }

        if (!$primaryFile) {
            throw new Exception("No file found for this version.");
        }

        // 3. Download new file
        $folder = ModrinthProjectType::fromServer($server)->getFolder();
        $fileRepository->pull($primaryFile['url'], $folder);

        // 4. Update lock entry
        $projectData = [
            'id' => $currentPlugin['project_id'],
            'title' => $currentPlugin['name'],
            'icon_url' => $currentPlugin['icon_url'],
            'description' => $currentPlugin['description'],
        ];

        $this->addInstalledPlugin($server, $projectData, $newVersion, $primaryFile);
    }
    public function deleteInstalledPlugin(Server $server, string $projectId): void
    {
        $lockFile = $this->getLockFileContent($server);

        if (!isset($lockFile['plugins'][$projectId])) {
            throw new Exception("Plugin not found in lock file.");
        }

        $currentPlugin = $lockFile['plugins'][$projectId];

        /** @var \App\Repositories\Daemon\DaemonFileRepository $fileRepository */
        $fileRepository = app(\App\Repositories\Daemon\DaemonFileRepository::class);
        $fileRepository->setServer($server);

        // Delete file
        if (isset($currentPlugin['file_path'])) {
            try {
                \Illuminate\Support\Facades\Log::info("MinecraftModrinth: Attempting to delete file: " . $currentPlugin['file_path']);
                // deleteFiles expects ($root, $files)
                $fileRepository->deleteFiles('/', [$currentPlugin['file_path']]);
            } catch (\Throwable $e) {
                // Ignore deletion errors (file might be already gone), but log it
                report($e);
            }
        }

        unset($lockFile['plugins'][$projectId]);
        $this->saveLockFile($server, $lockFile);
    }

    protected function getLockFileContent(Server $server): array
    {
        /** @var \App\Repositories\Daemon\DaemonFileRepository $fileRepository */
        $fileRepository = app(\App\Repositories\Daemon\DaemonFileRepository::class);
        $fileRepository->setServer($server);

        try {
            $content = $fileRepository->getContent('modrinth.lock.json');
            return json_decode($content, true) ?? ['plugins' => []];
        } catch (Exception $e) {
            return ['plugins' => []];
        }
    }

    protected function saveLockFile(Server $server, array $content): void
    {
        /** @var \App\Repositories\Daemon\DaemonFileRepository $fileRepository */
        $fileRepository = app(\App\Repositories\Daemon\DaemonFileRepository::class);
        $fileRepository->setServer($server);

        try {
            $fileRepository->putContent('modrinth.lock.json', json_encode($content, JSON_PRETTY_PRINT));
        } catch (Exception $e) {
            report($e);
        }
    }
}
