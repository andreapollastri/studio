<?php

namespace App\Http\Controllers;

use App\Files\FileStoreException;
use App\Models\SshConnection;
use App\Ssh\SftpFiles;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file of a server downloaded from the SSH page's file manager, streamed
 * over SFTP as it comes, with the person's own key or password. The path is
 * absolute on the server. A folder goes as a zip: compress it first.
 */
class SshFileController extends Controller
{
    public function __invoke(Request $request, SshConnection $connection): StreamedResponse
    {
        abort_unless($connection->isVisibleTo($request->user()), 404);

        $path = (string) $request->query('path', '');
        $files = app(SftpFiles::class, ['connection' => $connection, 'user' => $request->user(), 'root' => '/']);

        try {
            $size = $files->fileSize($path);
        } catch (FileStoreException $e) {
            abort(in_array($e->getCode(), [400, 403, 404], true) ? 404 : 502, $e->getMessage());
        }

        return response()->streamDownload(function () use ($files, $path) {
            $out = fopen('php://output', 'wb');

            if ($out !== false) {
                $files->download($path, $out);
                fclose($out);
            }
        }, basename($path), [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => $size,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
