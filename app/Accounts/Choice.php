<?php

namespace App\Accounts;

use App\Models\ClaudeAccount;
use App\Models\ConnectorAccount;
use App\Models\ProjectGroup;

/**
 * The account a person works on a project with, and why that one: chosen for
 * the project, the default of its group, their own default, the only one on
 * the repository's host, or the first that fits.
 *
 * @template TAccount of ClaudeAccount|ConnectorAccount
 */
final readonly class Choice
{
    public const PROJECT = 'project';

    public const GROUP = 'group';

    public const DEFAULT = 'default';

    public const MATCH = 'match';

    /** @param  TAccount  $account */
    public function __construct(
        public ClaudeAccount|ConnectorAccount $account,
        public string $source,
        public ?ProjectGroup $group = null,
    ) {}

    public function isExplicit(): bool
    {
        return $this->source === self::PROJECT;
    }

    /** Why this account, in a few words for the page. */
    public function reason(): string
    {
        return match ($this->source) {
            self::PROJECT => __('chosen for this project'),
            self::GROUP => __('from the group :group', ['group' => (string) $this->group?->name]),
            self::DEFAULT => __('your default'),
            default => $this->account instanceof ConnectorAccount
                ? __('your account on :host', ['host' => (string) $this->account->host])
                : __('your first account'),
        };
    }
}
