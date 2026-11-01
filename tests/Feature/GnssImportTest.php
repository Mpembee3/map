<?php

use App\Models\Flight;
use App\Models\TrackPoint;

function writeGnssLog(string $name, array $rows, ?string $header = null): string
{
    $header ??= 'Index,Lat,Lon,Alt (MSL),Alt (HAE),jamind,SVs Used,Fix type (GPS) valid,Fix type (GPS),UTC,GPS time,noise (GPS),DGPS,HDOP,PDOP';
    $path = storage_path("framework/testing/{$name}");

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, implode("\n", [$header, ...$rows])."\n");

    return $path;
}

afterEach(function () {
    array_map('unlink', glob(storage_path('framework/testing/*.csv')) ?: []);
});

test('rebuilds utc from gps time and records flight bounds', function () {
    $path = writeGnssLog('timing.csv', [
        '0,,,,,,0,No,,21:56:27.500 ??/??/????,,,No,0.8,1.2',
        '1,53.1,49.9,1002.6,996.2,10.2,10,Yes,3D,56:29.0,2404:165407.000,60,No,0.9,1.9',
        '2,53.10001,49.9,1002.6,996.2,10.2,10,Yes,3D,56:30.0,2404:165408.000,60,No,0.9,1.9',
    ]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    $flight = Flight::sole();

    expect($flight->source_file)->toBe('timing.csv')
        ->and($flight->started_at->format('Y-m-d H:i:s'))->toBe('2026-02-02 21:56:29')
        ->and($flight->ended_at->format('Y-m-d H:i:s'))->toBe('2026-02-02 21:56:30')
        ->and($flight->trackPoints()->where('sequence', 0)->value('recorded_at'))->toBeNull();
});

test('keeps samples without a fix but stores no position for them', function () {
    $path = writeGnssLog('gaps.csv', [
        '0,,,,,11.8,0,No,,56:28.0,2404:165406.000,61,No,100,100',
    ]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    $point = TrackPoint::sole();

    expect($point->has_fix)->toBeFalse()
        ->and($point->latitude)->toBeNull()
        ->and($point->jam_indicator)->toBe(11.8);
});

test('treats a row flagged invalid as having no fix even when coordinates are present', function () {
    $path = writeGnssLog('invalid.csv', [
        '0,53.1,49.9,1002.6,996.2,10.2,10,No,,56:29.0,2404:165407.000,60,No,0.9,1.9',
    ]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    expect(TrackPoint::sole())
        ->has_fix->toBeFalse()
        ->latitude->toBeNull();
});

test('flags implausible position and altitude jumps without discarding them', function (string $jumpRow) {
    $path = writeGnssLog('jumps.csv', [
        '0,53.1,49.9,1002.6,996.2,10.2,10,Yes,3D,56:29.0,2404:165407.000,60,No,0.9,1.9',
        $jumpRow,
    ]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    expect(TrackPoint::count())->toBe(2)
        ->and(TrackPoint::where('sequence', 1)->value('is_suspect'))->toBeTruthy()
        ->and(TrackPoint::where('sequence', 0)->value('is_suspect'))->toBeFalsy();
})->with([
    'horizontal' => '1,53.102,49.9,1002.6,996.2,10.2,10,Yes,3D,56:30.0,2404:165408.000,60,No,0.9,1.9',
    'vertical' => '1,53.1,49.9,1200,1190,10.2,10,Yes,3D,56:30.0,2404:165408.000,60,No,0.9,1.9',
]);

test('does not flag normal cruise speed or movement across a gap in samples', function () {
    $path = writeGnssLog('cruise.csv', [
        '0,53.1,49.9,1002.6,996.2,10.2,10,Yes,3D,56:29.0,2404:165407.000,60,No,0.9,1.9',
        '1,53.10025,49.9,1002.6,996.2,10.2,10,Yes,3D,56:30.0,2404:165408.000,60,No,0.9,1.9',
        '2,,,,,10.2,0,No,,56:31.0,2404:165409.000,60,No,100,100',
        '3,,,,,10.2,0,No,,56:32.0,2404:165410.000,60,No,100,100',
        '4,53.10100,49.9,1002.6,996.2,10.2,10,Yes,3D,56:33.0,2404:165411.000,60,No,0.9,1.9',
    ]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    expect(TrackPoint::where('is_suspect', true)->count())->toBe(0);
});

test('re-importing a file replaces its points', function () {
    $row = '0,53.1,49.9,1002.6,996.2,10.2,10,Yes,3D,56:29.0,2404:165407.000,60,No,0.9,1.9';
    $path = writeGnssLog('again.csv', [$row]);

    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();
    $this->artisan('gnss:import', ['path' => $path])->assertSuccessful();

    expect(Flight::count())->toBe(1)
        ->and(TrackPoint::count())->toBe(1);
});

test('rejects a file with missing columns and imports nothing', function () {
    $path = writeGnssLog('bad-header.csv', ['0,53.1'], 'Index,Lat');

    $this->artisan('gnss:import', ['path' => $path])
        ->expectsOutputToContain('is missing columns: Lon, Alt (HAE)')
        ->assertFailed();

    expect(Flight::count())->toBe(0);
});

test('rolls back the whole file when a row is malformed', function () {
    $path = writeGnssLog('bad-row.csv', [
        '0,53.1,49.9,1002.6,996.2,10.2,10,Yes,3D,56:29.0,2404:165407.000,60,No,0.9,1.9',
        '1,53.1',
    ]);

    $this->artisan('gnss:import', ['path' => $path])
        ->expectsOutputToContain('malformed row')
        ->assertFailed();

    expect(Flight::count())->toBe(0)
        ->and(TrackPoint::count())->toBe(0);
});

test('fails clearly when the file does not exist', function () {
    $this->artisan('gnss:import', ['path' => storage_path('framework/testing/missing.csv')])
        ->expectsOutputToContain('Unable to read')
        ->assertFailed();
});
