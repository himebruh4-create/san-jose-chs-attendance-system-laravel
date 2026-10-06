<?php

use App\Domain\Attendance\ScanPhotos;
use App\Models\Account;
use App\Support\Audit;
use App\Support\DatabaseBackup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Maintenance commands. The schedule below runs when Windows Task Scheduler
| (or cron) calls `php artisan schedule:run` every minute — see README.
*/

Artisan::command('backup:run {--keep=30 : Number of backups to keep}', function () {
    $result = DatabaseBackup::create();

    if (! $result['ok']) {
        $this->error($result['message']);

        return 1;
    }

    $removed = DatabaseBackup::prune((int) $this->option('keep'));
    Audit::log('backup.created', details: ['file' => $result['backup']['filename'], 'scheduled' => true], actor: 'system');

    $this->info("Backup {$result['backup']['filename']} ({$result['backup']['size_display']}) created; {$removed} old backup(s) removed.");

    return 0;
})->purpose('Create a verified database backup and keep only the newest ones');

Artisan::command('attendance:prune-scan-photos {--days='.ScanPhotos::RETENTION_DAYS.'}', function () {
    $deleted = ScanPhotos::prune((int) $this->option('days'));
    $this->info("{$deleted} kiosk photo(s) older than {$this->option('days')} days deleted.");
})->purpose('Delete kiosk webcam photos past the retention period');

Artisan::command('account:create-superadmin {email} {--name=Super Admin}', function (string $email) {
    if (Account::query()->where('email', $email)->exists()) {
        $this->error('That email is already in use.');

        return 1;
    }

    $password = $this->secret('Password (at least 8 characters)');

    if (strlen((string) $password) < 8 || $password !== $this->secret('Confirm password')) {
        $this->error('The passwords are too short or do not match.');

        return 1;
    }

    $account = Account::create([
        'full_name' => $this->option('name'),
        'email' => strtolower($email),
        'password' => $password,
        'role' => 'superadmin',
    ]);

    Audit::log('setup.superadmin_created', 'account', $account->id, actor: 'console');
    $this->info("Super Admin {$account->email} created. Set a security question in My Account so Forgot Password works.");

    return 0;
})->purpose('Create a Super Admin account from the command line');

Schedule::command('backup:run')->dailyAt('18:30');
Schedule::command('attendance:prune-scan-photos')->dailyAt('02:00');
