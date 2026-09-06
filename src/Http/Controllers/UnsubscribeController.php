<?php

namespace Lad\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Lad\Services\LadEngine;

class UnsubscribeController extends Controller
{
    /**
     * Handle the signed unsubscribe request.
     */
    public function unsubscribe(Request $request, string|int $recipient, LadEngine $engine)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired unsubscribe link.');
        }

        $modelClass = config('lad.recipient_model', 'App\\Models\\User');
        $user = class_exists($modelClass) ? $modelClass::find($recipient) : null;

        if ($user !== null) {
            $engine->optOutRecipient($user);
        } else {
            $engine->cancelRecipient($recipient, null, 'opt_out');
        }

        return view('lad::unsubscribe', [
            'recipient' => $user,
            'recipientId' => $recipient,
            'status' => 'unsubscribed',
        ]);
    }

    /**
     * Allow recipient to opt back in if they changed their mind.
     */
    public function resubscribe(Request $request, string|int $recipient)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired link.');
        }

        $modelClass = config('lad.recipient_model', 'App\\Models\\User');
        $user = class_exists($modelClass) ? $modelClass::find($recipient) : null;

        if ($user !== null) {
            $attributesToReset = [
                'marketing_emails_opted_out_at' => null,
            ];

            if ($user->getAttribute('lifecycle_opted_out_at') !== null) {
                $attributesToReset['lifecycle_opted_out_at'] = null;
            }

            if ($user->getAttribute('drip_opted_out_at') !== null) {
                $attributesToReset['drip_opted_out_at'] = null;
            }

            $user->forceFill($attributesToReset)->save();
        }

        return view('lad::unsubscribe', [
            'recipient' => $user,
            'recipientId' => $recipient,
            'status' => 'resubscribed',
        ]);
    }
}
