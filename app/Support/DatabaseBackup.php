<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Full database backups with mysqldump. Ported from
 * superadmin/database-backup-core.php, with the same safeguards:
 * no shell, nothing user-supplied on the command line, the password passed
 * through the environment only, a strict generated file-name pattern, and
 * an integrity check of every dump.
 *
 * Backups live in storage/app/private/backups — inside the application but
 * outside public/, so the web server never serves them.
 */
class DatabaseBackup
{
    public static function database(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database');
    }

    /** Exactly the names this feature generates; the only names listed or downloadable. */
    private static function namePattern(): string
    {
        return '/^'.preg_quote(self::database(), '/').'_full_backup_\d{8}_\d{6}\.sql\z/';
    }

    public static function directory(bool $create = false): ?string
    {
        $dir = storage_path('app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'backups');

        if (! is_dir($dir) && (! $create || ! @mkdir($dir, 0775, true))) {
            return null;
        }

        $real = realpath($dir);
        $public = realpath(public_path());

        // Never inside the web root.
        if ($real === false || ($public && stripos($real.DIRECTORY_SEPARATOR, rtrim($public, '\\/').DIRECTORY_SEPARATOR) === 0)) {
            return null;
        }

        return $real;
    }

    public static function mysqldumpPath(): ?string
    {
        if ($configured = config('database.mysqldump_path')) {
            return is_file($configured) ? $configured : null;
        }

        $candidates = [];

        try {
            $basedir = DB::selectOne('SELECT @@basedir AS basedir')->basedir ?? null;
            if ($basedir) {
                $candidates[] = $basedir;
            }
        } catch (\Throwable) {
        }

        $candidates[] = dirname(PHP_BINARY).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'mysql';   // XAMPP layout

        foreach ($candidates as $base) {
            foreach (['mysqldump.exe', 'mysqldump'] as $exe) {
                $path = rtrim($base, '\\/').DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.$exe;
                if (is_file($path)) {
                    return realpath($path) ?: $path;
                }
            }
        }

        // Linux / macOS: mysqldump on the PATH.
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump'] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' bytes';
    }

    /** Backups made by this feature, newest first. */
    public static function list(): array
    {
        $dir = self::directory();
        $backups = [];

        foreach ($dir ? (@scandir($dir) ?: []) : [] as $name) {
            if (! preg_match(self::namePattern(), $name)) {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$name;

            if (! is_file($path) || is_link($path)) {
                continue;
            }

            $size = (int) filesize($path);
            $mtime = (int) filemtime($path);

            $backups[] = [
                'filename' => $name,
                'size' => $size,
                'size_display' => self::formatSize($size),
                'created_at' => date('M j, Y g:i A', $mtime),
                'timestamp' => $mtime,
            ];
        }

        usort($backups, fn ($a, $b) => ($b['timestamp'] <=> $a['timestamp']) ?: strcmp($b['filename'], $a['filename']));

        return $backups;
    }

    /** A client-supplied NAME -> real path inside the backup folder, or null. */
    public static function resolve(?string $requestedName): ?string
    {
        $dir = self::directory();

        if ($dir === null || ! is_string($requestedName) || ! preg_match(self::namePattern(), $requestedName)) {
            return null;
        }

        foreach (self::list() as $backup) {
            if ($backup['filename'] === $requestedName) {
                $path = realpath($dir.DIRECTORY_SEPARATOR.$requestedName);

                return ($path !== false && is_file($path) && dirname($path) === $dir) ? $path : null;
            }
        }

        return null;
    }

    /** @return array{ok: bool, message?: string, backup?: array} */
    public static function create(): array
    {
        $database = self::database();
        $mysqldump = self::mysqldumpPath();

        if ($mysqldump === null) {
            Log::error('database backup: mysqldump executable not found');

            return ['ok' => false, 'message' => 'The backup tool could not be found on the server.'];
        }

        $dir = self::directory(true);

        if ($dir === null || ! is_writable($dir)) {
            Log::error('database backup: backup directory unavailable or not writable');

            return ['ok' => false, 'message' => 'The backup folder is not available or is not writable.'];
        }

        @set_time_limit(300);

        // Never overwrite an existing backup.
        $name = null;
        for ($i = 0, $stamp = time(); $i < 60; $i++, $stamp++) {
            $candidate = $database.'_full_backup_'.date('Ymd_His', $stamp).'.sql';
            if (! file_exists($dir.DIRECTORY_SEPARATOR.$candidate)) {
                $name = $candidate;
                break;
            }
        }

        if ($name === null) {
            return ['ok' => false, 'message' => 'The database backup could not be created. Please try again.'];
        }

        $finalPath = $dir.DIRECTORY_SEPARATOR.$name;
        $tempPath = $finalPath.'.tmp';   // never matches the listing pattern

        $connection = config('database.connections.'.config('database.default'));

        $command = [
            $mysqldump,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file='.$tempPath,
            '--databases',
            $database,
        ];

        $env = null;
        if (($connection['password'] ?? '') !== '') {
            $env = array_merge(getenv(), ['MYSQL_PWD' => $connection['password']]);
        }

        $process = @proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);

        if (! is_resource($process)) {
            Log::error('database backup: could not start mysqldump');

            return ['ok' => false, 'message' => 'The backup tool could not be started.'];
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0 || ! is_file($tempPath)) {
            Log::error('database backup: mysqldump exit '.$exitCode.' — '.trim($stderr.' '.$stdout));
            @unlink($tempPath);

            return ['ok' => false, 'message' => 'The database backup could not be completed.'];
        }

        if (! self::verify($tempPath, $database)) {
            Log::error('database backup: verification of the generated dump failed');
            @unlink($tempPath);

            return ['ok' => false, 'message' => 'The backup file failed its integrity check and was discarded.'];
        }

        if (file_exists($finalPath) || ! @rename($tempPath, $finalPath)) {
            Log::error('database backup: could not move the finished dump into place');
            @unlink($tempPath);

            return ['ok' => false, 'message' => 'The backup file could not be saved.'];
        }

        $size = (int) filesize($finalPath);

        return ['ok' => true, 'backup' => [
            'filename' => $name,
            'size' => $size,
            'size_display' => self::formatSize($size),
            'created_at' => date('M j, Y g:i A', (int) filemtime($finalPath)),
        ]];
    }

    /** The dump ends with mysqldump's completion marker and has every live table. */
    private static function verify(string $path, string $database): bool
    {
        $size = filesize($path);

        if ($size === false || $size < 100) {
            return false;
        }

        $expected = (int) DB::selectOne(
            "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_type = 'BASE TABLE'",
            [$database]
        )->c;

        $fh = fopen($path, 'rb');
        if (! $fh) {
            return false;
        }

        fseek($fh, max(0, $size - 300));
        $tail = stream_get_contents($fh);

        if (! str_contains($tail, '-- Dump completed')) {
            fclose($fh);

            return false;
        }

        rewind($fh);

        $tables = 0;
        $hasUse = false;
        $use = 'USE `'.$database.'`';

        while (($line = fgets($fh)) !== false) {
            if (strncmp($line, 'CREATE TABLE ', 13) === 0) {
                $tables++;
            } elseif (strncmp($line, $use, strlen($use)) === 0) {
                $hasUse = true;
            }
        }

        fclose($fh);

        return $hasUse && $expected > 0 && $tables === $expected;
    }

    /** Deletes the oldest backups beyond $keep. */
    public static function prune(int $keep): int
    {
        $dir = self::directory();
        $deleted = 0;

        foreach (array_slice(self::list(), $keep) as $old) {
            if ($dir && @unlink($dir.DIRECTORY_SEPARATOR.$old['filename'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
