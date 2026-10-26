<?php

declare(strict_types=1);

namespace App\Sending;

/**
 * Peer strings as people paste them: "@name", "name", "t.me/name",
 * "https://t.me/name/", "https://t.me/+invite", "-100123...", "+998 90 123-45-67".
 * One spelling per peer, so the same chat is not written to twice.
 *
 * A phone number needs the "+": bare digits are a Telegram id.
 */
final class Peers
{
    private const USERNAME = '/^[A-Za-z][A-Za-z0-9_]{3,31}$/';

    private const PHONE = '/^\+\d{7,15}$/';

    public static function normalize(string $peer): string
    {
        $peer = trim($peer, " \t\n\r\0\x0B/");
        if ($peer === '') {
            return '';
        }

        if (str_starts_with($peer, '+') && preg_match('/^\+[\d\s\-().]{7,24}$/', $peer) === 1) {
            return '+'.preg_replace('/\D+/', '', $peer);
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?(?:t|telegram)\.me/([A-Za-z][A-Za-z0-9_]{3,31})$~i', $peer, $m) === 1) {
            return '@'.$m[1];
        }

        if (preg_match(self::USERNAME, ltrim($peer, '@')) === 1 && ! str_contains($peer, '/')) {
            return '@'.ltrim($peer, '@');
        }

        return $peer;
    }

    /**
     * @param  iterable<string>  $peers
     * @return list<string> normalized, non-empty, unique (usernames compared case-insensitively)
     */
    public static function unique(iterable $peers): array
    {
        $seen = [];
        foreach ($peers as $peer) {
            $normalized = self::normalize((string) $peer);
            if ($normalized === '') {
                continue;
            }
            $key = str_starts_with($normalized, '@') ? mb_strtolower($normalized) : $normalized;
            $seen[$key] ??= $normalized;
        }

        return array_values($seen);
    }

    /** A normalized peer that is a phone number ("+998901234567"). */
    public static function isPhone(string $peer): bool
    {
        return preg_match(self::PHONE, $peer) === 1;
    }

    /** @return list<string> one peer per line, comma or semicolon */
    public static function parse(string $text): array
    {
        return self::unique(preg_split('/[\r\n,;]+/', $text) ?: []);
    }
}
