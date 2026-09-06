<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Services\NotificationDedupeService;
use Lad\Step;
use Tests\Support\OnboardingReminderNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('atomic duplicate prevention on race condition results in exactly 1 send', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Charlie',
        'email' => 'charlie@example.com',
        'email_verified_at' => $now->copy()->subDays(3),
    ]);

    $campaign = Campaign::make('onboarding')
        ->eligible(fn ($u) => true)
        ->anchor(fn ($u) => $u->email_verified_at)
        ->steps([
            Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
        ]);

    Lad::register($campaign);

    $dedupeService = app(NotificationDedupeService::class);
    $step = $campaign->getStep('day3');

    // Worker 1 dispatches successfully
    $log1 = $dedupeService->sendOnce($campaign, $step, $user, $now);
    expect($log1)->not->toBeNull();
    expect($log1)->toBeInstanceOf(LadNotificationLog::class);

    // Worker 2 attempts simultaneous dispatch with same dedupe key
    $log2 = $dedupeService->sendOnce($campaign, $step, $user, $now);
    expect($log2)->toBeNull(); // Caught duplicate constraint and handled gracefully

    // Assert only 1 database record exists
    expect(LadNotificationLog::where('recipient_id', $user->id)->count())->toBe(1);

    // Assert only 1 notification sent
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);
});

test('scheduler run ignores already sent dedupe keys safely', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Dana',
        'email' => 'dana@example.com',
        'email_verified_at' => $now->copy()->subDays(3),
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    // First run sends Day 3
    $dispatches1 = Lad::run();
    expect($dispatches1)->toHaveCount(1);

    // Immediately re-run: step is already sent
    $dispatches2 = Lad::run();
    expect($dispatches2)->toBeEmpty();

    expect(LadNotificationLog::where('recipient_id', $user->id)->count())->toBe(1);
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);
});

test('concurrent enrollment race condition resolves safely without duplicate crash', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Concurrent Enrollment User',
        'email' => 'concurrent@example.com',
        'email_verified_at' => $now->copy()->subDays(3),
    ]);

    $campaign = Campaign::make('onboarding')
        ->eligible(fn ($u) => true)
        ->anchor(fn ($u) => $u->email_verified_at)
        ->steps([
            Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
        ]);

    Lad::register($campaign);

    // Simulate worker 1 having created the active state in the database
    LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'instance_key' => 'default',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $user->email_verified_at,
    ]);

    // Worker 2 evaluates and should handle the state seamlessly
    $result = Lad::evaluateRecipient($user, now: $now);
    expect($result)->not->toBeNull();
    expect($result['step'])->toBe('day3');

    // Still only 1 state row exists
    expect(LadCampaignState::where('recipient_id', $user->id)->count())->toBe(1);
});
