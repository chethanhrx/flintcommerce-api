<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use FlintPHP\Framework\Http\Request;
use FlintPHP\Framework\Http\Response;
use FlintPHP\Framework\Middleware\MiddlewareInterface;

/**
 * Bot detection middleware.
 *
 * Analyzes request patterns to detect automated/bot traffic:
 * - Missing or suspicious User-Agent
 * - Missing Accept headers
 * - Honeypot field detection
 * - Request rate anomalies
 * - Known bot signatures
 */
final class BotDetectionMiddleware implements MiddlewareInterface
{
    private array $suspiciousPatterns;
    private array $blockedUserAgents;
    private bool $blockSuspicious;
    private bool $logDetections;

    public function __construct(
        bool $blockSuspicious = true,
        bool $logDetections = true,
    ) {
        $this->blockSuspicious = $blockSuspicious;
        $this->logDetections = $logDetections;

        $this->suspiciousPatterns = [
            '/bot/i',
            '/crawler/i',
            '/spider/i',
            '/scraper/i',
            '/curl/i',
            '/wget/i',
            '/python/i',
            '/java/i',
            '/go-http/i',
            '/node/i',
            '/phantom/i',
            '/headless/i',
            '/selenium/i',
            '/puppeteer/i',
            '/playwright/i',
        ];

        $this->blockedUserAgents = [
            '',
            'Mozilla/5.0',
            'Mozilla/4.0',
        ];
    }

    public function process(Request $request, callable $next): Response
    {
        // Skip bot detection for authenticated requests (they have valid credentials)
        $authHeader = $request->header('Authorization') ?? '';
        $hasAuth = $authHeader !== '' && (str_starts_with($authHeader, 'Basic ') || str_starts_with($authHeader, 'Bearer '));

        $score = 0;
        $reasons = [];

        // Check User-Agent
        $userAgent = $request->header('User-Agent') ?? '';
        if ($userAgent === '' || in_array(trim($userAgent), $this->blockedUserAgents, true)) {
            $score += 30;
            $reasons[] = 'missing_or_generic_user_agent';
        }

        foreach ($this->suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                $score += 20;
                $reasons[] = 'suspicious_user_agent';
                break;
            }
        }

        // Check Accept header (browsers always send this)
        $accept = $request->header('Accept') ?? '';
        if ($accept === '' && $request->method() === 'GET' && !$hasAuth) {
            $score += 15;
            $reasons[] = 'missing_accept_header';
        }

        // Check for honeypot fields in POST data
        $body = $request->body();
        if ($body !== '' && $body !== null) {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $honeypotFields = ['website', 'url', 'homepage', 'company_url', 'fax', 'phone2'];
                foreach ($honeypotFields as $field) {
                    if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                        $score += 50;
                        $reasons[] = 'honeypot_triggered';
                        break;
                    }
                }
            }
        }

        // Check for missing common browser headers on POST/PUT (only for unauthenticated)
        if (!$hasAuth && in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = $request->header('Content-Type') ?? '';
            if ($contentType === '') {
                $score += 20;
                $reasons[] = 'missing_content_type';
            }
        }

        // Check for suspicious request patterns
        $query = $request->query();
        if (is_array($query) && count($query) > 50) {
            $score += 10;
            $reasons[] = 'excessive_query_params';
        }

        // For authenticated requests, reduce score (they have valid credentials)
        if ($hasAuth) {
            $score = (int) ($score * 0.3);
        }

        // Determine if request should be blocked
        $isBot = $score >= 50;

        if ($isBot && $this->logDetections) {
            error_log(sprintf(
                '[BOT DETECTION] Score: %d, Reasons: %s, IP: %s, UA: %s, Path: %s, Auth: %s',
                $score,
                implode(',', $reasons),
                $request->clientIp() ?? 'unknown',
                substr($userAgent, 0, 100),
                $request->path(),
                $hasAuth ? 'yes' : 'no'
            ));
        }

        if ($isBot && $this->blockSuspicious) {
            return Response::error(
                'Request blocked by bot detection.',
                403,
                'bot_detected'
            );
        }

        $response = $next($request);

        return $response->withHeader('X-Bot-Score', (string) $score);
    }
}