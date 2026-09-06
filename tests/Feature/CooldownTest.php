<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadNotificationLog;
use Lad\Step;
use Tests\Support\OnboardingReminderNotification;
use Tests\Support\WeMissYouNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('global cooldown prevents multiple campaign inbox bombing', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Eve',
        'email' => 'eve@example.com',
        'email_verified_at' => $now->copy()->subDays(3),
        'last_login_at' => $now->copy()->subDays(7),
    ]);

    // Campaign 1: Onboarding (Priority 10)
    Lad::register(
        Campaign::make('onboarding')
            ->priority(10)
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    // Campaign 2: Idle Win-back (Priority 20)
    Lad::register(
        Campaign::make('idle_winback')
            ->priority(20)
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->last_login_at)
            ->steps([
                Step::make('idle_reminder')->offsetDays(7)->notification(WeMissYouNotification::class),
            ])
    );

    // Run 1: User qualifies for both. High-priority Onboarding dispatches.
    // Idle winback must NOT send on the same run (Single send per run limit).
    $dispatches1 = Lad::run();
    expect($dispatches1)->toHaveCount(1);
    expect($dispatches1[0]['campaign'])->toBe('onboarding');
    expect($dispatches1[0]['step'])->toBe('day3');
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);
    Notification::assertNotSentTo($user, WeMissYouNotification::class);

    // 2 hours later (same day): Run 2 executes.
    // User is in 48-hour global cooldown. No email should be sent!
    Carbon::setTestNow($now->copy()->addHours(2));
    $dispatches2 = Lad::run();
    expect($dispatches2)->toBeEmpty();
    Notification::assertNotSentTo($user, WeMissYouNotification::class);

    // 24 hours later: Still within 48h cooldown.
    Carbon::setTestNow($now->copy()->addHours(24));
    $dispatches3 = Lad::run();
    expect($dispatches3)->toBeEmpty();
    Notification::assertNotSentTo($user, WeMissYouNotification::class);

    // 49 hours later: Cooldown has passed (48h default).
    // Now Idle Win-back can send.
    Carbon::setTestNow($now->copy()->addHours(49));
    $dispatches4 = Lad::run();
    expect($dispatches4)->toHaveCount(1);
    expect($dispatches4[0]['campaign'])->toBe('idle_winback');
    expect($dispatches4[0]['step'])->toBe('idle_reminder');
    Notification::assertSentToTimes($user, WeMissYouNotification::class, 1);

    expect(LadNotificationLog::where('recipient_id', $user->id)->count())->toBe(2);
});
