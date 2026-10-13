<?php

namespace App\Mcp;

use App\Infrastructure\ConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Studio calling a remote MCP server itself, over the streamable HTTP transport: `initialize`
 * (and the session it may hand out), then a tool or a resource. For the pages that show what
 * an agent's tools know (the releases of a Cipi app, the anonymizer's audit) without a chat.
 */
final class McpClient
{
    private ?string $session = null;

    private bool $ready = false;

    private int $id = 0;

    /** @param  array<string, string>  $headers */
    public function __construct(private readonly string $url, private readonly array $headers = [], private readonly int $timeout = 60) {}

    /**
     * A tool's answer: its structured content, else the JSON in its first text, else that text.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments = []): mixed
    {
        $result = $this->request('tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);

        if (($result['isError'] ?? false) === true) {
            throw new ConnectorException(Str::limit((string) (data_get($result, 'content.0.text') ?? __('The tool :tool failed.', ['tool' => $tool])), 500));
        }

        if (isset($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        $text = (string) data_get($result, 'content.0.text', '');
        $json = json_decode($text, true);

        return json_last_error() === JSON_ERROR_NONE ? $json : $text;
    }

    /** A resource's text (cipi://anonymization.json…). */
    public function read(string $uri): string
    {
        return (string) data_get($this->request('resources/read', ['uri' => $uri]), 'contents.0.text', '');
    }

    /** @return list<string> the tools' names */
    public function tools(): array
    {
        return array_values(array_filter(array_map(fn ($tool) => is_array($tool) ? ($tool['name'] ?? null) : null, (array) ($this->request('tools/list')['tools'] ?? []))));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function request(string $method, array $params = []): array
    {
        if (! $this->ready) {
            $this->ready = true;
            $this->send('initialize', [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'studio', 'version' => (string) config('studio.version')],
            ]);
            $this->notify('notifications/initialized');
        }

        return $this->send($method, $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function send(string $method, array $params): array
    {
        $response = $this->post(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => (object) $params]);

        if ($response->header('Mcp-Session-Id') !== '') {
            $this->session = $response->header('Mcp-Session-Id');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new ConnectorException(__('The MCP server refused the token (:status).', ['status' => $response->status()]));
        }

        if ($response->status() === 404) {
            throw new ConnectorException(__('No MCP server at :url (404): is it turned on?', ['url' => $this->url]));
        }

        $message = self::message($response);

        if (! is_array($message)) {
            throw new ConnectorException(__('The MCP server answered :status, not JSON-RPC.', ['status' => $response->status()]));
        }

        if (isset($message['error'])) {
            throw new ConnectorException(Str::limit((string) data_get($message, 'error.message', __('MCP error')), 500));
        }

        return (array) ($message['result'] ?? []);
    }

    /** @param  array<string, mixed>  $params */
    private function notify(string $method, array $params = []): void
    {
        try {
            $this->post(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params]);
        } catch (ConnectorException) {
            // a stateless server may not care
        }
    }

    /** @param  array<string, mixed>  $body */
    private function post(array $body): Response
    {
        try {
            return Http::withHeaders([...$this->headers, ...($this->session ? ['Mcp-Session-Id' => $this->session] : [])])
                ->accept('application/json, text/event-stream')
                ->connectTimeout(10)
                ->timeout($this->timeout)
                ->post($this->url, $body);
        } catch (ConnectionException $e) {
            throw new ConnectorException(Str::limit(__('The MCP server is not reachable: :reason', ['reason' => $e->getMessage()]), 300), previous: $e);
        }
    }

    /** The JSON-RPC answer, from a JSON body or from the first event of a stream. */
    public static function message(Response $response): mixed
    {
        if (str_contains((string) $response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split("/\r\n|\r|\n/", $response->body()) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data = json_decode(trim(substr($line, 5)), true);

                    if (is_array($data) && (isset($data['result']) || isset($data['error']))) {
                        return $data;
                    }
                }
            }

            return null;
        }

        return $response->json();
    }
}
