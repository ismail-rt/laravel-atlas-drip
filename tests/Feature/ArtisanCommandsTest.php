<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Models\LadNotificationLog;
use Lad\Step;
use Tests\Support\OnboardingReminderNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('artisan lad:send and lifecycle:send dispatches due emails and supports dry-run', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Kara',
        'email' => 'kara@example.com',
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

    // 1. Dry run via lifecycle:send
    $this->artisan('lifecycle:send --dry-run')
        ->expectsOutputToContain('Running in DRY-RUN mode')
        ->expectsOutputToContain('onboarding')
        ->expectsOutputToContain('day3')
        ->assertSuccessful();

    // Verify dry run did not touch database or send notification
    expect(LadNotificationLog::count())->toBe(0);
    expect(LadCampaignState::count())->toBe(0);
    Notification::assertNothingSent();

    // 2. Real dispatch via lad:send
    $this->artisan('lad:send')
        ->expectsOutputToContain('Finished. Total 1 step(s) dispatched.')
        ->assertSuccessful();

    expect(LadNotificationLog::where('recipient_id', $user->id)->count())->toBe(1);
    expect(LadCampaignState::where('recipient_id', $user->id)->count())->toBe(1);
    Notification::assertSentToTimes($user, OnboardingReminderNotification::class, 1);
});

test('artisan lad:status and lifecycle:status outputs recipient state inspection', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Leo',
        'email' => 'leo@example.com',
        'email_verified_at' => $now,
    ]);

    Lad::register(
        Campaign::make('onboarding')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->email_verified_at)
            ->steps([
                Step::make('day3')->offsetDays(3)->notification(OnboardingReminderNotification::class),
            ])
    );

    $this->artisan("lifecycle:status {$user->id}")
        ->expectsOutputToContain("Status for Recipient #{$user->id}:")
        ->expectsOutputToContain('Global Cooldown:')
        ->expectsOutputToContain('onboarding')
        ->assertSuccessful();
});

test('artisan lad:cancel and lifecycle:cancel terminates active campaign states', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Mia',
        'email' => 'mia@example.com',
        'email_verified_at' => $now,
    ]);

    $state = LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $now,
    ]);

    expect($state->isActive())->toBeTrue();

    $this->artisan("lifecycle:cancel {$user->id} onboarding")
        ->expectsOutputToContain("Successfully cancelled 1 active campaign state(s) for recipient #{$user->id}.")
        ->assertSuccessful();

    $state->refresh();
    expect($state->isCancelled())->toBeTrue();
});
