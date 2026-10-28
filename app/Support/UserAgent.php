<?php

namespace App\Support;

/**
 * Browser and operating system names from a User-Agent header, for the
 * "signed in on another device" prompt and the Audit Log. Order matters:
 * Edge, Opera and Samsung Internet also say "Chrome", and Chrome also says
 * "Safari".
 */
class UserAgent
{
    /** @var array<string, string> pattern => name */
    private const BROWSERS = [
        '/Edg(e|A|iOS)?\//' => 'Microsoft Edge',
        '/OPR\/|Opera/' => 'Opera',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/Chrome\/|CriOS\//' => 'Chrome',
        '/Safari\//' => 'Safari',
    ];

    /** @var array<string, string> pattern => name */
    private const SYSTEMS = [
        '/Windows/' => 'Windows',
        '/iPhone|iPad|iPod/' => 'iOS',
        '/Android/' => 'Android',
        '/CrOS/' => 'ChromeOS',
        '/Mac OS X|Macintosh/' => 'macOS',
        '/Linux/' => 'Linux',
    ];

    public static function browser(string $userAgent): string
    {
        return self::match($userAgent, self::BROWSERS, 'an unknown browser');
    }

    public static function operatingSystem(string $userAgent): string
    {
        return self::match($userAgent, self::SYSTEMS, 'an unknown system');
    }

    /** @param  array<string, string>  $patterns */
    private static function match(string $userAgent, array $patterns, string $unknown): string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return $unknown;
    }
}
