<?php

namespace App\Http\Controllers\Data;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\DatabaseBackup;
use Illuminate\Http\Request;

/**
 * Database backups (Super Admin). Ported from superadmin/create-database-backup.php,
 * list-database-backups.php and download-database-backup.php.
 */
class BackupController extends Controller
{
    public function index()
    {
        return $this->ok(['backups' => DatabaseBackup::list()]);
    }

    public function store()
    {
        $result = DatabaseBackup::create();

        if (! $result['ok']) {
            return $this->fail($result['message']);
        }

        Audit::log('backup.created', details: ['file' => $result['backup']['filename']]);

        return $this->ok(['message' => 'Database backup created successfully.', 'backup' => $result['backup']]);
    }

    public function download(Request $request)
    {
        $path = DatabaseBackup::resolve($request->query('file'));

        if ($path === null) {
            abort(404, 'Backup not found.');
        }

        Audit::log('backup.downloaded', details: ['file' => basename($path)]);

        return response()->download($path, basename($path), ['Content-Type' => 'application/sql']);
    }
}
