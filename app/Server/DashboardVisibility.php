<?php

namespace App\Server;

use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Whether studio.<domain> shows itself to anyone. Public (the default), it shows the sign-in
 * page as any app does. Hidden, a visitor who is not signed in gets the page of a name nobody
 * configured, on every path, and the welcome page of those names no longer links to Studio:
 * nothing says a Studio lives here. People get in through the door, a secret link that leaves
 * a cookie in their browser and opens the sign-in page; a session, once signed in, is enough.
 * Rotating the door's key closes every door handed out until then.
 */
final class DashboardVisibility
{
    public const COOKIE = 'studio_door';

    private const HIDDEN = 'dashboard.hidden';

    private const DOOR = 'dashboard.door';

    /** A year: the door is a link people are given once, with their first password. */
    public const COOKIE_MINUTES = 525600;

    private ?bool $hidden = null;

    public function hidden(): bool
    {
        return $this->hidden ??= (bool) Setting::get(self::HIDDEN, false);
    }

    public function setHidden(bool $hidden): void
    {
        Setting::put(self::HIDDEN, $hidden);
        $this->hidden = $hidden;

        if ($hidden) {
            $this->doorKey();
        }
    }

    /** The secret of the door link, made the first time it is needed. */
    public function doorKey(): string
    {
        $key = (string) Setting::get(self::DOOR, '');

        if ($key === '') {
            $key = $this->rotateDoor();
        }

        return $key;
    }

    /** A new key: every door link and every door cookie given until now stops working. */
    public function rotateDoor(): string
    {
        $key = Str::lower(Str::random(32));
        Setting::put(self::DOOR, $key);

        return $key;
    }

    public function doorUrl(): string
    {
        return route('door', ['key' => $this->doorKey()]);
    }

    public function opens(string $key): bool
    {
        return $key !== '' && hash_equals($this->doorKey(), $key);
    }

    /** What the cookie holds: derived from the key, so that the key itself never sits in a browser. */
    public function cookieValue(): string
    {
        return hash_hmac('sha256', 'door', $this->doorKey());
    }

    public function cookieOpens(mixed $value): bool
    {
        return is_string($value) && $value !== '' && hash_equals($this->cookieValue(), $value);
    }
}
