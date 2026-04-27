<?php
/**
 * BothubClient: HTTP client wrapper for BotHub.chat (OpenAI-compatible) API.
 *
 * @author Mikhail Deynekin <mikhail@deynekin.com>
 * @copyright 2026 https://Deynekin.com
 * @version 1.0.0
 * @since 2026-04-27
 */
declare(strict_types=1);

namespace App\Infrastructure\BothubChat;

use App\Infrastructure\LLM\BothubClientInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Class BothubClient
 *
 * Production-ready HTTP client for Bothub.chat API.
 */
final readonly class BothubClient implements BothubClientInterface
{
    private const BASE_URI         = 'https://api.bothub.chat/v1';
    private const CHAT_ENDPOINT    = '/chat/completions';
    private const EMBED_ENDPOINT   = '/embeddings';

    private const MAX_RETRIES_CHAT   = 3;
    private const MAX_RETRIES_EMBED  = 3;
    private const TIMEOUT_CHAT       = 120.0;
    private const TIMEOUT_EMBED      = 30.0;

    public function __construct(
        private string $apiKey,
        private LoggerInterface $logger
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('BothubClient requires a non-empty API key.');
        }
    }

    public function createChatCompletion(string $model, array $messages, ?array $options = null): array
    {
        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
            'max_tokens'  => $options['max_tokens'] ?? null,
            'stream'      => false,
        ];

        $this->logger->info('BothubClient: creating chat completion', [
            'model'          => $model,
            'temperature'    => $payload['temperature'],
            'max_tokens'     => $payload['max_tokens'],
        ]);

        return $this->requestWithRetry(
            method: 'POST',
            endpoint: self::CHAT_ENDPOINT,
            payload: $payload,
            timeout: self::TIMEOUT_CHAT,
            maxRetries: self::MAX_RETRIES_CHAT
        );
    }

    public function createEmbedding(string $model, string $input): array
    {
        $payload = [
            'model' => $model,
            'input' => $input,
        ];

        $this->logger->info('BothubClient: creating embedding', [
            'model' => $model,
            'input_preview' => mb_substr($input, 0, 120),
        ]);

        return $this->requestWithRetry(
            method: 'POST',
            endpoint: self::EMBED_ENDPOINT,
            payload: $payload,
            timeout: self::TIMEOUT_EMBED,
            maxRetries: self::MAX_RETRIES_EMBED
        );
    }

    /**
     * Execute HTTP request with retry and exponential backoff.
     */
    private function requestWithRetry(
        string $method,
        string $endpoint,
        array $payload,
        float $timeout,
        int $maxRetries
    ): array {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                return $this->doRequest($method, $endpoint, $payload, $timeout);
            } catch (RuntimeException $e) {
                $lastException = $e;
                $this->logger->warning('BothubClient: request attempt failed', [
                    'endpoint' => $endpoint,
                    'attempt'  => $attempt,
                    'error'    => $e->getMessage(),
                ]);

                if ($attempt >= $maxRetries) {
                    break;
                }

                $delaySeconds = 2 ** ($attempt - 1);
                usleep($delaySeconds * 1_000_000);
            }
        }

        throw new RuntimeException(
            'BothubClient: all retry attempts failed for endpoint ' . $endpoint,
            0,
            $lastException
        );
    }

    /**
     * Low-level HTTP request using cURL.
     */
    private function doRequest(
        string $method,
        string $endpoint,
        array $payload,
        float $timeout
    ): array {
        $url = rtrim(self::BASE_URI, '/') . $endpoint;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('BothubClient: failed to initialize cURL');
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
        ]);

        $body       = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error      = curl_error($ch);

        curl_close($ch);

        if ($body === false || $error !== '') {
            $this->logger->error('BothubClient: HTTP transport error', [
                'endpoint' => $endpoint,
                'error'    => $error,
            ]);

            throw new RuntimeException('BothubClient: HTTP transport error: ' . $error);
        }

        $this->logger->debug('BothubClient: raw response', [
            'endpoint'    => $endpoint,
            'status_code' => $statusCode,
            'body_preview'=> mb_substr($body, 0, 300),
        ]);

        if ($statusCode === 429) {
            $this->logger->warning('BothubClient: rate limited (429)', [
                'endpoint' => $endpoint,
            ]);

            throw new RuntimeException('BothubClient: rate limited (429)');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('BothubClient: non-success HTTP status', [
                'endpoint'    => $endpoint,
                'status_code' => $statusCode,
                'body'        => $body,
            ]);

            throw new RuntimeException('BothubClient: non-success HTTP status: ' . $statusCode);
        }

        try {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('BothubClient: failed to decode JSON response', [
                'endpoint' => $endpoint,
                'error'    => $e->getMessage(),
                'body'     => $body,
            ]);

            throw new RuntimeException('BothubClient: invalid JSON response', 0, $e);
        }

        return $decoded;
    }
}
