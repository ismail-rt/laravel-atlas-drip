<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Step;
use Tests\Support\OnboardingReminderNotification;
use Tests\Support\OnboardingTipsNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('historical cutoff skips older users unless prior notification log exists', function () {
    $cutoff = Carbon::parse('2026-09-01 00:00:00');
    Config::set('lad.enrollment_started_at', '2026-09-01 00:00:00');

    $now = Carbon::parse('2026-09-05 10:00:00');
    Carbon::setTestNow($now);

    // Old user verified on 2026-08-15 (prior to cutoff)
    $oldUser = User::create([
        'name' => 'Old User',
        'email' => 'old@example.com',
        'email_verified_at' => Carbon::parse('2026-08-15 10:00:00'),
    ]);

    // New user verified on 2026-09-02 (after cutoff)
    $newUser = User::create([
        'name' => 'New User',
        'email' => 'new@example.com',
        'email_verified_at' => Carbon::parse('2026-09-02 10:00:00'),
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    $dispatches = Lad::run();

    // New user was verified 3 days ago (2026-09-02 -> 2026-09-05) and gets Day 3
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['recipient']->id)->toBe($newUser->id);
    Notification::assertSentToTimes($newUser, OnboardingReminderNotification::class, 1);

    // Old user was ignored and not enrolled
    Notification::assertNotSentTo($oldUser, OnboardingReminderNotification::class);
    expect(LadCampaignState::where('recipient_id', $oldUser->id)->count())->toBe(0);
});

test('older user with prior log continues through remaining steps', function () {
    Config::set('lad.enrollment_started_at', '2026-09-01 00:00:00');

    $anchor = Carbon::parse('2026-08-20 10:00:00');
    $now = Carbon::parse('2026-09-05 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Existing Campaign User',
        'email' => 'existing@example.com',
        'email_verified_at' => $anchor,
    ]);

    // User had already received day3 before
    LadNotificationLog::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'step' => 'day3',
        'dedupe_key' => "lad:onboarding:day3:{$user->id}",
        'sent_at' => Carbon::parse('2026-08-23 10:00:00'),
    ]);

    LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $anchor,
        'last_step' => 'day3',
        'last_sent_at' => Carbon::parse('2026-08-23 10:00:00'),
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
                Step::make('day5')->offsetDays(5)->minimumGapDays(2)->notification(OnboardingTipsNotification::class),
            ])
    );

    // Day 5 is due and sends because prior log exists
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day5');
    Notification::assertSentToTimes($user, OnboardingTipsNotification::class, 1);
});

test('max enrollment age failsafe prevents catch up blast on old accounts', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    // User verified 30 days ago
    $staleUser = User::create([
        'name' => 'Stale User',
        'email' => 'stale@example.com',
        'email_verified_at' => $now->copy()->subDays(30),
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->maxEnrollmentAgeDays(10) // Failsafe: max 10 days old for initial enrollment
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();
    Notification::assertNothingSent();
    expect(LadCampaignState::where('recipient_id', $staleUser->id)->count())->toBe(0);
});
