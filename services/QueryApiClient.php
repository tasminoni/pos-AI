<?php
declare(strict_types=1);

require_once __DIR__ . '/InternalQueryAuth.php';

interface ReportQueryClient
{
    public function run(array $plan): array;
}

/** Server-only signed transport to the Phase 2 API. */
final class QueryApiClient implements ReportQueryClient
{
    private $transport;

    public function __construct(private array $config, ?callable $transport = null)
    {
        $this->transport = $transport ?? [$this, 'curlTransport'];
    }

    public function run(array $plan): array
    {
        return $this->send(['plan' => $plan]);
    }

    public function runSql(string $sql, array $filters = [], int $offset = 0): array
    {
        return $this->send(['sql' => $sql, 'filters' => $filters, 'offset'=>$offset]);
    }

    private function send(array $envelope): array
    {
        $url = (string)($this->config['url'] ?? '');
        $parts = parse_url($url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $host = trim($host, '[]');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $allowedHosts = array_map('strtolower', array_map('trim',
            explode(',', (string)($this->config['allowed_hosts'] ?? '127.0.0.1,localhost,::1'))));
        if (!$parts || !str_ends_with($path, '/api/query.php') || str_contains($path, '..')
            || str_contains($path, '%') || str_contains($path, '\\')
            || !in_array($scheme, ['http', 'https'], true)
            || !in_array($host, $allowedHosts, true)
            || ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true))
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Internal query endpoint is not configured safely.');
        }
        if (session_id() === '') {
            throw new RuntimeException('Authenticated session is required for internal query.');
        }
        $body = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signature = InternalQueryAuth::headersFor($body, (string)($this->config['token'] ?? ''));
        $cookieName = session_name();
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $cookieName)) {
            throw new RuntimeException('Session cookie configuration is invalid.');
        }
        $cookie = $cookieName . '=' . rawurlencode(session_id());
        $headers = ['Content-Type: application/json', 'Cookie: ' . $cookie];
        foreach ($signature as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $response = ($this->transport)($url, $headers, $body);
        if (!is_array($response) || !is_int($response['status'] ?? null)
            || !is_string($response['body'] ?? null) || strlen($response['body']) > 2097152) {
            throw new RuntimeException('Internal query response is invalid.');
        }
        $decoded = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !in_array($decoded['status'] ?? null, ['ok', 'error'], true)
            || !is_array($decoded['columns'] ?? null) || !is_array($decoded['rows'] ?? null)
            || !is_int($decoded['row_count'] ?? null) || !is_bool($decoded['truncated'] ?? null)
            || !array_key_exists('error', $decoded)) {
            throw new RuntimeException('Internal query response shape is invalid.');
        }
        if (($response['status'] === 200) !== ($decoded['status'] === 'ok')) {
            throw new RuntimeException('Internal query response status is inconsistent.');
        }
        return $decoded;
    }

    private function curlTransport(string $url, array $headers, string $body): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required for the internal query API.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Internal query HTTP client could not start.');
        }
        $output = '';
        curl_setopt_array($handle, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 70,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$output): int {
                if (strlen($output) + strlen($chunk) > 2097152) { return 0; }
                $output .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            if (curl_exec($handle) === false) {
                throw new RuntimeException('Internal query request failed.');
            }
            return ['status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $output];
        } finally {
            curl_close($handle);
        }
    }
}
