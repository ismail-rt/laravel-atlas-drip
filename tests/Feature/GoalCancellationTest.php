<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Events\CampaignCancelled;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Step;
use Tests\Property;
use Tests\Support\OnboardingReminderNotification;
use Tests\Support\OnboardingTipsNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
    Event::fake([CampaignCancelled::class]);
});

test('goal completion permanently cancels active campaign and never re-enrolls', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Frank',
        'email' => 'frank@example.com',
        'email_verified_at' => $now,
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->priority(10)
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            // Goal: user creates a property/project
            ->cancelWhen(fn ($u) => $u->properties()->exists())
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
                Step::make('day5')->offsetDays(5)->minimumGapDays(2)->notification(OnboardingTipsNotification::class),
            ])
    );

    // Day 3: User hasn't created property yet, Day 3 sends
    Carbon::setTestNow($now->copy()->addDays(3));
    $dispatches1 = Lad::run();
    expect($dispatches1)->toHaveCount(1);
    expect($dispatches1[0]['step'])->toBe('day3');
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);

    $state = LadCampaignState::where('recipient_id', $user->id)->first();
    expect($state->isActive())->toBeTrue();

    // Day 4: User achieves goal!
    Property::create(['user_id' => $user->id, 'title' => 'My First Project']);

    // Day 5: Run executes. Goal achieved, state transitions to 'cancelled' and no email sent!
    Carbon::setTestNow($now->copy()->addDays(5));
    $dispatches2 = Lad::run();
    expect($dispatches2)->toBeEmpty();
    Notification::assertNotSentTo($user, OnboardingTipsNotification::class);

    $state->refresh();
    expect($state->isCancelled())->toBeTrue();
    expect($state->isTerminal())->toBeTrue();
    expect($state->cancelled_at)->not->toBeNull();

    Event::assertDispatched(CampaignCancelled::class);

    // Day 6: Subsequent run - terminal state is skipped, never restarts or re-enrolls
    Carbon::setTestNow($now->copy()->addDays(6));
    $dispatches3 = Lad::run();
    expect($dispatches3)->toBeEmpty();
    Notification::assertNotSentTo($user, OnboardingTipsNotification::class);
});

test('user who achieved goal before initial enrollment is never enrolled', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'email_verified_at' => $now,
    ]);

    // Already created property before campaign starts
    Property::create(['user_id' => $user->id, 'title' => 'Pre-existing Project']);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->cancelWhen(fn ($u) => $u->properties()->exists())
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    Carbon::setTestNow($now->copy()->addDays(3));
    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();
    expect(LadCampaignState::where('recipient_id', $user->id)->count())->toBe(0);
    Notification::assertNothingSent();
});
