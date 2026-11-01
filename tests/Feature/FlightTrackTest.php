<?php

use App\Models\Flight;
use App\Models\TrackPoint;

test('returns only positioned points of the requested flight in flight order', function () {
    $flight = Flight::factory()->create();

    TrackPoint::factory()->for($flight)->create(['sequence' => 2, 'latitude' => 53.2, 'longitude' => 49.2]);
    TrackPoint::factory()->for($flight)->create(['sequence' => 0, 'latitude' => 53.1, 'longitude' => 49.1]);
    TrackPoint::factory()->for($flight)->withoutFix()->create(['sequence' => 1]);
    TrackPoint::factory()->create(['sequence' => 3]);

    $response = $this->getJson(route('flights.track', $flight));

    $response->assertOk()->assertJsonCount(2, 'points');

    expect($response->json('points.*.0'))->toBe([0, 2])
        ->and($response->json('points.0.2'))->toBe(53.1)
        ->and($response->json('points.0.3'))->toBe(49.1);
});

test('returns not found for an unknown flight', function () {
    $this->getJson(route('flights.track', 999))->assertNotFound();
});
