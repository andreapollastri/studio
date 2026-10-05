<?php

namespace App\Git\Providers;

/** What a webhook delivery means for Studio: a ping, a push to some branches, or nothing to do. */
final readonly class WebhookEvent
{
    /** @param list<string> $branches */
    private function __construct(public string $type, public array $branches = []) {}

    public static function ping(): self
    {
        return new self('ping');
    }

    /** @param list<string> $branches */
    public static function push(array $branches): self
    {
        return new self('push', array_values(array_unique(array_filter($branches, fn (string $b) => $b !== ''))));
    }

    public static function ignored(): self
    {
        return new self('ignored');
    }

    public function pushes(string $branch): bool
    {
        return $this->type === 'push' && in_array($branch, $this->branches, true);
    }

    /** `refs/heads/main` → `main`; tags and other refs → null. */
    public static function branchOf(mixed $ref): ?string
    {
        return is_string($ref) && str_starts_with($ref, 'refs/heads/') ? substr($ref, 11) : null;
    }
}
