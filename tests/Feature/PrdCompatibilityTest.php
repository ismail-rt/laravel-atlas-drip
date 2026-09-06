<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OnboardingDiscountNotification;
use Tests\Support\OnboardingReminderNotification;
use Tests\Support\OnboardingTipsNotification;
use Tests\Support\WeMissYouNotification;
use Tests\User;
use Vendor\Lifecycle\Campaign;
use Vendor\Lifecycle\Facades\Lifecycle;
use Vendor\Lifecycle\Step;

beforeEach(function () {
    Notification::fake();
});

test('exact PRD code example runs seamlessly with Vendor\Lifecycle namespace', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Nathan',
        'email' => 'nathan@example.com',
        'email_verified_at' => $now,
        'is_admin' => false,
    ]);

    // Copy-pasted syntax from PRD Section 6
    Lifecycle::register(
        Campaign::make('onboarding')
            ->priority(10)
            ->eligible(fn ($user) => $user->email_verified_at !== null && ! $user->isAdmin())
            ->anchor(fn ($user) => $user->email_verified_at)
            ->maxEnrollmentAgeDays(10)
            ->cancelWhen(fn ($user) => $user->properties()->exists())
            ->steps([
                Step::make('day3')
                    ->offsetDays(3)
                    ->notification(OnboardingReminderNotification::class),

                Step::make('day5')
                    ->offsetDays(5)
                    ->minimumGapDays(2)
                    ->notification(OnboardingTipsNotification::class),

                Step::make('day7')
                    ->offsetDays(7)
                    ->minimumGapDays(2)
                    ->notification(function ($user) {
                        return new OnboardingDiscountNotification(promoCode: 'WELCOME15');
                    }),
            ])
    );

    // Recurring campaign example from PRD Section 6
    Lifecycle::register(
        Campaign::make('idle_winback')
            ->priority(20)
            ->eligible(fn ($user) => $user->properties()->exists())
            ->anchor(fn ($user) => $user->last_login_at)
            ->instanceKey(fn ($user) => $user->last_login_at?->utc()->format('Y-m-d-H-i-s'))
            ->cancelWhen(fn ($user) => $user->last_login_at?->gt(now()->subDays(7)))
            ->steps([
                Step::make('idle_reminder')
                    ->offsetDays(7)
                    ->notification(WeMissYouNotification::class),
            ])
    );

    // Day 3: First step sends
    Carbon::setTestNow($now->copy()->addDays(3));
    $dispatches = Lifecycle::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['campaign'])->toBe('onboarding');
    expect($dispatches[0]['step'])->toBe('day3');
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);

    // Day 5: Second step sends
    Carbon::setTestNow($now->copy()->addDays(5));
    $dispatches = Lifecycle::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day5');
    Notification::assertSentToTimes($user, OnboardingTipsNotification::class, 1);

    // Day 7: Third step with closure notification payload sends
    Carbon::setTestNow($now->copy()->addDays(7));
    $dispatches = Lifecycle::run();
    expect($dispatches)->toHaveCount(1);
    expect($dispatches[0]['step'])->toBe('day7');
    Notification::assertSentToTimes($user, OnboardingDiscountNotification::class, 1);
});
