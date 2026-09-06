<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Step;
use Tests\Support\OnboardingReminderNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('recipient opt out cancels all active campaigns and prevents subsequent sends', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Hannah',
        'email' => 'hannah@example.com',
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

    // Initial enrollment state created
    $state = LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $now,
    ]);

    expect($state->isActive())->toBeTrue();

    // User opts out
    Lad::optOutRecipient($user);

    $user->refresh();
    expect($user->marketing_emails_opted_out_at)->not->toBeNull();
    expect($user->hasOptedOutOfLifecycle())->toBeTrue();

    $state->refresh();
    expect($state->isCancelled())->toBeTrue();

    // On Day 3: Scheduler skips opted out user
    Carbon::setTestNow($now->copy()->addDays(3));
    $dispatches = Lad::run();
    expect($dispatches)->toBeEmpty();
    Notification::assertNothingSent();
});

test('signed unsubscribe route opts out user and renders view', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Ian',
        'email' => 'ian@example.com',
        'email_verified_at' => $now,
    ]);

    $state = LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $now,
    ]);

    $url = Lad::unsubscribeUrl($user);
    expect($url)->toContain('/lad/unsubscribe/'.$user->id);

    // Visiting signed URL
    $response = $this->get($url);
    $response->assertStatus(200);
    $response->assertSee('You are unsubscribed');
    $response->assertSee('Important:');

    $user->refresh();
    expect($user->marketing_emails_opted_out_at)->not->toBeNull();

    $state->refresh();
    expect($state->isCancelled())->toBeTrue();

    // Visiting without signature returns 403
    $tamperedUrl = url('/lad/unsubscribe/'.$user->id);
    $this->get($tamperedUrl)->assertStatus(403);

    // Resubscribe via signed POST
    $resubscribeUrl = URL::signedRoute('lad.resubscribe', ['recipient' => $user->id]);
    $resubscribeResponse = $this->post($resubscribeUrl);
    $resubscribeResponse->assertStatus(200);
    $resubscribeResponse->assertSee('Preferences Updated');

    $user->refresh();
    expect($user->marketing_emails_opted_out_at)->toBeNull();
});

test('dry run and status inspection do not cancel active states for opted out users', function () {
    $now = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($now);

    $user = User::create([
        'name' => 'Opted Out User',
        'email' => 'optout@example.com',
        'email_verified_at' => $now,
        'marketing_emails_opted_out_at' => $now,
    ]);

    $state = LadCampaignState::create([
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
        'campaign' => 'onboarding',
        'status' => LadCampaignState::STATUS_ACTIVE,
        'anchor_at' => $now,
    ]);

    // 1. Status check should not mutate state
    $status = Lad::getStatusForRecipient($user);
    expect($status['is_opted_out'])->toBeTrue();
    $state->refresh();
    expect($state->isActive())->toBeTrue();

    // 2. Dry-run evaluation should not mutate state
    $result = Lad::evaluateRecipient($user, now: $now, dryRun: true);
    expect($result)->toBeNull();
    $state->refresh();
    expect($state->isActive())->toBeTrue();
});
