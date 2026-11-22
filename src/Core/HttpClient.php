<?php

declare(strict_types=1);

namespace App\Core;

final class HttpResult
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}

final class ApiException extends \RuntimeException
{
    /**
     * @param array<string,mixed> $errors
     * @param array<string,string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $errors = [],
        string $message = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($message === '' ? 'HTTP ' . $status : $message);
    }
}

/**
 * Small HTTP client used by every external integration (Pterodactyl, Stripe,
 * PayPal, the licence server). Handles timeouts, retries with exponential
 * backoff, Retry-After on 429 and structured logging without secrets.
 */
final class HttpClient
{
    /**
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly array $headers = [],
        private readonly int $timeout = 20,
        private readonly int $retries = 3,
        private readonly string $name = 'http',
    ) {
    }

    /** @param array<string,string|int> $query */
    public function get(string $path, array $query = []): HttpResult
    {
        return $this->request('GET', $path, null, $query);
    }

    /** @param array<string,mixed> $json */
    public function post(string $path, array $json = []): HttpResult
    {
        return $this->request('POST', $path, $json);
    }

    /** @param array<string,mixed> $json */
    public function patch(string $path, array $json = []): HttpResult
    {
        return $this->request('PATCH', $path, $json);
    }

    /** @param array<string,mixed> $json */
    public function put(string $path, array $json = []): HttpResult
    {
        return $this->request('PUT', $path, $json);
    }

    public function delete(string $path): HttpResult
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Form-encoded POST, required by Stripe and PayPal token endpoints.
     *
     * @param array<string,string|int> $form
     */
    public function postForm(string $path, array $form): HttpResult
    {
        return $this->request('POST', $path, null, [], http_build_query($form), 'application/x-www-form-urlencoded');
    }

    /**
     * @param array<string,mixed>|null $json
     * @param array<string,string|int> $query
     */
    public function request(string $method, string $path, ?array $json = null, array $query = [], ?string $rawBody = null, string $contentType = 'application/json'): HttpResult
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $body = $rawBody ?? ($json === null ? null : Json::encode($json));
        $attempt = 0;
        $maxAttempts = max(1, $this->retries);

        while (true) {
            $attempt++;
            $started = microtime(true);
            $response = $this->send($method, $url, $body, $contentType);
            Logger::info('http.request', [
                'client' => $this->name,
                'method' => $method,
                'url' => preg_replace('/[?&](key|token|secret)=[^&]+/', '$1=[redacted]', $url),
                'status' => $response->status,
                'ms' => (int) round((microtime(true) - $started) * 1000),
                'attempt' => $attempt,
            ]);

            $retryable = $response->status === 429 || $response->status >= 500;
            if (!$retryable || $attempt >= $maxAttempts) {
                return $response;
            }
            $retryAfter = (int) ($response->header('Retry-After') ?? 0);
            $delay = $retryAfter > 0 ? min($retryAfter, 30) : min(8, 2 ** ($attempt - 1));
            usleep((int) ($delay * 1000000));
        }
    }

    private function send(string $method, string $url, ?string $body, string $contentType = 'application/json'): HttpResult
    {
        if (!extension_loaded('curl')) {
            throw new ApiException(500, [], 'The PHP cURL extension is required');
        }
        $handle = curl_init($url);
        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        $headers[] = 'Accept: application/json';
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        $responseHeaders = [];

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            unset($handle);
            throw new ApiException(0, [], 'Connection failed: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        return new HttpResult($status, $responseHeaders, (string) $raw);
    }
}
