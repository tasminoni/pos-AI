<?php
declare(strict_types=1);

/** HMAC authentication for the server-side chat controller's query request. */
final class InternalQueryAuth
{
    public static function isAllowedPeer(string $remoteAddress, string $allowedCsv): bool
    {
        $allowed = array_filter(array_map('trim', explode(',', $allowedCsv)), 'strlen');
        return $remoteAddress !== '' && in_array($remoteAddress, $allowed, true);
    }

    public static function headersFor(string $body, string $secret, ?int $now = null, ?string $nonce = null): array
    {
        self::checkSecret($secret);
        $timestamp = (string)($now ?? time());
        $nonce ??= bin2hex(random_bytes(16));
        if (!preg_match('/^[a-f0-9]{32}$/D', $nonce)) {
            throw new InvalidArgumentException('Internal request nonce is invalid.');
        }
        return [
            'X-Query-Timestamp' => $timestamp,
            'X-Query-Nonce' => $nonce,
            'X-Query-Signature' => hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret),
        ];
    }

    /** Returns the authenticated nonce; throws without revealing the credential. */
    public static function verifySignature(string $body, array $headers, string $secret, int $windowSeconds = 60, ?int $now = null): string
    {
        self::checkSecret($secret);
        $now ??= time();
        $windowSeconds = max(1, min(300, $windowSeconds));
        $timestamp = $headers['X-Query-Timestamp'] ?? '';
        $nonce = $headers['X-Query-Nonce'] ?? '';
        $signature = $headers['X-Query-Signature'] ?? '';
        if (!is_string($timestamp) || !preg_match('/^[0-9]{10,11}$/D', $timestamp)
            || !is_string($nonce) || !preg_match('/^[a-f0-9]{32}$/D', $nonce)
            || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
            || abs($now - (int)$timestamp) > $windowSeconds) {
            throw new RuntimeException('Internal query authentication failed.');
        }
        $expected = hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $body, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Internal query authentication failed.');
        }
        return $nonce;
    }

    /** Replay cache is persisted in the authenticated PHP session. */
    public static function claimNonce(string $nonce, array &$cache, int $windowSeconds = 60, ?int $now = null): void
    {
        $now ??= time();
        $windowSeconds = max(1, min(300, $windowSeconds));
        foreach ($cache as $key => $timestamp) {
            if (!is_int($timestamp) || $timestamp < $now - $windowSeconds) {
                unset($cache[$key]);
            }
        }
        if (isset($cache[$nonce]) || count($cache) >= 128) {
            throw new RuntimeException('Internal query authentication failed.');
        }
        $cache[$nonce] = $now;
    }

    private static function checkSecret(string $secret): void
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('Internal query credential is not configured securely.');
        }
    }
}
