<?php

namespace App\Services\Dashboard;

use App\Models\City;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The weather now in a city, for the dashboard's glance card. Open-Meteo
 * (free for non-commercial use, no key), and MET Norway when it does not
 * answer; at most one answer per city every CACHE_MINUTES, asked by the
 * server: the browser never calls either, and the only thing sent is the
 * city's coordinates, never anything about the person. Both failing (no
 * network, slow answers, an unknown city) is simply null, and the card says
 * so quietly. Both ask to be credited where their data is shown (the card's
 * city line).
 */
class WeatherFeed
{
    public const CACHE_MINUTES = 30;

    public const TIMEOUT_SECONDS = 3;

    /** City slug => [latitude, longitude]. The national office (SYR) is Damascus. */
    public const PLACES = [
        'SYR' => [33.5138, 36.2765],
        'DMS' => [33.5138, 36.2765],
        'ALP' => [36.2021, 37.1343],
        'HMS' => [34.7324, 36.7137],
        'LAT' => [35.5317, 35.7914],
        'TAR' => [34.8890, 35.8866],
        'HAM' => [35.1318, 36.7578],
        'DAR' => [32.6189, 36.1021],
        'SWD' => [32.7090, 36.5695],
        'QNT' => [33.1255, 35.8243],
    ];

    /**
     * Open-Meteo first; when it does not answer, MET Norway (the Norwegian
     * Meteorological Institute's public service), so one source being down
     * does not empty the card. `source` names the one that answered, for the
     * credit line both ask for (CC BY 4.0).
     *
     * @return array{temp: int, kind: string, day: bool, source: string}|null kind: clear | partly | cloudy | fog | drizzle | rain | snow | storm; source: open-meteo | met-norway
     */
    public function now(?City $city): ?array
    {
        $place = $city ? (self::PLACES[$city->slug] ?? null) : null;

        if ($place === null) {
            return null;
        }

        $key = 'dashboard.weather.'.$city->slug;
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['source'])) {
            return $cached;
        }

        $weather = $this->openMeteo($place) ?? $this->metNorway($place);

        if ($weather !== null) {
            Cache::put($key, $weather, now()->addMinutes(self::CACHE_MINUTES));
        }

        return $weather;
    }

    /** MET Norway's symbol code ("partlycloudy_day", "lightrainshowers_night", …) as one of the same eight kinds. */
    public static function kindOfSymbol(string $symbol): string
    {
        $name = preg_replace('/_(day|night|polartwilight)$/', '', $symbol);

        return match (true) {
            $name === 'clearsky' => 'clear',
            $name === 'fair' || $name === 'partlycloudy' => 'partly',
            $name === 'fog' => 'fog',
            str_contains($name, 'thunder') => 'storm',
            str_contains($name, 'snow') || str_contains($name, 'sleet') => 'snow',
            str_starts_with($name, 'light') && str_contains($name, 'rain') => 'drizzle',
            str_contains($name, 'rain') => 'rain',
            default => 'cloudy',
        };
    }

    /** The WMO weather code (Open-Meteo's weather_code) as one of eight kinds. */
    public static function kind(int $code): string
    {
        return match (true) {
            $code === 0 => 'clear',
            $code <= 2 => 'partly',
            $code === 3 => 'cloudy',
            $code === 45 || $code === 48 => 'fog',
            $code >= 51 && $code <= 57 => 'drizzle',
            ($code >= 61 && $code <= 67) || ($code >= 80 && $code <= 82) => 'rain',
            ($code >= 71 && $code <= 77) || $code === 85 || $code === 86 => 'snow',
            $code >= 95 => 'storm',
            default => 'cloudy',
        };
    }

    /** @return array{temp: int, kind: string, day: bool, source: string}|null */
    private function openMeteo(array $place): ?array
    {
        try {
            $current = Http::timeout(self::TIMEOUT_SECONDS)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $place[0],
                    'longitude' => $place[1],
                    'current' => 'temperature_2m,weather_code,is_day',
                    'timezone' => 'Asia/Damascus',
                ])
                ->throw()
                ->json('current');
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (! is_array($current) || ! is_numeric($current['temperature_2m'] ?? null) || ! is_numeric($current['weather_code'] ?? null)) {
            return null;
        }

        return [
            'temp' => (int) round((float) $current['temperature_2m']),
            'kind' => self::kind((int) $current['weather_code']),
            'day' => (bool) ($current['is_day'] ?? true),
            'source' => 'open-meteo',
        ];
    }

    /**
     * MET Norway's Locationforecast (compact). Its terms ask every caller to
     * name itself in the User-Agent (the site's address; a generic one is
     * blocked) and for coordinates of at most four decimals.
     *
     * @return array{temp: int, kind: string, day: bool, source: string}|null
     */
    private function metNorway(array $place): ?array
    {
        try {
            $now = Http::timeout(self::TIMEOUT_SECONDS)
                ->withUserAgent('HopeHR/1.0 (+'.config('app.url').')')
                ->get('https://api.met.no/weatherapi/locationforecast/2.0/compact', [
                    'lat' => round($place[0], 4),
                    'lon' => round($place[1], 4),
                ])
                ->throw()
                ->json('properties.timeseries.0.data');
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $temp = $now['instant']['details']['air_temperature'] ?? null;
        $symbol = $now['next_1_hours']['summary']['symbol_code'] ?? $now['next_6_hours']['summary']['symbol_code'] ?? null;

        if (! is_numeric($temp) || ! is_string($symbol)) {
            return null;
        }

        return [
            'temp' => (int) round((float) $temp),
            'kind' => self::kindOfSymbol($symbol),
            'day' => ! str_ends_with($symbol, '_night'),
            'source' => 'met-norway',
        ];
    }
}
