<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Security helpers for input validation and SSRF prevention.
 */
final class Security
{
    /**
     * Check if a URL is safe to fetch (not pointing to internal/private networks).
     */
    public static function isSafeUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || !isset($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];
        $scheme = $parsed['scheme'] ?? '';

        // Only allow http and https
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        // Resolve hostname to IP
        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            // DNS resolution failed - could be a valid hostname that doesn't resolve yet
            // Block to be safe
            return false;
        }

        // Check for private/internal IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Block localhost variants
        $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', '169.254.169.254'];
        if (in_array(strtolower($host), $blockedHosts, true)) {
            return false;
        }

        // Block private IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false) {
                // 10.0.0.0/8
                if (($long & 0xFF000000) === 0x0A000000) return false;
                // 172.16.0.0/12
                if (($long & 0xFFF00000) === 0xAC100000) return false;
                // 192.168.0.0/16
                if (($long & 0xFFFF0000) === 0xC0A80000) return false;
                // 127.0.0.0/8
                if (($long & 0xFF000000) === 0x7F000000) return false;
                // 169.254.0.0/16 (link-local)
                if (($long & 0xFFFF0000) === 0xA9FE0000) return false;
            }
        }

        return true;
    }

    /**
     * Sanitize a string for safe output in JSON (prevents XSS).
     */
    public static function sanitizeString(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Check if a string contains potential SQL injection patterns.
     */
    public static function hasSqlInjectionPattern(string $value): bool
    {
        $patterns = [
            '/\b(union|select|insert|update|delete|drop|alter|create|exec|execute)\b/i',
            '/(--|#|\/\*|\*\/)/',
            '/(\bOR\b|\bAND\b)\s+\d+\s*=\s*\d+/i',
            '/[\'";]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }

        return false;
    }
}