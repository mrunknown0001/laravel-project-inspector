<?php

declare(strict_types=1);

namespace LaraInspect\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Mock HTTP client that serves queued responses and records every request.
 * Running out of responses fails the test, so unexpected calls are caught.
 */
final class RecordingHttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}> */
    public array $requests = [];

    /** @var list<MockResponse> */
    private array $queue;

    public readonly MockHttpClient $client;

    public function __construct(MockResponse ...$responses)
    {
        $this->queue = array_values($responses);
        $this->client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $headers = [];
            foreach ($options['headers'] ?? [] as $line) {
                if (is_string($line) && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
            $body = isset($options['body']) && is_string($options['body']) && $options['body'] !== '' ? json_decode($options['body'], true) : null;
            $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => is_array($body) ? $body : null];

            $response = array_shift($this->queue);
            if ($response === null) {
                throw new \LogicException(sprintf('Unexpected HTTP request: %s %s', $method, $url));
            }

            return $response;
        });
    }

    /**
     * @param array<string, mixed>       $body
     * @param array<string, list<string>|string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($body), ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json'] + $headers]);
    }

    public static function timeout(): MockResponse
    {
        return new MockResponse((static function (): \Generator {
            yield '';
        })());
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}
     */
    public function last(): array
    {
        $last = end($this->requests);
        if ($last === false) {
            throw new \LogicException('No request was sent.');
        }

        return $last;
    }
}
