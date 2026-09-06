<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Engine Enabled
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) env('LAD_ENABLED', env('LIFECYCLE_ENABLED', true)),

    /*
    |--------------------------------------------------------------------------
    | Global Inter-Campaign Cooldown (Hours)
    |--------------------------------------------------------------------------
    |
    | Prevents cross-campaign inbox bombing. If a recipient received any
    | drip/lifecycle email in the last X hours, subsequent campaign emails are paused.
    |
    */
    'global_cooldown_hours' => (int) env('LAD_COOLDOWN_HOURS', env('LIFECYCLE_COOLDOWN_HOURS', 48)),

    /*
    |--------------------------------------------------------------------------
    | Historical Enrollment Cutoff
    |--------------------------------------------------------------------------
    |
    | Optional timestamp (e.g. '2026-09-01 00:00:00'). Recipients whose anchor
    | is prior to this date are rejected from enrollment unless a prior notification
    | log proves they were already in the campaign.
    |
    */
    'enrollment_started_at' => env('LAD_ENROLLMENT_STARTED_AT', env('LIFECYCLE_ENROLLMENT_STARTED_AT', null)),

    /*
    |--------------------------------------------------------------------------
    | Default Recipient Model
    |--------------------------------------------------------------------------
    */
    'recipient_model' => env('LAD_RECIPIENT_MODEL', env('LIFECYCLE_RECIPIENT_MODEL', 'App\\Models\\User')),

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */
    'table_names' => [
        'states' => env('LAD_TABLE_STATES', 'lad_campaign_states'),
        'logs' => env('LAD_TABLE_LOGS', 'lad_notification_logs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed Unsubscribe Routing
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled' => true,
        'prefix' => 'lad',
        'middleware' => ['web'],
    ],
];
