<?php

use Illuminate\Support\Carbon;
use Lad\Step;

test('two clock formula due date equals anchor plus offset when no previous step exists', function () {
    $anchor = Carbon::parse('2026-09-01 10:00:00');

    $step = Step::make('day3')->offsetDays(3);

    $dueAt = $step->calculateDueAt($anchor);

    expect($dueAt->toDateTimeString())->toBe('2026-09-04 10:00:00');
});

test('two clock formula takes maximum between anchor offset and previous step plus min gap', function () {
    $anchor = Carbon::parse('2026-09-01 10:00:00');

    $step = Step::make('day5')
        ->offsetDays(5)
        ->minimumGapDays(2);

    // Case A: Previous step sent on schedule (Day 3)
    // anchor + 5 = Sept 6
    // prev (Sept 4) + 2 = Sept 6
    // max = Sept 6
    $prevOnSchedule = Carbon::parse('2026-09-04 10:00:00');
    expect($step->calculateDueAt($anchor, $prevOnSchedule)->toDateTimeString())
        ->toBe('2026-09-06 10:00:00');

    // Case B: Previous step delayed to Day 4 (Sept 5)
    // anchor + 5 = Sept 6
    // prev (Sept 5) + 2 = Sept 7
    // max = Sept 7 (pushed forward!)
    $prevDelayed = Carbon::parse('2026-09-05 10:00:00');
    expect($step->calculateDueAt($anchor, $prevDelayed)->toDateTimeString())
        ->toBe('2026-09-07 10:00:00');

    // Case C: Previous step delayed severely to Day 6 (Sept 7)
    // anchor + 5 = Sept 6
    // prev (Sept 7) + 2 = Sept 9
    // max = Sept 9
    $prevSeverelyDelayed = Carbon::parse('2026-09-07 10:00:00');
    expect($step->calculateDueAt($anchor, $prevSeverelyDelayed)->toDateTimeString())
        ->toBe('2026-09-09 10:00:00');
});

test('two clock formula supports hours and minutes offsets and gaps', function () {
    $anchor = Carbon::parse('2026-09-01 12:00:00');

    $step = Step::make('hour12')
        ->offsetHours(12)
        ->minimumGapHours(4);

    $dueAt = $step->calculateDueAt($anchor);
    expect($dueAt->toDateTimeString())->toBe('2026-09-02 00:00:00');

    $prevSent = Carbon::parse('2026-09-01 22:00:00');
    // prev (22:00) + 4h = 02:00 next day
    $dueAtWithGap = $step->calculateDueAt($anchor, $prevSent);
    expect($dueAtWithGap->toDateTimeString())->toBe('2026-09-02 02:00:00');
});
