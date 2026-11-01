import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { leafletLayer } from 'protomaps-leaflet';

// protomaps-leaflet extends the global Leaflet object.
window.L = L;

const SEQUENCE = 0;
const RECORDED_AT = 1;
const LATITUDE = 2;
const LONGITUDE = 3;
const ALTITUDE = 4;
const JAM_INDICATOR = 5;
const HDOP = 6;
const IS_SUSPECT = 7;

const HIGH_JAM_INDICATOR = 14;
const CLICK_TOLERANCE_PIXELS = 14;
const TILE_BOUNDS = [
    [53.02, 49.8],
    [53.21, 50.14],
];

const PIN_ICON = L.divIcon({
    className: 'flight-map-pin',
    html: `<svg viewBox="0 0 28 38" width="28" height="38" aria-hidden="true">
        <path d="M14 1.5C7.1 1.5 1.5 7 1.5 13.8 1.5 23 14 36.5 14 36.5S26.5 23 26.5 13.8C26.5 7 20.9 1.5 14 1.5Z" />
        <circle cx="14" cy="13.8" r="4.5" />
    </svg>`,
    iconSize: [28, 38],
    iconAnchor: [14, 37],
    popupAnchor: [0, -36],
});

const METRICS = {
    jamind: {
        label: 'jamind',
        unit: '%',
        column: JAM_INDICATOR,
        bands: [
            { below: 12, label: 'Normal, under 12', color: '#64748b' },
            { below: 14, label: 'Elevated, 12 to 14', color: '#fab219' },
            { below: Infinity, label: 'High, 14 and over', color: '#d03b3b' },
        ],
    },
    altitude: {
        label: 'Alt (HAE)',
        unit: 'm',
        column: ALTITUDE,
        bands: [
            { below: 900, label: 'Under 900 m', color: '#a5c8f0' },
            { below: 950, label: '900 to 950 m', color: '#6fa8e8' },
            { below: 1000, label: '950 to 1,000 m', color: '#3f86da' },
            { below: 1050, label: '1,000 to 1,050 m', color: '#1f63b5' },
            { below: Infinity, label: '1,050 m and over', color: '#0d3f7c' },
        ],
    },
    hdop: {
        label: 'HDOP',
        unit: '',
        column: HDOP,
        bands: [
            { below: 1.5, label: 'Under 1.5', color: '#c9b6ec' },
            { below: 3, label: '1.5 to 3', color: '#a585dd' },
            { below: 5, label: '3 to 5', color: '#8159c9' },
            { below: 10, label: '5 to 10', color: '#5f35a6' },
            { below: Infinity, label: '10 and over', color: '#3d1e73' },
        ],
    },
};

const bandIndexFor = (metric, value) => metric.bands.findIndex((band) => value < band.below);

/**
 * Split the points into contiguous runs per colour band, plus the straight
 * bridges across any samples that have no position.
 */
const buildSegments = (points, metric) => {
    const bandLines = metric.bands.map(() => []);
    const gapLines = [];
    let openLine = null;

    for (let index = 1; index < points.length; index++) {
        const previous = points[index - 1];
        const current = points[index];
        const from = [previous[LATITUDE], previous[LONGITUDE]];
        const to = [current[LATITUDE], current[LONGITUDE]];

        if (current[SEQUENCE] - previous[SEQUENCE] > 1) {
            gapLines.push([from, to]);
            openLine = null;

            continue;
        }

        const bandIndex = bandIndexFor(metric, current[metric.column] ?? 0);

        if (openLine?.bandIndex === bandIndex) {
            openLine.coordinates.push(to);
        } else {
            openLine = { bandIndex, coordinates: [from, to] };
            bandLines[bandIndex].push(openLine.coordinates);
        }
    }

    return { bandLines, gapLines };
};

const escapeHtml = (value) => String(value).replace(/[&<>"]/g, (character) => `&#${character.charCodeAt(0)};`);

const formatValue = (value, unit) => (value === null ? 'n/a' : `${value}${unit ? ` ${unit}` : ''}`);

const describePoint = (point) => `
    <dl class="flight-map-popup">
        <dt>Time</dt><dd>${escapeHtml(point[RECORDED_AT] ?? 'n/a')} UTC</dd>
        <dt>Lat</dt><dd>${point[LATITUDE].toFixed(6)}</dd>
        <dt>Lon</dt><dd>${point[LONGITUDE].toFixed(6)}</dd>
        <dt>Alt (HAE)</dt><dd>${formatValue(point[ALTITUDE], 'm')}</dd>
        <dt>jamind</dt><dd>${formatValue(point[JAM_INDICATOR], '%')}</dd>
        <dt>HDOP</dt><dd>${formatValue(point[HDOP], '')}</dd>
        ${point[IS_SUSPECT] ? '<dt>Note</dt><dd>Suspect position jump</dd>' : ''}
    </dl>`;

document.addEventListener('alpine:init', () => {
    window.Alpine.data('flightMap', (trackUrl, tilesUrl) => {
        let map = null;
        let trackLayer = null;
        let trackCasingColor = '#ffffff';
        let points = [];

        return {
            metricKey: 'jamind',
            hideSuspect: false,
            isLoading: true,
            errorMessage: null,
            metrics: METRICS,

            get metric() {
                return METRICS[this.metricKey];
            },

            async init() {
                const isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                map = L.map(this.$refs.map, {
                    preferCanvas: true,
                    minZoom: 10,
                    maxZoom: 18,
                    maxBounds: TILE_BOUNDS,
                    maxBoundsViscosity: 1,
                });
                map.fitBounds(TILE_BOUNDS);

                trackCasingColor = isDark ? '#1a1a19' : '#ffffff';

                leafletLayer({
                    url: `${tilesUrl}/{z}/{x}/{y}.mvt`,
                    flavor: isDark ? 'dark' : 'light',
                    lang: 'en',
                    bounds: TILE_BOUNDS,
                    maxDataZoom: 15,
                    attribution: '&copy; OpenStreetMap contributors, Protomaps',
                }).addTo(map);

                map.on('click', (event) => this.showNearestPoint(event));

                this.$watch('metricKey', () => this.drawTrack());
                this.$watch('hideSuspect', () => {
                    map.closePopup();
                    this.drawTrack();
                });

                try {
                    const response = await fetch(trackUrl, { headers: { Accept: 'application/json' } });

                    if (!response.ok) {
                        throw new Error(`Track request failed with status ${response.status}`);
                    }

                    points = (await response.json()).points;
                } catch (error) {
                    console.error(error);
                    this.errorMessage = 'The flight track could not be loaded.';

                    return;
                } finally {
                    this.isLoading = false;
                }

                this.drawTrack();

                if (points.length > 0) {
                    map.fitBounds(points.map((point) => [point[LATITUDE], point[LONGITUDE]]), { padding: [24, 24] });
                }
            },

            visiblePoints() {
                return this.hideSuspect ? points.filter((point) => !point[IS_SUSPECT]) : points;
            },

            drawTrack() {
                trackLayer?.remove();
                trackLayer = L.layerGroup().addTo(map);

                const metric = this.metric;
                const visible = this.visiblePoints();
                const { bandLines, gapLines } = buildSegments(visible, metric);
                const allLines = [...bandLines.flat(), ...gapLines];

                L.polyline(allLines, { color: trackCasingColor, weight: 5, opacity: 0.7, interactive: false }).addTo(trackLayer);
                L.polyline(gapLines, { color: '#6b7280', weight: 1.5, dashArray: '4 4', interactive: false }).addTo(trackLayer);

                // Bands run low to high so the rare, important values stay on top of repeat passes.
                bandLines.forEach((lines, bandIndex) => {
                    L.polyline(lines, { color: metric.bands[bandIndex].color, weight: 2.5, interactive: false }).addTo(trackLayer);
                });

                const firstInterference = visible.find((point) => (point[JAM_INDICATOR] ?? 0) >= HIGH_JAM_INDICATOR);

                if (firstInterference) {
                    L.circleMarker([firstInterference[LATITUDE], firstInterference[LONGITUDE]], {
                        radius: 8,
                        color: '#ffffff',
                        weight: 2,
                        fillColor: '#d03b3b',
                        fillOpacity: 1,
                    })
                        .bindTooltip(`High jamind starts ${escapeHtml(firstInterference[RECORDED_AT])} UTC`)
                        .addTo(trackLayer);
                }
            },

            showNearestPoint(event) {
                const clicked = map.latLngToContainerPoint(event.latlng);
                let nearest = null;
                let nearestDistance = CLICK_TOLERANCE_PIXELS;

                for (const point of this.visiblePoints()) {
                    const distance = map.latLngToContainerPoint([point[LATITUDE], point[LONGITUDE]]).distanceTo(clicked);

                    if (distance < nearestDistance) {
                        nearest = point;
                        nearestDistance = distance;
                    }
                }

                if (!nearest) {
                    return;
                }

                const pin = L.marker([nearest[LATITUDE], nearest[LONGITUDE]], { icon: PIN_ICON, keyboard: false })
                    .bindPopup(describePoint(nearest))
                    .on('popupclose', () => pin.remove())
                    .addTo(map);

                pin.openPopup();
            },

            destroy() {
                map?.remove();
                map = null;
            },
        };
    });
});
