<?php

namespace App\Http\Controllers;

use App\Models\Flight;
use Illuminate\Http\JsonResponse;

class FlightTrackController extends Controller
{
    /**
     * Return the flight's positions as compact rows, in flight order. Each row is
     * [sequence, recordedAt, latitude, longitude, altitudeHae, jamIndicator, hdop, isSuspect].
     */
    public function __invoke(Flight $flight): JsonResponse
    {
        $points = $flight->trackPoints()
            ->toBase()
            ->where('has_fix', true)
            ->orderBy('sequence')
            ->get(['sequence', 'recorded_at', 'latitude', 'longitude', 'altitude_hae', 'jam_indicator', 'hdop', 'is_suspect'])
            ->map(fn (object $point): array => [
                (int) $point->sequence,
                $point->recorded_at,
                (float) $point->latitude,
                (float) $point->longitude,
                $point->altitude_hae === null ? null : (float) $point->altitude_hae,
                $point->jam_indicator === null ? null : (float) $point->jam_indicator,
                $point->hdop === null ? null : (float) $point->hdop,
                (bool) $point->is_suspect,
            ]);

        return response()->json(['points' => $points]);
    }
}
