<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /** {success: true, ...} — the shape every page script expects. */
    protected function ok(array $data = []): JsonResponse
    {
        return response()->json(['success' => true] + $data);
    }

    /** {success: false, message} with HTTP 200, as the native-PHP endpoints answered. */
    protected function fail(string $message, int $status = 200, array $data = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message] + $data, $status);
    }

    /** A Y-m-d string, or null. */
    protected function date(?string $value): ?string
    {
        $value = trim((string) $value);
        $d = \DateTime::createFromFormat('Y-m-d', $value);

        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
