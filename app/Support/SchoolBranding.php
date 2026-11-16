<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The school's name and logo, set by the Super Admin in Settings -> School
 * Name & Logo and stored in system_settings (id 1). Every page, printout and
 * badge reads them from here (Blade: $school, shared by AppServiceProvider;
 * scripts: window.SCHOOL, set by the layout). An empty setting falls back to
 * the built-in default.
 *
 * The full name is for printouts, badges and the login page; the short name
 * for the sidebar and the browser tab titles.
 *
 * An uploaded logo is re-encoded with GD (so a file that merely looks like
 * an image never reaches the server as uploaded), kept as PNG so a
 * transparent logo stays transparent, and stored on the private disk. It is
 * served by GET /school-logo (public: the login page shows it).
 */
class SchoolBranding
{
    public const DEFAULT_NAME = 'San Jose Community High School';

    public const DEFAULT_SHORT_NAME = 'San Jose CHS';

    /** Under public/: the logo shown until one is uploaded. */
    public const DEFAULT_LOGO = 'img/logo.png';

    public const LOGO_DIR = 'school-logo';

    /** Accepted logo uploads (validated on the server). */
    public const LOGO_MIMES = ['png', 'jpg', 'jpeg', 'webp'];

    public const LOGO_MAX_KILOBYTES = 2048;

    /** A stored logo is at most this many pixels on its longer side. */
    private const LOGO_MAX_PIXELS = 512;

    public const NAME_MAX = 120;

    public const SHORT_NAME_MAX = 40;

    /**
     * Read once per request (kept on the request, so the next request
     * always sees a just-saved change).
     *
     * @return array{name: string, short_name: string, logo_url: string, logo_path: ?string, custom_logo: bool}
     */
    public static function current(): array
    {
        $attributes = request()->attributes;

        if (! $attributes->has(self::class)) {
            $attributes->set(self::class, self::load());
        }

        return $attributes->get(self::class);
    }

    /** @return array{name: string, short_name: string, logo_url: string, logo_path: ?string, custom_logo: bool} */
    private static function load(): array
    {
        // Before the migration has run (or with no settings row) the
        // defaults still give every page a name and a logo.
        $row = rescue(
            fn () => DB::table('system_settings')->where('id', 1)->first(['school_name', 'school_short_name', 'school_logo_path']),
            null,
            false,
        );

        $logoPath = $row?->school_logo_path;
        $customLogo = $logoPath !== null && $logoPath !== '' && Storage::disk('local')->exists($logoPath);

        return [
            'name' => self::filled($row?->school_name) ?? self::DEFAULT_NAME,
            'short_name' => self::filled($row?->school_short_name) ?? self::DEFAULT_SHORT_NAME,
            // The file name changes with every upload, so the URL does too
            // and the browser never shows a cached old logo.
            'logo_url' => $customLogo
                ? route('school-logo', ['v' => pathinfo($logoPath, PATHINFO_FILENAME)])
                : asset(self::DEFAULT_LOGO),
            'logo_path' => $customLogo ? $logoPath : null,
            'custom_logo' => $customLogo,
        ];
    }

    public static function name(): string
    {
        return self::current()['name'];
    }

    public static function shortName(): string
    {
        return self::current()['short_name'];
    }

    public static function logoUrl(): string
    {
        return self::current()['logo_url'];
    }

    /** What scripts get as window.SCHOOL. */
    public static function forScripts(): array
    {
        return array_intersect_key(self::current(), array_flip(['name', 'short_name', 'logo_url']));
    }

    /** One line, single spaces. */
    public static function cleanName(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Re-encodes an uploaded logo and stores it.
     *
     * @return string|null the stored path, or null when the file is not a readable PNG, JPEG or WebP image
     */
    public static function storeLogo(UploadedFile $file): ?string
    {
        $tmpPath = $file->getRealPath();
        $info = $tmpPath ? @getimagesize($tmpPath) : false;

        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        $source = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($tmpPath),
            'image/png' => @imagecreatefrompng($tmpPath),
            'image/webp' => @imagecreatefromwebp($tmpPath),
        };

        if (! $source) {
            return null;
        }

        [$width, $height] = [$info[0], $info[1]];
        $ratio = min(1, self::LOGO_MAX_PIXELS / $width, self::LOGO_MAX_PIXELS / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $image = imagecreatetruecolor($newWidth, $newHeight);

        // Keep transparency (a logo is usually drawn on no background).
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();

        $path = self::LOGO_DIR.'/logo_'.Str::uuid().'.png';
        Storage::disk('local')->put($path, $png);

        return $path;
    }

    /**
     * Saves the names and, when given, the new logo (removing the old
     * logo's file).
     */
    public static function save(string $name, string $shortName, ?string $newLogoPath = null): void
    {
        $update = ['school_name' => $name, 'school_short_name' => $shortName];
        $oldLogoPath = null;

        if ($newLogoPath !== null) {
            $oldLogoPath = DB::table('system_settings')->where('id', 1)->value('school_logo_path');
            $update['school_logo_path'] = $newLogoPath;
        }

        // The settings row may be missing (an imported database without
        // one); it is created then, with the other settings' defaults.
        DB::table('system_settings')->updateOrInsert(['id' => 1], $update);

        if ($oldLogoPath && $oldLogoPath !== $newLogoPath) {
            Storage::disk('local')->delete($oldLogoPath);
        }
    }

    private static function filled(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
