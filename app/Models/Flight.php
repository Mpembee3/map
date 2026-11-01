<?php

namespace App\Models;

use Database\Factories\FlightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $source_file
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 */
#[Fillable(['name', 'source_file', 'started_at', 'ended_at'])]
class Flight extends Model
{
    /** @use HasFactory<FlightFactory> */
    use HasFactory;

    /**
     * @return HasMany<TrackPoint, $this>
     */
    public function trackPoints(): HasMany
    {
        return $this->hasMany(TrackPoint::class);
    }

    /**
     * Aggregate figures for the map's summary panel.
     *
     * @return array{samples: int, positions: int, withoutFix: int, suspect: int, peakJamIndicator: float|null, interferenceStartedAt: string|null, interferenceEndedAt: string|null}
     */
    public function summary(): array
    {
        $totals = $this->trackPoints()
            ->toBase()
            ->selectRaw('count(*) as samples')
            ->selectRaw('coalesce(sum(has_fix), 0) as positions')
            ->selectRaw('coalesce(sum(is_suspect), 0) as suspect')
            ->selectRaw('max(jam_indicator) as peak_jam_indicator')
            ->first();

        $interference = $this->trackPoints()
            ->toBase()
            ->where('jam_indicator', '>=', TrackPoint::HIGH_JAM_INDICATOR)
            ->selectRaw('min(recorded_at) as started_at, max(recorded_at) as ended_at')
            ->first();

        return [
            'samples' => (int) $totals->samples,
            'positions' => (int) $totals->positions,
            'withoutFix' => (int) $totals->samples - (int) $totals->positions,
            'suspect' => (int) $totals->suspect,
            'peakJamIndicator' => $totals->peak_jam_indicator === null ? null : (float) $totals->peak_jam_indicator,
            'interferenceStartedAt' => $interference->started_at,
            'interferenceEndedAt' => $interference->ended_at,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
