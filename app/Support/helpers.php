<?php

if (! function_exists('asset_v')) {
    /**
     * URL of a file in public/ with its modification time appended, so a
     * browser never keeps an old copy of a CSS/JS file after an update.
     */
    function asset_v(string $path): string
    {
        $file = public_path($path);

        return asset($path).(is_file($file) ? '?v='.filemtime($file) : '');
    }
}
