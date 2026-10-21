<?php

namespace App\Ssh;

use App\Infrastructure\ConnectorException;
use App\Models\SshConnection;
use App\Models\User;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Throwable;

/**
 * A file read or written over SFTP on a saved SSH connection, as one person: their own key or
 * password, never the owner's (SshConnection::forGateway), and only on a host whose key was
 * accepted in the SSH terminal before. For the files Studio writes on a server for a project:
 * the anonymizer's config of a Cipi app; open() is also the session of the SSH page's file manager.
 */
class RemoteFiles
{
    public function put(SshConnection $connection, User $user, string $path, string $contents, int $mode = 0640): void
    {
        $sftp = $this->open($connection, $user);
        $dir = dirname($path);

        if ($dir !== '.' && $dir !== '' && ! $sftp->is_dir($dir)) {
            $sftp->mkdir($dir, 0750, true);
        }

        if (! $sftp->put($path, $contents)) {
            throw new ConnectorException(__('Could not write :path on :host.', ['path' => $path, 'host' => $connection->host]));
        }

        $sftp->chmod($mode, $path);
    }

    public function get(SshConnection $connection, User $user, string $path): ?string
    {
        $contents = $this->open($connection, $user)->get($path);

        return is_string($contents) ? $contents : null;
    }

    public function open(SshConnection $connection, User $user): SFTP
    {
        if (! $connection->isVisibleTo($user) || ! $connection->canConnect($user)) {
            throw new ConnectorException(__('You have no credential of your own for :name: set one on the SSH page.', ['name' => $connection->name]));
        }

        if (blank($connection->host_key)) {
            throw new ConnectorException(__('Open :name once in the SSH terminal to accept its host key first.', ['name' => $connection->name]));
        }

        $target = $connection->forGateway($user);

        try {
            $sftp = new SFTP($target['host'], $target['port'], 15);
            $shown = (string) $sftp->getServerPublicHostKey();
            $blob = base64_decode((string) (explode(' ', $shown)[1] ?? ''), true);
            $fingerprint = $blob === false ? '' : 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');

            if (! hash_equals((string) $connection->host_key, $fingerprint)) {
                throw new ConnectorException(__('The host key of :host changed: expected :known, got :seen.', ['host' => $target['host'], 'known' => $connection->host_key, 'seen' => $fingerprint]));
            }

            $secret = $target['auth'] === 'password' ? (string) ($target['password'] ?? '') : PublicKeyLoader::load((string) ($target['private_key'] ?? ''));

            if (! $sftp->login($target['username'], $secret)) {
                throw new ConnectorException(__(':host refused the login of :user.', ['host' => $target['host'], 'user' => $target['username']]));
            }
        } catch (ConnectorException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ConnectorException(__('SFTP to :host failed: :reason', ['host' => $target['host'], 'reason' => $e->getMessage()]), previous: $e);
        }

        return $sftp;
    }
}
