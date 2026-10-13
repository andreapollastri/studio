<?php

namespace App\Http\Controllers;

use App\Drive\Access;
use App\Models\DriveFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A file of the drive, for whoever may read its folder: downloaded, or shown
 * in the browser (?inline=1) when it is an image, a PDF or plain text.
 */
class DriveFileController extends Controller
{
    public function __invoke(Request $request, DriveFile $file): StreamedResponse
    {
        abort_unless((new Access($request->user()))->canReadFile($file), 404);
        abort_unless(Storage::disk('local')->exists($file->disk_path), 404);

        $inline = $request->boolean('inline') && $file->isPreviewable();

        return Storage::disk('local')->response($file->disk_path, $file->name, [
            'Content-Type' => $inline ? (string) $file->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            // Shown in the browser, an uploaded file runs nothing and frames nothing.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ], $inline ? 'inline' : 'attachment');
    }
}
