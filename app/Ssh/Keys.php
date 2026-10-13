<?php

namespace App\Ssh;

use InvalidArgumentException;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * SSH keys for the terminal, through phpseclib: new ones (Ed25519, RSA 4096)
 * or a person's own, in any format OpenSSH and PuTTY write, with or without a
 * passphrase. Either way the private key is kept in OpenSSH format without
 * the passphrase (the column is encrypted), so the gateway can use it as is.
 */
final class Keys
{
    public const TYPES = ['ed25519', 'rsa'];

    /**
     * @return array{type: string, bits: int|null, public_key: string, private_key: string, fingerprint: string}
     */
    public static function generate(string $type, string $comment): array
    {
        $key = match ($type) {
            'ed25519' => EC::createKey('Ed25519'),
            'rsa' => RSA::createKey(4096),
            default => throw new InvalidArgumentException("Unknown key type {$type}."),
        };

        return self::describe($key, $comment);
    }

    /**
     * @return array{type: string, bits: int|null, public_key: string, private_key: string, fingerprint: string}
     *
     * @throws InvalidArgumentException when it is not a private key, the passphrase is wrong, or the type is not supported
     */
    public static function import(string $privateKey, ?string $passphrase, string $comment): array
    {
        $text = str_replace("\r\n", "\n", trim($privateKey))."\n";

        try {
            $key = PublicKeyLoader::loadPrivateKey($text, (string) $passphrase);
        } catch (Throwable) {
            throw new InvalidArgumentException(filled($passphrase)
                ? __('The key could not be read: check the passphrase, and that this is the private key.')
                : __('The key could not be read: paste the private key (it may need its passphrase).'));
        }

        if (! $key instanceof RSA\PrivateKey && ! $key instanceof EC\PrivateKey) {
            throw new InvalidArgumentException(__('Only RSA, Ed25519 and ECDSA keys are supported.'));
        }

        return self::describe($key, $comment);
    }

    /**
     * @return array{type: string, bits: int|null, public_key: string, private_key: string, fingerprint: string}
     */
    private static function describe(PrivateKey $key, string $comment): array
    {
        /** @var RSA\PrivateKey|EC\PrivateKey $key */
        $key = $key->withPassword(false);
        $public = $key->getPublicKey();

        [$type, $bits] = match (true) {
            $key instanceof RSA\PrivateKey => ['rsa', $key->getLength()],
            $key->getCurve() === 'Ed25519' => ['ed25519', null],
            default => ['ecdsa', $key->getLength()],
        };

        return [
            'type' => $type,
            'bits' => $bits,
            'public_key' => trim((string) $public->toString('OpenSSH', ['comment' => $comment])),
            'private_key' => (string) $key->toString('OpenSSH', ['comment' => $comment]),
            'fingerprint' => 'SHA256:'.$public->getFingerprint('sha256'),
        ];
    }
}
