<?php

namespace App\Claude;

use App\Models\PermissionRequest;
use App\Models\PermissionRule;
use App\Models\Project;
use App\Models\User;

/**
 * What Claude Code runs without asking, beyond what its permission mode lets
 * through: the role's own list (config studio.allowed_tools) and the rules the
 * person added with "Always allow" on a permission card, for one project. Every
 * turn carries them as --allowedTools, in Claude Code's own rule syntax.
 */
final class AllowRules
{
    /** Runners whose first words name the command: php artisan migrate, npm run build, composer install. */
    private const RUNNERS = [
        'php artisan' => 3, 'npm run' => 3, 'pnpm run' => 3, 'yarn run' => 3, 'bun run' => 3,
        'composer' => 2, 'npm' => 2, 'pnpm' => 2, 'yarn' => 2, 'bun' => 2, 'npx' => 2, 'git' => 2,
    ];

    /** @return list<string> the role's rules, then the person's own on this project */
    public static function forTurn(User $user, Project $project): array
    {
        $own = PermissionRule::query()->where('user_id', $user->id)->where('project_id', $project->id)->orderBy('id')->pluck('rule')->all();

        return array_values(array_unique([...self::forRole($user, $project), ...array_map('strval', $own)]));
    }

    /** @return list<string> the everyday commands of the person's role on the project (config studio.allowed_tools) */
    public static function forRole(User $user, Project $project): array
    {
        $role = $user->roleIn($project) ?? $user->role;

        return array_values(array_map('strval', (array) config('studio.allowed_tools.'.$role->value, [])));
    }

    /**
     * The rule "Always allow" adds for this request: the one Claude Code suggested, otherwise
     * one Studio derives for a plain command; null when nothing narrow enough fits (a compound
     * shell line, a file edit: for those, a mode lets them through).
     */
    public static function suggest(PermissionRequest $request): ?string
    {
        foreach ($request->suggestions ?? [] as $rule) {
            if (self::valid($rule)) {
                return $rule;
            }
        }

        if ($request->tool_name === 'Bash') {
            return self::forCommand((string) ($request->input['command'] ?? ''));
        }

        // a tool of an MCP server is one action: allowing it means that tool, nothing wider
        return str_starts_with($request->tool_name, 'mcp__') ? $request->tool_name : null;
    }

    /** Bash(npm run build:*) for "npm run build --watch"; null for a line with shell syntax. */
    public static function forCommand(string $command): ?string
    {
        $command = trim($command);

        if ($command === '' || preg_match('/[;&|<>`$\n\\\\]|\(|\)/', $command)) {
            return null;
        }

        $words = preg_split('/\s+/', $command) ?: [];
        $take = self::RUNNERS[implode(' ', array_slice($words, 0, 2))] ?? self::RUNNERS[$words[0]] ?? null;

        if ($take === null) {
            // a project's own binaries (vendor/bin/pest): the binary, any arguments; anything else exactly as asked
            $take = preg_match('#^(\./)?(vendor/bin|node_modules/\.bin)/[\w.-]+$#', $words[0]) ? 1 : count($words);
        }

        return 'Bash('.implode(' ', array_slice($words, 0, $take)).':*)';
    }

    /** What a person reads for a rule: "npm run build" for Bash(npm run build:*). */
    public static function label(string $rule): string
    {
        if (preg_match('/^Bash\((.+?)(:\*| \*)?\)$/', $rule, $m)) {
            return $m[1].(isset($m[2]) ? ' …' : '');
        }

        return $rule;
    }

    /** Claude Code's rule syntax, kept to what --allowedTools takes safely as one argument. */
    public static function valid(string $rule): bool
    {
        return strlen($rule) <= 500 && preg_match('/^[A-Za-z][\w-]*(\([^()\n]+\))?$/', $rule) === 1;
    }
}
