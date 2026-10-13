<?php

namespace App\Support;

use App\Models\Conversation;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The pictures of a conversation: the screenshots the agent's browser takes and
 * any image a tool hands back, kept on Studio's private disk next to nothing
 * else, under the conversation's uuid. Only the chat's route serves them, to
 * whoever may see that conversation.
 */
final class ConversationImages
{
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Store one image sent by the bridge; null when it is not one Studio keeps.
     *
     * @return array{file: string, type: string, bytes: int}|null
     */
    public static function store(Conversation $conversation, string $mediaType, string $base64): ?array
    {
        $extension = self::TYPES[$mediaType] ?? null;
        $bytes = base64_decode($base64, true);

        if ($extension === null || $bytes === false || $bytes === '' || strlen($bytes) > self::MAX_BYTES || ! self::looksLike($extension, $bytes)) {
            return null;
        }

        $file = Str::lower(Str::random(24)).'.'.$extension;
        Storage::disk('local')->put(self::directory($conversation).'/'.$file, $bytes);

        return ['file' => $file, 'type' => $mediaType, 'bytes' => strlen($bytes)];
    }

    /** The path on the disk of a file of this conversation, or null for a name that is not one of ours. */
    public static function path(Conversation $conversation, string $file): ?string
    {
        if (! preg_match('/^[a-z0-9]{24}\.(png|jpg|webp|gif)$/', $file)) {
            return null;
        }

        $path = self::directory($conversation).'/'.$file;

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    public static function deleteAll(Conversation $conversation): void
    {
        Storage::disk('local')->deleteDirectory(self::directory($conversation));
    }

    private static function directory(Conversation $conversation): string
    {
        return 'conversations/'.$conversation->uuid;
    }

    /** The bytes start the way the type says: a tool cannot slip a page or a script in as a "png". */
    private static function looksLike(string $extension, string $bytes): bool
    {
        return match ($extension) {
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'gif' => str_starts_with($bytes, 'GIF8'),
            'webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }
}
