<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\TrackPoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class GnssLogImporter
{
    /**
     * The CSV columns the map depends on.
     *
     * @var list<string>
     */
    public const array REQUIRED_COLUMNS = [
        'Index', 'Lat', 'Lon', 'Alt (HAE)', 'jamind', 'SVs Used',
        'Fix type (GPS) valid', 'GPS time', 'noise (GPS)', 'HDOP',
    ];

    /**
     * GPS time ignores leap seconds, so it runs ahead of UTC by this many seconds.
     */
    private const int GPS_UTC_OFFSET_SECONDS = 18;

    private const string GPS_EPOCH = '1980-01-06 00:00:00';

    private const int SECONDS_PER_WEEK = 604800;

    /**
     * Steps faster than this, in metres per second, are treated as receiver glitches.
     * The logged platform cruises at about 28 m/s.
     */
    private const float MAX_PLAUSIBLE_SPEED = 60.0;

    private const int EARTH_RADIUS_METRES = 6371000;

    private const int INSERT_CHUNK_SIZE = 500;

    /**
     * Import a GNSS log, replacing any earlier import of the same file.
     *
     * @throws InvalidArgumentException when the file is missing columns or has malformed rows.
     */
    public function import(string $path): Flight
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;

        if ($handle === false) {
            throw new InvalidArgumentException("Unable to read [{$path}].");
        }

        try {
            return DB::transaction(fn (): Flight => $this->importRows($handle, $path));
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importRows($handle, string $path): Flight
    {
        $columns = $this->readColumns($handle, $path);

        $flight = Flight::updateOrCreate(
            ['source_file' => basename($path)],
            ['name' => pathinfo($path, PATHINFO_FILENAME)],
        );

        $flight->trackPoints()->delete();

        $previousFix = null;
        $chunk = [];
        $startedAt = null;
        $endedAt = null;

        while (($values = fgetcsv($handle, escape: '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            if (count($values) !== count($columns)) {
                throw new InvalidArgumentException("[{$path}] has a malformed row: expected ".count($columns).' columns, found '.count($values).'.');
            }

            $point = $this->parseRow(array_combine($columns, $values), $flight->id, $previousFix);

            if ($point['has_fix']) {
                $previousFix = $point;
            }

            $startedAt ??= $point['recorded_at'];
            $endedAt = $point['recorded_at'] ?? $endedAt;

            unset($point['gps_seconds']);
            $chunk[] = $point;

            if (count($chunk) === self::INSERT_CHUNK_SIZE) {
                TrackPoint::insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            TrackPoint::insert($chunk);
        }

        $flight->update(['started_at' => $startedAt, 'ended_at' => $endedAt]);

        return $flight;
    }

    /**
     * @param  resource  $handle
     * @return list<string>
     */
    private function readColumns($handle, string $path): array
    {
        $header = fgetcsv($handle, escape: '');
        $columns = array_map(
            fn (?string $column): string => trim(str_replace("\xEF\xBB\xBF", '', (string) $column)),
            $header === false ? [] : $header,
        );

        $missing = array_diff(self::REQUIRED_COLUMNS, $columns);

        if ($missing !== []) {
            throw new InvalidArgumentException("[{$path}] is missing columns: ".implode(', ', $missing).'.');
        }

        return $columns;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, mixed>|null  $previousFix
     * @return array<string, mixed>
     */
    private function parseRow(array $row, int $flightId, ?array $previousFix): array
    {
        $latitude = $this->toFloat($row['Lat']);
        $longitude = $this->toFloat($row['Lon']);
        $hasFix = $row['Fix type (GPS) valid'] === 'Yes' && $latitude !== null && $longitude !== null;
        $gpsTime = $this->parseGpsTime($row['GPS time']);

        $point = [
            'flight_id' => $flightId,
            'sequence' => (int) $row['Index'],
            'recorded_at' => $gpsTime['recordedAt'],
            'gps_seconds' => $gpsTime['seconds'],
            'latitude' => $hasFix ? $latitude : null,
            'longitude' => $hasFix ? $longitude : null,
            'altitude_hae' => $hasFix ? $this->toFloat($row['Alt (HAE)']) : null,
            'jam_indicator' => $this->toFloat($row['jamind']),
            'hdop' => $this->toFloat($row['HDOP']),
            'satellites_used' => (int) $row['SVs Used'],
            'noise' => $this->toFloat($row['noise (GPS)']),
            'has_fix' => $hasFix,
            'is_suspect' => false,
        ];

        $point['is_suspect'] = $hasFix && $previousFix !== null && $this->isImplausibleStep($previousFix, $point);

        return $point;
    }

    /**
     * Rebuild the timestamp from "week:seconds-of-week" because the file's UTC
     * column has lost its hours and date.
     *
     * @return array{recordedAt: string|null, seconds: float|null}
     */
    private function parseGpsTime(string $gpsTime): array
    {
        if (preg_match('/^(\d+):(\d+(?:\.\d+)?)$/', trim($gpsTime), $matches) !== 1) {
            return ['recordedAt' => null, 'seconds' => null];
        }

        $seconds = ((int) $matches[1] * self::SECONDS_PER_WEEK) + (float) $matches[2];

        return [
            'recordedAt' => Carbon::parse(self::GPS_EPOCH, 'UTC')
                ->addSeconds((int) round($seconds) - self::GPS_UTC_OFFSET_SECONDS)
                ->format('Y-m-d H:i:s'),
            'seconds' => $seconds,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function isImplausibleStep(array $previous, array $current): bool
    {
        $elapsedSeconds = $previous['gps_seconds'] !== null && $current['gps_seconds'] !== null
            ? $current['gps_seconds'] - $previous['gps_seconds']
            : $current['sequence'] - $previous['sequence'];

        if ($elapsedSeconds <= 0) {
            return false;
        }

        $climbMetres = abs(($current['altitude_hae'] ?? 0.0) - ($previous['altitude_hae'] ?? 0.0));

        return max($this->groundDistance($previous, $current), $climbMetres) / $elapsedSeconds > self::MAX_PLAUSIBLE_SPEED;
    }

    /**
     * Equirectangular distance, accurate enough for one-second steps.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     */
    private function groundDistance(array $from, array $to): float
    {
        $northing = deg2rad($to['latitude'] - $from['latitude']);
        $easting = deg2rad($to['longitude'] - $from['longitude']) * cos(deg2rad($to['latitude']));

        return self::EARTH_RADIUS_METRES * hypot($northing, $easting);
    }

    private function toFloat(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
