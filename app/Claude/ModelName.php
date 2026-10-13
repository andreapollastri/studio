<?php

namespace App\Claude;

/**
 * "claude-haiku-4-5-20251001" as people say it: "Haiku 4.5". The label from
 * config/studio.php when the model is listed there, otherwise read off the id.
 */
final class ModelName
{
    public static function label(?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }

        foreach ((array) config('studio.models', []) as $model) {
            if (($model['id'] ?? null) === $id) {
                return (string) $model['label'];
            }
        }

        if (preg_match('/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-\d{8})?$/', $id, $m)) {
            return ucfirst($m[1]).' '.$m[2].(isset($m[3]) ? '.'.$m[3] : '');
        }

        return $id;
    }
}
