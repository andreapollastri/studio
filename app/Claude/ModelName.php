<?php

namespace App\Claude;

/**
 * "claude-haiku-5-5" as people say it: "Haiku 5.5". The label from
 * config/studio.php when the model is listed there, otherwise read off the id.
 */
final class ModelName
{
    public static function label(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach ([...(array) config('studio.models', []), ...(array) config('studio.cursor_models', [])] as $model) {
            if (($model['id'] ?? null) === $id) {
                return (string) $model['label'];
            }
        }

        // opus / sonnet / haiku aliases, and dated ids like claude-haiku-4-5-20251001
        if (preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $id, $m)) {
            return ucfirst($m[1]).' '.$m[2].(isset($m[3]) ? '.'.$m[3] : '');
        }

        if (in_array($id, ['opus', 'sonnet', 'haiku', 'best', 'fable'], true)) {
            return ucfirst($id);
        }

        return $id;
    }
}
