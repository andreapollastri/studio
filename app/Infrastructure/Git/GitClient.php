<?php

namespace App\Infrastructure\Git;

use App\Infrastructure\ApiClient;
use App\Infrastructure\ConnectorException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The API of a git host: an ApiClient that also remembers who it is signed
 * in as (every "assigned to me" asks for it) and sends the bodies that are
 * not plain JSON (Azure DevOps' JSON Patch).
 */
class GitClient extends ApiClient
{
    private readonly string $fingerprint;

    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(string $baseUrl, array $headers = [], int $timeout = 30)
    {
        parent::__construct($baseUrl, $headers, $timeout);

        $this->fingerprint = hash('sha256', $baseUrl.'|'.json_encode($headers));
    }

    /** Something about the signed-in account that does not change between clicks, kept for an hour. */
    public function remember(string $key, Closure $read): mixed
    {
        return Cache::remember('git:'.$this->fingerprint.':'.$key, 3600, $read);
    }

    /**
     * A body of another content type, answered with JSON.
     *
     * @return array<mixed>
     */
    public function sendBody(string $method, string $path, string $body, string $contentType): array
    {
        try {
            $response = $this->request()->withBody($body, $contentType)->send($method, $this->url($path));
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('Not reachable: :reason', ['reason' => $e->getMessage()]), 250), previous: $e);
        }

        if ($response->failed()) {
            throw new ConnectorException($this->error($response));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
