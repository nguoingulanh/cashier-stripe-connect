<?php

namespace Nguoingulanh\CashierConnect\Tests\Fixtures;

use Stripe\HttpClient\ClientInterface;

/**
 * Captures Stripe SDK requests and answers with canned JSON.
 */
class RecordingHttpClient implements ClientInterface
{
    /** @var list<array{method: string, url: string, headers: array<int, string>, params: array<string, mixed>}> */
    public array $requests = [];

    public function __construct(private array $response = ['id' => 'obj_123', 'object' => 'payment_intent']) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params];

        return [json_encode($this->response), 200, []];
    }

    public function last(): array
    {
        return end($this->requests);
    }

    public function lastHeader(string $name): ?string
    {
        foreach ($this->last()['headers'] as $header) {
            [$key, $value] = array_map('trim', explode(':', $header, 2));

            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
