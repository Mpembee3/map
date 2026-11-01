<?php

namespace App\Models;

use Database\Factories\TrackPointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $flight_id
 * @property int $sequence
 * @property Carbon|null $recorded_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $altitude_hae
 * @property float|null $jam_indicator
 * @property float|null $hdop
 * @property int|null $satellites_used
 * @property float|null $noise
 * @property bool $has_fix
 * @property bool $is_suspect
 */
#[Fillable([
    'flight_id', 'sequence', 'recorded_at', 'latitude', 'longitude', 'altitude_hae',
    'jam_indicator', 'hdop', 'satellites_used', 'noise', 'has_fix', 'is_suspect',
])]
class TrackPoint extends Model
{
    /** @use HasFactory<TrackPointFactory> */
    use HasFactory;

    /**
     * The jamind value at and above which a sample counts as interference.
     */
    public const float HIGH_JAM_INDICATOR = 14.0;

    public $timestamps = false;

    /**
     * @return BelongsTo<Flight, $this>
     */
    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'altitude_hae' => 'float',
            'jam_indicator' => 'float',
            'hdop' => 'float',
            'noise' => 'float',
            'has_fix' => 'boolean',
            'is_suspect' => 'boolean',
        ];
    }
}
