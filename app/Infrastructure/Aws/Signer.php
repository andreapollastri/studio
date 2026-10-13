<?php

namespace App\Infrastructure\Aws;

use DateTimeInterface;

/**
 * AWS Signature Version 4: the Authorization header every AWS API wants.
 * https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class Signer
{
    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly ?string $sessionToken = null,
    ) {}

    /**
     * The headers to send with the request, the signature included.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    public function sign(string $method, string $url, string $region, string $service, array $headers = [], string $body = '', ?DateTimeInterface $at = null): array
    {
        $time = $at?->getTimestamp() ?? time();
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $date = gmdate('Ymd', $time);
        $parts = parse_url($url) ?: [];
        $payloadHash = hash('sha256', $body);

        $headers = [
            ...$headers,
            'host' => ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'x-amz-date' => $amzDate,
        ];

        if ($service === 's3') {
            $headers['x-amz-content-sha256'] = $payloadHash;
        }

        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $headers['x-amz-security-token'] = $this->sessionToken;
        }

        $canonical = [];

        foreach ($headers as $name => $value) {
            $canonical[strtolower($name)] = preg_replace('/\s+/', ' ', trim($value));
        }

        ksort($canonical);
        $signedHeaders = implode(';', array_keys($canonical));
        $canonicalHeaders = implode('', array_map(fn ($name, $value) => $name.':'.$value."\n", array_keys($canonical), $canonical));

        $request = implode("\n", [
            strtoupper($method),
            $this->canonicalPath($parts['path'] ?? '/', $service),
            $this->canonicalQuery($parts['query'] ?? ''),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $date.'/'.$region.'/'.$service.'/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n".hash('sha256', $request);

        $key = hash_hmac('sha256', $date, 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        unset($headers['host']);

        return [
            ...$headers,
            'Authorization' => "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }

    /** Every segment encoded; twice for every service but S3, as SigV4 asks. */
    private function canonicalPath(string $path, string $service): string
    {
        if ($path === '') {
            return '/';
        }

        if ($service === 's3') {
            return $path;
        }

        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];

        foreach (explode('&', $query) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }

        usort($pairs, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return implode('&', array_map(fn ($pair) => $pair[0].'='.$pair[1], $pairs));
    }
}
