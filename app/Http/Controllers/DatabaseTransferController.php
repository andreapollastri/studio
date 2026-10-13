<?php

namespace App\Http\Controllers;

use App\Models\DatabaseTransfer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** The file of an export, for whoever still reaches its connection. */
class DatabaseTransferController extends Controller
{
    public function __invoke(Request $request, DatabaseTransfer $transfer): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($transfer->connection->accessFor($user) !== null && ($transfer->user_id === $user->id || $transfer->connection->isOwnedBy($user) || $user->isAdmin()), 404);
        abort_unless($transfer->hasFile(), 404);

        return response()->download($transfer->absolutePath(), (string) $transfer->file_name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
