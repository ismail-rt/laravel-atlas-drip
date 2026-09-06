<?php

namespace Lad\Contracts;

interface RecipientInterface
{
    /**
     * Determine if the recipient has opted out of drip/lifecycle marketing emails.
     */
    public function hasOptedOutOfLifecycle(): bool;

    /**
     * Alias for hasOptedOutOfLifecycle.
     */
    public function hasOptedOutOfDrip(): bool;
}
