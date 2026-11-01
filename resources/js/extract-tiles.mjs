// Downloads the offline basemap for one area into public/tiles. Needs internet once.
//
//   npm run tiles:extract
//   npm run tiles:extract -- --bbox=49.80,53.02,50.14,53.21 --max-zoom=15 --build=20261031
//
// When the area changes, update TILE_BOUNDS in flight-map.js to match.

import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { PMTiles } from 'pmtiles';

const BUILDS_URL = 'https://build.protomaps.com';
const BUILD_LOOKBACK_DAYS = 14;
const CONCURRENT_DOWNLOADS = 6;
const MAX_ATTEMPTS = 4;

const { values: options } = parseArgs({
    options: {
        bbox: { type: 'string', default: '49.80,53.02,50.14,53.21' },
        'max-zoom': { type: 'string', default: '15' },
        build: { type: 'string' },
        out: { type: 'string', default: fileURLToPath(new URL('../../public/tiles', import.meta.url)) },
    },
});

const [west, south, east, north] = options.bbox.split(',').map(Number);
const maxZoom = Number(options['max-zoom']);

if ([west, south, east, north, maxZoom].some(Number.isNaN) || west >= east || south >= north) {
    throw new Error('Expected --bbox=west,south,east,north and a numeric --max-zoom.');
}

const columnFor = (longitude, zoom) => Math.floor(((longitude + 180) / 360) * 2 ** zoom);

const rowFor = (latitude, zoom) =>
    Math.floor(((1 - Math.asinh(Math.tan((latitude * Math.PI) / 180)) / Math.PI) / 2) * 2 ** zoom);

/**
 * Builds are only kept for a few days, so find the newest one that still exists.
 */
const findLatestBuild = async () => {
    for (let daysAgo = 0; daysAgo < BUILD_LOOKBACK_DAYS; daysAgo++) {
        const date = new Date(Date.now() - daysAgo * 86400000).toISOString().slice(0, 10).replaceAll('-', '');
        const response = await fetch(`${BUILDS_URL}/${date}.pmtiles`, { method: 'HEAD' });

        if (response.ok) {
            return date;
        }
    }

    throw new Error(`No map build found in the last ${BUILD_LOOKBACK_DAYS} days.`);
};

const build = options.build ?? (await findLatestBuild());
const source = new PMTiles(`${BUILDS_URL}/${build}.pmtiles`);
const tiles = [];

for (let zoom = 0; zoom <= maxZoom; zoom++) {
    for (let x = columnFor(west, zoom); x <= columnFor(east, zoom); x++) {
        for (let y = rowFor(north, zoom); y <= rowFor(south, zoom); y++) {
            tiles.push([zoom, x, y]);
        }
    }
}

console.log(`Extracting ${tiles.length} tiles from build ${build} into ${options.out}`);

let nextTile = 0;
let written = 0;
let missing = 0;
let failed = 0;

const downloadTile = async ([zoom, x, y]) => {
    for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt++) {
        try {
            const tile = await source.getZxy(zoom, x, y);

            if (!tile) {
                missing++;

                return;
            }

            const directory = path.join(options.out, `${zoom}`, `${x}`);

            await mkdir(directory, { recursive: true });
            await writeFile(path.join(directory, `${y}.mvt`), new Uint8Array(tile.data));
            written++;

            return;
        } catch (error) {
            if (attempt === MAX_ATTEMPTS) {
                failed++;
                console.error(`Failed ${zoom}/${x}/${y}: ${error.message}`);
            }
        }
    }
};

await Promise.all(
    Array.from({ length: CONCURRENT_DOWNLOADS }, async () => {
        while (nextTile < tiles.length) {
            await downloadTile(tiles[nextTile++]);
        }
    }),
);

console.log(`Written: ${written}, missing: ${missing}, failed: ${failed}`);

if (failed > 0) {
    process.exitCode = 1;
}
