<?php

namespace App\Http\Controllers\Principal;

use App\Domain\Attendance\ScanVerificationCounts;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Read-only counts for the Principal dashboard's Scan Verification card,
 * refreshed by the page every few minutes. Takes no parameters.
 */
class ScanVerificationController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(ScanVerificationCounts::get())
            ->header('Cache-Control', 'no-store');
    }
}
