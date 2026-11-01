<?php

use App\Models\Flight;
use App\Models\TrackPoint;
use Livewire\Livewire;

test('prompts for an import when there are no flights', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('No flights imported');
});

test('summarises the flight and its interference window', function () {
    $flight = Flight::factory()->create();

    TrackPoint::factory()->for($flight)->create(['sequence' => 0, 'jam_indicator' => 10.6]);
    TrackPoint::factory()->for($flight)->create(['sequence' => 1, 'jam_indicator' => 26.3, 'recorded_at' => '2026-02-03 02:29:07']);
    TrackPoint::factory()->for($flight)->create(['sequence' => 2, 'jam_indicator' => 17.3, 'recorded_at' => '2026-02-03 02:32:46', 'is_suspect' => true]);
    TrackPoint::factory()->for($flight)->withoutFix()->create(['sequence' => 3]);

    expect($flight->summary())->toMatchArray([
        'samples' => 4,
        'positions' => 3,
        'withoutFix' => 1,
        'suspect' => 1,
        'peakJamIndicator' => 26.3,
        'interferenceStartedAt' => '2026-02-03 02:29:07',
        'interferenceEndedAt' => '2026-02-03 02:32:46',
    ]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('High jamind')
        ->assertSee('02:29:07 to 02:32:46 UTC')
        ->assertSee('wire:key="flight-'.$flight->id.'"', escape: false);
});

test('omits the interference note when jamind stays low', function () {
    TrackPoint::factory()->create(['jam_indicator' => 13.9]);

    $this->get(route('home'))->assertOk()->assertDontSee('High jamind');
});

test('switching flight points the map at the new track', function () {
    Flight::factory()->create(['started_at' => '2026-02-03 00:00:00']);
    $other = Flight::factory()->create(['started_at' => '2026-02-01 00:00:00']);

    Livewire::test('pages::flight-map')
        ->set('flightId', $other->id)
        ->assertSee('wire:key="flight-'.$other->id.'"', escape: false);
});
