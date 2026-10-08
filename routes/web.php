<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\Data;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\Principal;
use App\Http\Controllers\Reports\MonthlySummaryController;
use App\Http\Controllers\SuperAdmin;
use Illuminate\Support\Facades\Route;

/*
| Legacy file -> route, for reference:
|   index.php, login.php             -> /, /login
|   teacher-dashboard.php            -> /kiosk
|   save-attendance.php, get-dtr.php -> /kiosk/scan, /kiosk/dtr
|   superadmin/*.php                 -> /superadmin/...
|   admin/*.php, principal/*.php     -> /admin/..., /principal/...
|   dtr/*.php (JSON endpoints)       -> /data/...
*/

Route::redirect('/', '/login');

// ---------------------------------------------------------------- setup + auth

Route::get('/setup', [SetupController::class, 'show'])->name('setup');
Route::post('/setup', [SetupController::class, 'store']);

Route::middleware(['guest', 'setup.done'])->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
    Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])->name('password.forgot');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'submit']);
});

Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ---------------------------------------------------------------- kiosk

Route::middleware('kiosk')->group(function () {
    Route::get('/kiosk', [KioskController::class, 'index'])->name('kiosk');
    Route::post('/kiosk/scan', [KioskController::class, 'scan'])->middleware('throttle:120,1')->name('kiosk.scan');
    Route::get('/kiosk/dtr', [KioskController::class, 'weeklyDtr'])->name('kiosk.dtr');
});

// Local CA certificate for kiosk devices (public: needed before anyone signs in).
Route::get('/certificate', [CertificateController::class, 'show'])->name('certificate');
Route::get('/certificate/download', [CertificateController::class, 'download'])->name('certificate.download');

// Personnel photos: shown on the kiosk after a scan and on the admin pages.
Route::get('/photos/{filename}', [PhotoController::class, 'personnel'])
    ->where('filename', '[A-Za-z0-9._-]+')->name('photos.personnel');

// ---------------------------------------------------------------- signed-in

Route::middleware('auth')->group(function () {

    Route::get('/my-account', [AccountController::class, 'show'])->name('account');
    Route::post('/my-account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::post('/my-account/security-question', [AccountController::class, 'updateSecurityQuestion'])->name('account.security-question');

    // Monthly Summary report (all three roles; was dtr/monthly-summary.php).
    Route::get('/reports/monthly-summary', MonthlySummaryController::class)
        ->middleware('role:superadmin,admin,principal')->name('reports.monthly-summary');

    // ------------------------------------------------------------ Super Admin
    Route::prefix('superadmin')->name('superadmin.')->middleware('role:superadmin')->group(function () {
        Route::get('/dashboard', [SuperAdmin\DashboardController::class, 'show'])->name('dashboard');
        Route::get('/attendance-report', [SuperAdmin\AttendanceReportController::class, 'show'])->name('attendance-report');
        Route::get('/adjustments', [SuperAdmin\AdjustmentsPageController::class, 'show'])->name('adjustments');
        Route::get('/scan-photos', [SuperAdmin\ScanPhotosController::class, 'show'])->name('scan-photos');
        Route::get('/reports', [SuperAdmin\ReportsController::class, 'show'])->name('reports');

        Route::get('/personnel', [SuperAdmin\PersonnelController::class, 'index'])->name('personnel');
        Route::post('/personnel/save', [SuperAdmin\PersonnelController::class, 'save'])->name('personnel.save');
        Route::post('/personnel/archive', [SuperAdmin\PersonnelController::class, 'archive'])->name('personnel.archive');
        Route::post('/personnel/regenerate-barcode', [SuperAdmin\PersonnelController::class, 'regenerateBarcode'])->name('personnel.regenerate-barcode');
        Route::post('/personnel/leave', [SuperAdmin\PersonnelController::class, 'addLeave'])->name('personnel.leave');

        Route::get('/settings', [SuperAdmin\SettingsController::class, 'show'])->name('settings');
        Route::post('/settings/accounts', [SuperAdmin\SettingsController::class, 'createAccount'])->name('settings.accounts.create');
        Route::post('/settings/accounts/archive', [SuperAdmin\SettingsController::class, 'archiveAccount'])->name('settings.accounts.archive');
        Route::post('/settings/accounts/reset-password', [SuperAdmin\SettingsController::class, 'resetPassword'])->name('settings.accounts.reset-password');
        Route::post('/settings/kiosk-devices', [SuperAdmin\KioskDeviceController::class, 'register'])->name('settings.kiosk.register');
        Route::post('/settings/kiosk-devices/revoke', [SuperAdmin\KioskDeviceController::class, 'revoke'])->name('settings.kiosk.revoke');

        Route::redirect('/account-management', '/superadmin/settings');
    });

    // ------------------------------------------------------------ Admin
    Route::prefix('admin')->name('admin.')->middleware('role:admin')->group(function () {
        Route::get('/dashboard', [Admin\DashboardController::class, 'show'])->name('dashboard');
        Route::get('/attendance-report', [Admin\AttendanceReportController::class, 'show'])->name('attendance-report');
        Route::get('/personnel', [Admin\PersonnelController::class, 'show'])->name('personnel');
        Route::get('/reports', [Admin\ReportsController::class, 'show'])->name('reports');
    });

    // ------------------------------------------------------------ Principal
    Route::prefix('principal')->name('principal.')->middleware('role:principal')->group(function () {
        Route::get('/dashboard', [Principal\DashboardController::class, 'show'])->name('dashboard');
        Route::get('/dashboard/scan-verification', Principal\ScanVerificationController::class)->name('scan-verification-counts');
        Route::get('/monitoring', [Principal\MonitoringController::class, 'show'])->name('monitoring');
        Route::get('/personnel', [Principal\PersonnelController::class, 'show'])->name('personnel');
        Route::get('/reports', [Principal\ReportsController::class, 'show'])->name('reports');
    });

    // ------------------------------------------------------------ JSON data (was dtr/*.php)
    Route::prefix('data')->group(function () {

        // Read by the DTR (Super Admin + Admin).
        Route::middleware('role:superadmin,admin')->group(function () {
            Route::get('/recording-status', [Data\DtrDataController::class, 'recordingStatus']);
            Route::get('/school-events', [Data\DtrDataController::class, 'schoolEvents']);
            Route::get('/teacher-schedule', [Data\DtrDataController::class, 'teacherSchedule']);
            Route::get('/teacher-leaves', [Data\DtrDataController::class, 'teacherLeaves']);
            Route::get('/teacher-adjustments', [Data\DtrDataController::class, 'teacherAdjustments']);
            Route::get('/confirmed-absences', [Data\DtrDataController::class, 'confirmedAbsences']);
            Route::get('/dtr-remarks', [Data\DtrDataController::class, 'dtrRemarks']);
            Route::post('/dtr-remarks', [Data\DtrDataController::class, 'saveDtrRemarks']);
            Route::post('/confirm-absent', [Data\ReviewController::class, 'confirmAbsent']);
        });

        Route::middleware('role:superadmin')->group(function () {
            Route::get('/review-summary', [Data\ReviewController::class, 'summary']);
            Route::get('/pending-review', [Data\ReviewController::class, 'pending']);
            Route::post('/review-reminder/dismiss', [Data\ReviewController::class, 'dismissReminder']);
            Route::get('/day-real-scans', [Data\ReviewController::class, 'dayRealScans']);
            Route::get('/rejected-scans', [Data\ReviewController::class, 'rejectedScans']);
            Route::post('/rejected-scans/review', [Data\ReviewController::class, 'reviewRejectedScan']);
            Route::get('/scan-photos', [Data\ReviewController::class, 'scanPhotos']);
            Route::get('/scan-photos/{id}/image', [PhotoController::class, 'scan'])->whereNumber('id')->name('photos.scan');

            Route::get('/adjustments', [Data\AdjustmentController::class, 'index']);
            Route::get('/adjustments/one', [Data\AdjustmentController::class, 'show']);
            Route::post('/adjustments', [Data\AdjustmentController::class, 'store']);
            Route::post('/adjustments/delete', [Data\AdjustmentController::class, 'destroy']);

            Route::post('/school-events', [Data\SchoolEventController::class, 'store']);
            Route::post('/school-events/update', [Data\SchoolEventController::class, 'update']);
            Route::post('/school-events/deactivate', [Data\SchoolEventController::class, 'deactivate']);

            Route::get('/department-options', [Data\DepartmentOptionController::class, 'index']);
            Route::post('/department-options', [Data\DepartmentOptionController::class, 'store']);
            Route::post('/department-options/deactivate', [Data\DepartmentOptionController::class, 'deactivate']);

            Route::post('/settings/lunch-break', [Data\SettingsDataController::class, 'saveLunchBreak']);
            Route::post('/settings/recording', [Data\SettingsDataController::class, 'toggleRecording']);
            Route::get('/recycle-bin', [Data\SettingsDataController::class, 'recycleBin']);
            Route::post('/recycle-bin', [Data\SettingsDataController::class, 'recycleBinAction']);
            Route::get('/backups', [Data\BackupController::class, 'index']);
            Route::post('/backups', [Data\BackupController::class, 'store'])->middleware('throttle:5,1');
            Route::get('/backups/download', [Data\BackupController::class, 'download']);
            Route::get('/audit-log', [Data\SettingsDataController::class, 'auditLog']);
        });
    });
});
