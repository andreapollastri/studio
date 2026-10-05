<?php

namespace App\Mcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Does a remote MCP server answer? One `initialize` call over the streamable
 * HTTP transport, the way Claude Code starts a session: JSON or an event
 * stream back. The answer is a short status fit for the settings page.
 */
final class McpProbe
{
    /**
     * @param  array<string, string>  $headers
     */
    public function check(string $url, array $headers = []): string
    {
        try {
            $response = Http::withHeaders($headers)
                ->accept('application/json, text/event-stream')
                ->connectTimeout(5)
                ->timeout(10)
                ->post($url, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'initialize',
                    'params' => [
                        'protocolVersion' => '2025-06-18',
                        'capabilities' => new \stdClass,
                        'clientInfo' => ['name' => 'studio', 'version' => '0.1'],
                    ],
                ]);
        } catch (ConnectionException $e) {
            return Str::limit(__('not reachable: :reason', ['reason' => $e->getMessage()]), 250);
        }

        if (in_array($response->status(), [401, 403], true)) {
            return __('refused (:status): check the header', ['status' => $response->status()]);
        }

        if ($response->status() === 404) {
            return __('not found (404): check the URL');
        }

        $message = $this->message($response);
        $server = data_get($message, 'result.serverInfo');

        if ($response->successful() && is_array($message) && array_key_exists('result', $message)) {
            return is_array($server) && isset($server['name'])
                ? Str::limit('ok · '.$server['name'].(isset($server['version']) ? ' '.$server['version'] : ''), 120)
                : 'ok';
        }

        return __('answered :status but not as an MCP server', ['status' => $response->status()]);
    }

    /** The JSON-RPC answer, from a JSON body or from the first event of a stream. */
    private function message(Response $response): mixed
    {
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split("/\r\n|\r|\n/", $response->body()) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    return json_decode(trim(substr($line, 5)), true);
                }
            }

            return null;
        }

        return $response->json();
    }
}
