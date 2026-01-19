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
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

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
        $projectType = ModrinthProjectType::fromServer($server);
        if (!$projectType) {
            return [];
        }

        $folder = $projectType->getFolder();
        /** @var \App\Repositories\Daemon\DaemonFileRepository $fileRepository */
        $fileRepository = app(\App\Repositories\Daemon\DaemonFileRepository::class);
        $fileRepository->setServer($server);

        try {
            $files = $fileRepository->getDirectory($folder);
        } catch (Exception $e) {
            return [];
        }

        if (isset($files['error'])) {
            return [];
        }

        $installed = [];

        foreach ($files as $file) {
            if ($file['mime'] !== 'application/jar' && !str_ends_with($file['name'], '.jar')) {
                continue;
            }

            $cacheKey = "modrinth_installed_{$server->uuid}_{$file['name']}_{$file['size']}_{$file['created']}"; // Use size/created as simple hash/version check

            $metadata = cache()->remember($cacheKey, now()->addDays(7), function () use ($fileRepository, $file, $folder) {
                // Determine version by inspecting file
                return $this->inspectJarVersion($fileRepository, $folder, $file['name']);
            });
            
            $installed[] = [
                'name' => $file['name'],
                'size' => $file['size'],
                'date_modified' => $file['modified'],
                'version' => $metadata['version'] ?? 'Unknown',
                'description' => $metadata['description'] ?? '',
                'icon_url' => null, // Local files don't have icons easily available
                'project_id' => null, // Not linked to Modrinth yet
                'author' => $metadata['author'] ?? 'Unknown',
                'downloads' => 0,
            ];
        }

        return $installed;
    }

    private function inspectJarVersion(\App\Repositories\Daemon\DaemonFileRepository $repo, string $folder, string $filename): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'mc_plugin_');
        
        try {
            $content = $repo->getObject($folder . '/' . $filename);
            file_put_contents($tempPath, $content);

            $zip = new \ZipArchive();
            if ($zip->open($tempPath) === true) {
                // Check for plugin.yml (Spigot/Paper)
                if (($content = $zip->getFromName('plugin.yml')) !== false) {
                    $data = yaml_parse($content);
                    $zip->close();
                    return [
                        'version' => $data['version'] ?? null,
                        'author' => $data['author'] ?? ($data['authors'][0] ?? null),
                        'description' => $data['description'] ?? null,
                    ];
                }

                // Check for fabric.mod.json
                if (($content = $zip->getFromName('fabric.mod.json')) !== false) {
                    $data = json_decode($content, true);
                    $zip->close();
                    return [
                        'version' => $data['version'] ?? null,
                        'author' => $data['authors'][0] ?? null, // Fabric authors can be array of objects or strings
                        'description' => $data['description'] ?? null,
                    ];
                }

                // Check for bungee.yml
                if (($content = $zip->getFromName('bungee.yml')) !== false) {
                    $data = yaml_parse($content);
                    $zip->close();
                    return [
                        'version' => $data['version'] ?? null,
                        'author' => $data['author'] ?? null,
                        'description' => $data['description'] ?? null,
                    ];
                }

                 // Check for velocity-plugin.json
                 if (($content = $zip->getFromName('velocity-plugin.json')) !== false) {
                    $data = json_decode($content, true);
                    $zip->close();
                    return [
                        'version' => $data['version'] ?? null,
                        'author' => $data['authors'][0] ?? null,
                        'description' => $data['description'] ?? null,
                    ];
                }
                
                $zip->close();
            }
        } catch (Exception $e) {
            // Log error or ignore?
            report($e);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return ['version' => null];
    }
}
