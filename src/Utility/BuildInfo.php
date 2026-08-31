<?php
declare(strict_types=1);

namespace Amtgard\IdP\Utility;

class BuildInfo
{
    private const VERSION_FILE = __DIR__ . '/../../VERSION';
    private const JSON_FILE = __DIR__ . '/../../version.json';

    public static function getVersion(): string
    {
        $info = self::load();
        $buildId = $info['build_id'] ?? $info['version'] ?? 'unknown';
        $short = $info['short_commit'] ?? null;

        if ($short !== null && $short !== '') {
            return $buildId . '+' . $short;
        }

        return $buildId;
    }

    /**
     * @return array{
     *     version: string,
     *     build_id: string,
     *     date?: string,
     *     revision?: int,
     *     short_commit?: string,
     *     commit?: string,
     *     branch?: string
     * }
     */
    public static function load(): array
    {
        $data = self::readCommittedMetadata();
        $git = self::readGitHead();

        $buildId = $data['build_id'] ?? trim((string) ($data['version'] ?? ''));
        if ($buildId === '') {
            $buildId = 'unknown';
        }

        $short = $git['short_commit'] ?? null;
        $full = $git['commit'] ?? null;

        return [
            'version' => $short !== null && $short !== '' ? $buildId . '+' . $short : $buildId,
            'build_id' => $buildId,
            'date' => $data['date'] ?? null,
            'revision' => isset($data['revision']) ? (int) $data['revision'] : null,
            'short_commit' => $short,
            'commit' => $full,
            'branch' => $git['branch'] ?? ($data['branch'] ?? null),
        ];
    }

    /**
     * @return array{build_id?: string, date?: string, revision?: int, branch?: string, version?: string}
     */
    private static function readCommittedMetadata(): array
    {
        if (is_readable(self::JSON_FILE)) {
            $raw = file_get_contents(self::JSON_FILE);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        if (is_readable(self::VERSION_FILE)) {
            $buildId = trim((string) file_get_contents(self::VERSION_FILE));
            if ($buildId !== '') {
                return ['build_id' => $buildId];
            }
        }

        return [];
    }

    /**
     * @return array{short_commit?: string, commit?: string, branch?: string}
     */
    private static function readGitHead(): array
    {
        $gitDir = dirname(__DIR__, 2) . '/.git';
        if (!is_dir($gitDir) && !is_file($gitDir)) {
            return [];
        }

        $short = self::gitCommand('rev-parse --short HEAD');
        if ($short === null) {
            return [];
        }

        return [
            'short_commit' => $short,
            'commit' => self::gitCommand('rev-parse HEAD'),
            'branch' => self::gitCommand('rev-parse --abbrev-ref HEAD'),
        ];
    }

    private static function gitCommand(string $args): ?string
    {
        $repoRoot = dirname(__DIR__, 2);
        $cmd = 'git -C ' . escapeshellarg($repoRoot) . ' ' . $args . ' 2>/dev/null';
        $output = shell_exec($cmd);
        if ($output === null) {
            return null;
        }

        $value = trim($output);

        return $value === '' ? null : $value;
    }
}
