<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Lad\Campaign;
use Lad\Facades\Lad;
use Lad\Models\LadCampaignState;
use Lad\Step;
use Tests\Support\WeMissYouNotification;
use Tests\User;

beforeEach(function () {
    Notification::fake();
});

test('recurring campaign allows multiple independent streaks via custom instance key', function () {
    $streak1Time = Carbon::parse('2026-09-01 10:00:00');
    Carbon::setTestNow($streak1Time);

    $user = User::create([
        'name' => 'Jack',
        'email' => 'jack@example.com',
        'last_login_at' => $streak1Time,
    ]);

    Lad::register(
        Campaign::make('idle_winback')
            ->eligible(fn ($u) => true)
            ->anchor(fn ($u) => $u->last_login_at)
            ->instanceKey(fn ($u) => $u->last_login_at?->format('Y-m-d-H-i-s') ?? 'default')
            ->steps([
                Step::make('idle_reminder')->offsetDays(7)->notification(WeMissYouNotification::class),
            ])
    );

    // 7 days into streak 1: idle reminder sends
    Carbon::setTestNow($streak1Time->copy()->addDays(7));
    $dispatches1 = Lad::run();
    expect($dispatches1)->toHaveCount(1);
    expect($dispatches1[0]['step'])->toBe('idle_reminder');

    $state1 = LadCampaignState::where('recipient_id', $user->id)
        ->where('instance_key', '2026-09-01-10-00-00')
        ->first();

    expect($state1)->not->toBeNull();
    expect($state1->isCompleted())->toBeTrue();

    // Streak 2: User logs back in on Day 20!
    $streak2Time = Carbon::parse('2026-09-20 12:00:00');
    $user->update(['last_login_at' => $streak2Time]);

    // 7 days into streak 2 (Day 27): new streak reminder triggers with new instance key!
    Carbon::setTestNow($streak2Time->copy()->addDays(7));
    $dispatches2 = Lad::run();
    expect($dispatches2)->toHaveCount(1);
    expect($dispatches2[0]['step'])->toBe('idle_reminder');

    $state2 = LadCampaignState::where('recipient_id', $user->id)
        ->where('instance_key', '2026-09-20-12-00-00')
        ->first();

    expect($state2)->not->toBeNull();
    expect($state2->isCompleted())->toBeTrue();

    // Both state records exist in history
    expect(LadCampaignState::where('recipient_id', $user->id)->count())->toBe(2);
    Notification::assertSentToTimes($user, WeMissYouNotification::class, 2);
});
