<?php

namespace App\Support;

/**
 * Whether this Studio can send email at all. The installer leaves
 * MAIL_MAILER=log, so a fresh server writes every message to its log and the
 * "forgot your password" link would promise an email nobody receives: the
 * pages say so, until whoever runs the server fills the MAIL_* lines of
 * /opt/studio/shared/.env (see the Email section of the docs).
 */
final class Mail
{
    public static function configured(): bool
    {
        return ! in_array(config('mail.default'), [null, '', 'log', 'array'], true);
    }
}
