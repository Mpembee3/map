# Flight Map

An offline map of GNSS flight logs. It reads CSV logs from the `data` folder and plots the track with Leaflet, coloured by `jamind`, Alt (HAE) or HDOP.

Once installed, the map needs no internet connection. The basemap, fonts and scripts are all stored in the project.

## Requirements

Install these first:

- [PHP 8.4](https://www.php.net/downloads) or newer, with the `pdo_sqlite`, `mbstring`, `fileinfo`, `openssl` and `zip` extensions enabled
- [Composer](https://getcomposer.org/download/)
- [Node.js](https://nodejs.org/) 22.18 or newer, or 24.11 or newer

To check your versions and PHP extensions:

```bash
php -v
php -m
composer -V
node -v
```

On Windows, if an extension is missing from `php -m`, enable it in `php.ini` by removing the `;` from the start of its `extension=` line.

## Install

You need internet for this step only.

1. Get the project onto your device by cloning the repository or copying the folder. On Windows, use a short path such as `C:\map`, because some files are deeply nested and long paths can fail.

2. In the project folder, run:

   ```bash
   composer run setup
   ```

   This installs the dependencies, creates the `.env` file and the SQLite database, and builds the page.

3. Import the flight logs from the `data` folder:

   ```bash
   php artisan gnss:import
   ```

## Run

```bash
php artisan serve
```

Open <http://localhost:8000> in your browser. Press `Ctrl+C` in the terminal to stop the server.

## Using the map

- **Colour track by:** switch between `jamind`, Alt (HAE) and HDOP.
- **Click the track:** drop a pin on the nearest point and see its time, Lat, Lon, Alt (HAE), `jamind` and HDOP. Close the details or click away from the track to remove the pin.
- **Dashed lines:** samples where the receiver had no position.
- **Red marker:** where high `jamind` (14 and over) first appears.
- **Hide suspect points:** hides positions that imply a speed or climb over 60 m/s, which are likely receiver glitches.

The map follows your system's light or dark theme.

## Adding a flight log

Put the CSV in the `data` folder and import it again:

```bash
php artisan gnss:import
```

To import one file from another location:

```bash
php artisan gnss:import path/to/log.csv
```

Importing the same file name again replaces the earlier import. A flight selector appears once there is more than one flight.

The CSV must include these columns: `Index`, `Lat`, `Lon`, `Alt (HAE)`, `jamind`, `SVs Used`, `Fix type (GPS) valid`, `GPS time`, `noise (GPS)` and `HDOP`.

Times are rebuilt from `GPS time`, so the `UTC` column isn't used. Excel files (`.xlsx`) must be saved as CSV first.

## Installing on a device with no internet

1. Complete the install steps on a device with internet.
2. Copy the whole project folder to the offline device, including `vendor`, `public/build`, `public/tiles` and `database/database.sqlite`.
3. Install PHP on the offline device, then run `php artisan serve` from the project folder.

Composer and Node.js aren't needed to run the map, only to install or rebuild it.

## Changing the map area

The included basemap covers only 53.02–53.21°N, 49.80–50.14°E, and the map view is locked to that area. A flight elsewhere needs its own basemap, which requires internet once:

1. Download the tiles, giving the area as west, south, east, north:

   ```bash
   npm run tiles:extract -- --bbox=49.80,53.02,50.14,53.21
   ```

2. Set `TILE_BOUNDS` in `resources/js/flight-map.js` to the same area. It uses `[south, west], [north, east]` order.

3. Rebuild the page:

   ```bash
   npm run build
   ```

The map data comes from [OpenStreetMap](https://www.openstreetmap.org/copyright) through [Protomaps](https://protomaps.com/).

## Troubleshooting

| Problem | Fix |
| --- | --- |
| `Your Composer dependencies require a PHP version` | Install PHP 8.4 or newer. |
| `could not find driver` | Enable `pdo_sqlite` in `php.ini`. |
| The page says "No flights imported" | Run `php artisan gnss:import`. |
| `Unable to locate file in Vite manifest` | Run `npm run build`. |
| Port 8000 is already in use | Run `php artisan serve --port=8001` and open that port instead. |
| Errors about missing files or paths during install on Windows | Move the project to a shorter path such as `C:\map`, then run `composer run setup` again. |

## Development

```bash
php artisan test --compact
vendor/bin/pint --dirty
```
