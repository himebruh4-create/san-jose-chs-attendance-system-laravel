<?php

use App\Domain\Attendance\ScanPhotos;
use App\Models\Account;
use App\Support\AccountRecovery;
use App\Support\Audit;
use App\Support\DatabaseBackup;
use App\Support\KioskHealth;
use App\Support\RecoveryCodes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

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
    $this->info("Super Admin {$account->email} created. They will be asked to set 3 security questions at first login.");

    return 0;
})->purpose('Create a Super Admin account from the command line');

/*
| Last-resort recovery for a Super Admin who forgot both the password and
| the security answers (and has no recovery code). Runs only on the server
| PC itself. The account must set 3 new security questions at next login.
*/
Artisan::command('account:reset-superadmin {email}
    {--generate : Print a random temporary password instead of typing one}
    {--revoke-codes : Also delete the account\'s recovery codes}', function (string $email) {
    $account = Account::query()->active()->where('role', 'superadmin')->where('email', strtolower(trim($email)))->first();

    if (! $account) {
        $this->error('No active Super Admin has that email.');

        return 1;
    }

    if (! $this->confirm("Reset the password of {$account->full_name} ({$account->email})?")) {
        $this->line('Nothing was changed.');

        return 1;
    }

    if ($this->option('generate')) {
        $password = Str::password(14, symbols: false);
    } else {
        $password = (string) $this->secret('New password (at least 8 characters)');

        if (strlen($password) < 8 || $password !== $this->secret('Confirm new password')) {
            $this->error('The passwords are too short or do not match. Nothing was changed.');

            return 1;
        }
    }

    $account->forceFill(['password' => $password, 'remember_token' => null])->save();
    AccountRecovery::afterReset($account, 'console');
    DB::table('sessions')->where('user_id', $account->id)->delete();
    $codesRevoked = $this->option('revoke-codes') ? RecoveryCodes::revoke($account->id) : 0;

    Audit::log('superadmin.password_reset_console', 'account', $account->id, [
        'host' => gethostname() ?: null,
        'os_user' => getenv('USERNAME') ?: (getenv('USER') ?: get_current_user()),
        'recovery_codes_revoked' => (bool) $this->option('revoke-codes'),
    ], actor: 'console');

    $this->info("Password reset for {$account->email}. Signed out everywhere.");

    if ($this->option('generate')) {
        $this->warn("Temporary password (shown once): {$password}");
    }

    $this->line('At the next login they must set 3 new security questions.');
    $this->line($this->option('revoke-codes')
        ? "{$codesRevoked} recovery code(s) deleted."
        : 'Recovery codes were kept (use --revoke-codes to delete them).');

    return 0;
})->purpose('Reset a Super Admin password on the server PC (forgotten password and security answers)');

Artisan::command('kiosk:check-offline', function () {
    $opened = KioskHealth::checkOffline();
    $this->info("{$opened} kiosk offline alert(s) opened.");
})->purpose('Alert the Super Admin and Admin when a kiosk stops checking in during a scan window');

Schedule::command('backup:run')->dailyAt('18:30');
Schedule::command('attendance:prune-scan-photos')->dailyAt('02:00');
Schedule::command('kiosk:check-offline')->everyMinute()->withoutOverlapping();
