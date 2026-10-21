<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use phpseclib3\Net\SFTP;

/**
 * An SFTP session on this machine's own disk, for the tests of the SSH file
 * manager and the docs' screenshots: the home is a local folder, commands run
 * in the local shell. With $alias the server shows that folder under another
 * path (/home/shopacme), as a real server would.
 */
class LocalSftp extends SFTP
{
    /** @var list<string> */
    public array $commands = [];

    private int|false $exitStatus = false;

    private string $lastError = '';

    private readonly string $home;

    public function __construct(string $home, private readonly ?string $alias = null)
    {
        parent::__construct('localhost');
        $this->home = realpath($home) ?: $home;
    }

    public function disableStatCache() {}

    public function realpath($path)
    {
        $path = str_starts_with($path, '/') ? $this->local($path) : $this->home.'/'.$path;

        return $this->remote(realpath($path) ?: $path);
    }

    public function rawlist($dir = '.', $recursive = false)
    {
        $dir = $this->local($dir);

        if (! is_dir($dir)) {
            return $this->fail();
        }

        $list = [];

        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.'/'.$name;
            $list[$name] = is_link($path) ? ['type' => 3, 'size' => 0] : $this->attributes($path);
        }

        return $list;
    }

    public function stat($filename)
    {
        $filename = $this->local($filename);

        clearstatcache();

        return file_exists($filename) ? $this->attributes($filename) : $this->fail();
    }

    public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
    {
        $remote_file = $this->local($remote_file);

        if (! is_file($remote_file)) {
            return $this->fail();
        }

        if (is_resource($local_file)) {
            $in = fopen($remote_file, 'rb');
            stream_copy_to_stream($in, $local_file);
            fclose($in);

            return true;
        }

        return file_get_contents($remote_file);
    }

    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $remote_file = $this->local($remote_file);

        if (! is_dir(dirname($remote_file))) {
            return $this->fail();
        }

        if (is_resource($data)) {
            $out = fopen($remote_file, 'wb');
            stream_copy_to_stream($data, $out);
            fclose($out);

            return true;
        }

        return file_put_contents($remote_file, $data) !== false;
    }

    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        $dir = $this->local($dir);

        return @mkdir($dir, 0777, $recursive) ?: $this->fail();
    }

    public function rename($oldname, $newname)
    {
        [$oldname, $newname] = [$this->local($oldname), $this->local($newname)];

        return @rename($oldname, $newname) ?: $this->fail();
    }

    public function delete($path, $recursive = true)
    {
        $path = $this->local($path);

        if (is_dir($path) && ! is_link($path)) {
            return $recursive ? File::deleteDirectory($path) : @rmdir($path);
        }

        return @unlink($path) ?: $this->fail();
    }

    public function exec($command, $callback = null)
    {
        $this->commands[] = $command;
        exec($this->alias === null ? $command : str_replace($this->alias, $this->home, $command), $output, $status);
        $this->exitStatus = $status;

        return implode("\n", $output);
    }

    public function getExitStatus()
    {
        return $this->exitStatus;
    }

    public function getLastSFTPError()
    {
        return $this->lastError;
    }

    private function local(string $path): string
    {
        return $this->alias !== null && ($path === $this->alias || str_starts_with($path, $this->alias.'/')) ? $this->home.substr($path, strlen($this->alias)) : $path;
    }

    private function remote(string $path): string
    {
        return $this->alias !== null && ($path === $this->home || str_starts_with($path, $this->home.'/')) ? $this->alias.substr($path, strlen($this->home)) : $path;
    }

    /** @return array{type: int, size: int, mtime: int} */
    private function attributes(string $path): array
    {
        return ['type' => is_dir($path) ? 2 : (is_file($path) ? 1 : 0), 'size' => is_file($path) ? (int) filesize($path) : 0, 'mtime' => (int) filemtime($path)];
    }

    private function fail(): false
    {
        $this->lastError = 'NET_SFTP_STATUS_NO_SUCH_FILE: No such file';

        return false;
    }
}
