<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * "Forgot your password?" answers the same whether the address belongs to an
 * account or not (Fortify would say "we can't find a user with that email"):
 * the sign-in page of a public dashboard must not tell a stranger which
 * addresses are accounts.
 */
class QuietPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    /** Fortify hands the broker's status over ("sent", "no such user", "throttled"): the answer is the same whatever it says. */
    public function __construct(public readonly string $status = '') {}

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $message = __('If that address belongs to an account, a reset link is on its way.');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
