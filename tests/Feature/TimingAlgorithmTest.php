<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Step;
use Tests\Support\OnboardingDiscountNotification;
use Tests\Support\OnboardingReminderNotification;
use Tests\Support\OnboardingTipsNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('sequential steps send with proper delay', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'email_verified_at' => $now,
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->priority(10)
            ->eligible(fn ($u) => $u->email_verified_at !== null)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
                Step::make('day5')->offsetDays(5)->minimumGapDays(2)->notification(OnboardingTipsNotification::class),
                Step::make('day7')->offsetDays(7)->minimumGapDays(2)->notification(OnboardingDiscountNotification::class),
            ])
    );

    // Day 1: Not due yet
    Carbon::setTestNow($now->copy()->addDays(1));
    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();
    expect(LadNotificationLog::count())->toBe(0);

    // Day 3: Step 1 sends
    Carbon::setTestNow($now->copy()->addDays(3));
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day3');
    Notification::assertSentTo($user, OnboardingReminderNotification::class);

    $state = LadCampaignState::where('recipient_id', $user->id)->first();
    expect($state->last_step)->toBe('day3');
    expect($state->isActive())->toBeTrue();

    // Day 4: Step 2 not due yet
    Carbon::setTestNow($now->copy()->addDays(4));
    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();

    // Day 5: Step 2 sends
    Carbon::setTestNow($now->copy()->addDays(5));
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day5');
    Notification::assertSentTo($user, OnboardingTipsNotification::class);

    // Day 7: Step 3 sends and completes the campaign
    Carbon::setTestNow($now->copy()->addDays(7));
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day7');
    Notification::assertSentTo($user, OnboardingDiscountNotification::class);

    $state->refresh();
    expect($state->isCompleted())->toBeTrue();
    expect($state->completed_at)->not->toBeNull();
});

test('delayed previous step pushes next step forward via two clock formula', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'email_verified_at' => $now,
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->priority(10)
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
                Step::make('day5')->offsetDays(5)->minimumGapDays(2)->notification(OnboardingTipsNotification::class),
            ])
    );

    // Simulate Day 3 being delayed and only sending on Day 4
    Carbon::setTestNow($now->copy()->addDays(4));
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day3');

    // On Day 5 (1 day after Day 4 send):
    // Anchor + 5 days = Day 5
    // But last_sent + minimum_gap (2 days) = Day 6
    // due_at = max(Day 5, Day 6) = Day 6
    Carbon::setTestNow($now->copy()->addDays(5));
    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();
    Notification::assertNotSentTo($user, OnboardingTipsNotification::class);

    // On Day 6: Now due!
    Carbon::setTestNow($now->copy()->addDays(6));
    $dispatches = Lad::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day5');
    Notification::assertSentTo($user, OnboardingTipsNotification::class);
});
