<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Personnel ID photos: resized to at most 800x800 and always re-encoded with
 * GD (so a file that merely looks like an image never reaches the server
 * as uploaded), stored on the private disk under personnel-photos/.
 */
class PersonnelPhotos
{
    public const DIR = 'personnel-photos';

    /** @return string|null the stored file name */
    public static function store(string $tmpPath, int $max = 800): ?string
    {
        $info = @getimagesize($tmpPath);

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
        $ratio = min(1, $max / $width, $max / $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $image = imagecreatetruecolor($newWidth, $newHeight);

        // Flatten transparency onto white (badges are printed on white).
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = ob_get_clean();

        imagedestroy($image);
        imagedestroy($source);

        $name = 'teacher_'.Str::uuid().'.jpg';
        Storage::disk('local')->put(self::DIR.'/'.$name, $jpeg);

        return $name;
    }

    public static function delete(?string $name): void
    {
        if ($name) {
            Storage::disk('local')->delete(self::DIR.'/'.basename($name));
        }
    }
}
