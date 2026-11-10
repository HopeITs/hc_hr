<?php

namespace Tests\Feature\Dashboard;

use App\Livewire\Dashboard\GlanceCard;
use App\Models\City;
use App\Services\Dashboard\WeatherFeed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesContractFixtures;
use Tests\TestCase;

/**
 * "Today at a glance" (2026-11-03): the weather in the viewer's work city
 * (WeatherFeed, Open-Meteo, cached per city), an analogue clock, the date and
 * the twelve-hour time. The weather service is faked: no test reaches it.
 */
class GlanceCardTest extends TestCase
{
    use CreatesContractFixtures, RefreshDatabase;

    private City $alp;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Cache::flush();
        // Nothing here may reach a real weather service.
        Http::preventStrayRequests();
        // 10:08 in Damascus (UTC+3), on 6 March.
        $this->travelTo(CarbonImmutable::parse('2026-03-06 07:08:00', 'UTC'));
        $this->alp = $this->makeCity('ALP', '001');
    }

    public function test_it_shows_the_weather_in_the_viewers_city_with_the_clock_and_date(): void
    {
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 18.4, 'weather_code' => 3, 'is_day' => 1]])]);
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(GlanceCard::class)
            ->assertSee(__('dashboard.glance.sky.cloudy'))
            ->assertSeeHtml('<span>18</span>')
            ->assertSee($this->alp->name)
            ->assertSee('10:08')
            ->assertSee('Mar')
            ->assertSeeHtml('x-data="hcClock(')
            // The scene behind the words follows the weather: drifting clouds, no rain.
            ->assertSeeHtml('class="hc-wx hc-wx--cloudy"')
            ->assertSeeHtml('hc-wx-cloud')
            ->assertDontSeeHtml('hc-wx-drop')
            // The credit the source asks for, as the tile's hover hint only: no line on the card.
            ->assertSeeHtml('title="'.__('dashboard.glance.source', ['name' => 'Open-Meteo']).'"')
            ->assertDontSeeHtml('data-testid="glance-credit"');

        // Only the city's coordinates are sent: Aleppo's.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'latitude=36.2021') && str_contains($request->url(), 'longitude=37.1343'));
    }

    public function test_the_weather_is_asked_once_per_city_and_a_failure_is_quiet(): void
    {
        // The first answer is rain; the next one (after the cache is gone) a failure.
        Http::fake([
            'api.open-meteo.com/*' => Http::sequence()
                ->push(['current' => ['temperature_2m' => 9, 'weather_code' => 61, 'is_day' => 1]])
                ->push('down', 503),
            'api.met.no/*' => Http::response('down', 503),
        ]);
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(GlanceCard::class)->assertSee(__('dashboard.glance.sky.rain'));
        Livewire::actingAs($user)->test(GlanceCard::class)->assertSee(__('dashboard.glance.sky.rain'));
        Http::assertSentCount(1);

        Cache::flush();

        // Both down: one quiet line.
        Livewire::actingAs($user)->test(GlanceCard::class)
            ->assertSee(__('dashboard.glance.no-weather'))
            ->assertDontSeeHtml('data-testid="glance-scene"')
            ->assertSee('10:08')
            ->assertDontSeeHtml('x-ui.load-error');
    }

    public function test_met_norway_answers_when_open_meteo_does_not(): void
    {
        Http::fake([
            'api.open-meteo.com/*' => Http::response('down', 503),
            'api.met.no/*' => Http::response(['properties' => ['timeseries' => [[
                'time' => '2026-03-06T07:00:00Z',
                'data' => ['instant' => ['details' => ['air_temperature' => 12.6]], 'next_1_hours' => ['summary' => ['symbol_code' => 'lightrainshowers_night']]],
            ]]]]),
        ]);
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);

        Livewire::actingAs($user)->test(GlanceCard::class)
            ->assertSee(__('dashboard.glance.sky.drizzle'))
            ->assertSeeHtml('class="hc-wx hc-wx--drizzle"')
            ->assertSeeHtml('hc-wx-drop')
            ->assertSeeHtml('<span>13</span>')
            ->assertSeeHtml('title="'.__('dashboard.glance.source', ['name' => 'MET Norway']).'"');

        // Its terms: the site names itself, and four decimals at most.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.met.no')
            && str_starts_with($request->header('User-Agent')[0] ?? '', 'HopeHR/1.0 (+')
            && str_contains($request->url(), 'lat=36.2021') && str_contains($request->url(), 'lon=37.1343'));
    }

    /**
     * The scene behind the weather, as settled on 2026-11-03: one cloud wherever there are
     * clouds, the sun only by day when clear or partly cloudy, stars only at night (no
     * moon), and every line drawn at one width (non-scaling strokes).
     */
    public function test_each_weather_draws_its_settled_scene(): void
    {
        $expected = [
            // [kind, day] => [sun, clouds, stars, fog bands, drops, flakes, flash]
            'clear-day' => [['clear', true], [1, 0, 0, 0, 0, 0, 0]],
            'clear-night' => [['clear', false], [0, 0, 5, 0, 0, 0, 0]],
            'partly' => [['partly', true], [1, 1, 0, 0, 0, 0, 0]],
            'cloudy' => [['cloudy', true], [0, 1, 0, 0, 0, 0, 0]],
            'fog' => [['fog', true], [0, 1, 0, 3, 0, 0, 0]],
            'drizzle' => [['drizzle', true], [0, 1, 0, 0, 8, 0, 0]],
            'rain' => [['rain', true], [0, 1, 0, 0, 12, 0, 0]],
            'snow' => [['snow', true], [0, 1, 0, 0, 0, 12, 0]],
            'storm' => [['storm', true], [0, 1, 0, 0, 12, 0, 1]],
        ];
        $now = CarbonImmutable::parse('2026-03-06 10:08', 'Asia/Damascus');

        foreach ($expected as $scene => [[$kind, $day], $counts]) {
            $html = view('livewire.dashboard.glance-card', [
                'loadFailed' => false,
                'config' => ['locale' => 'en-GB', 'zone' => 'Asia/Damascus', 'day' => null, 'text' => ['aria' => '']],
                'now' => $now,
                'city' => 'Aleppo',
                'weather' => ['temp' => 20, 'kind' => $kind, 'day' => $day, 'source' => 'open-meteo'],
                'angles' => ['h' => 0, 'm' => 0, 's' => 0],
            ])->render();

            $this->assertStringContainsString('class="hc-wx hc-wx--'.$scene.'"', $html, $scene);
            $this->assertSame($counts, [
                substr_count($html, 'class="hc-wx-sun"'),
                substr_count($html, 'class="hc-wx-cloud"'),
                substr_count($html, 'class="hc-wx-spark"'),
                substr_count($html, 'class="hc-wx-fog"'),
                substr_count($html, 'class="hc-wx-drop"'),
                substr_count($html, 'class="hc-wx-flake"'),
                substr_count($html, 'class="hc-wx-flash"'),
            ], $scene);
            $this->assertStringNotContainsString('hc-wx-moon', $html, $scene);

            // One line width: the sun's and the cloud's strokes do not scale with their size.
            if ($counts[0] + $counts[1] > 0) {
                $this->assertStringContainsString('vector-effect="non-scaling-stroke"', $html, $scene);
            }
        }
    }

    public function test_weather_codes_become_one_of_eight_kinds(): void
    {
        $this->assertSame(
            ['clear', 'partly', 'partly', 'cloudy', 'fog', 'drizzle', 'rain', 'snow', 'snow', 'storm'],
            array_map([WeatherFeed::class, 'kindOfSymbol'], ['clearsky_day', 'fair_night', 'partlycloudy_day', 'cloudy', 'fog', 'lightrain', 'heavyrainshowers_day', 'snow', 'lightsleet', 'rainandthunder']),
        );

        $this->assertSame(
            ['clear', 'partly', 'cloudy', 'fog', 'drizzle', 'rain', 'rain', 'snow', 'storm'],
            array_map([WeatherFeed::class, 'kind'], [0, 2, 3, 45, 53, 63, 81, 73, 95]),
        );
        $this->assertNull(app(WeatherFeed::class)->now(null));
    }

    public function test_it_is_a_tile_in_the_gallery_with_its_names(): void
    {
        $user = $this->makeUser($this->alp, 'employee', ['leaves.view-own']);
        Http::fake(['api.open-meteo.com/*' => Http::response(['current' => ['temperature_2m' => 20, 'weather_code' => 0, 'is_day' => 1]])]);

        $this->actingAs($user)->get(route('dashboard.window', ['tile' => 'glance']))->assertOk()->assertSee(__('dashboard.glance.sky.clear'));

        // On everyone's dashboard, first, at exactly 4×2 (sized, never grown into a gap).
        $first = (new \App\Support\Dashboard\DashboardLayout)->defaults($user)[0];
        $this->assertSame(['glance', '4x2', true, false], [$first['key'], $first['size'], $first['fixed'] ?? false, $first['hidden']]);

        // One size only: no grip, and any other size comes back as 4×2.
        $layout = new \App\Support\Dashboard\DashboardLayout;
        $this->assertFalse($layout->canResize('glance'));
        $this->assertSame(['4x2', '4x2'], [$layout->size('glance', '1x1'), $layout->size('glance', '12x4')]);
        $this->assertStringNotContainsString('aria-label="Resize Weather, date and time', Livewire::actingAs($user)->test(\App\Livewire\Dashboard\Index::class)->html());

        foreach (['glance.source', 'glance.sources.open-meteo', 'glance.sources.met-norway', 'tiles.glance', 'glance.no-weather', 'glance.degrees', 'glance.sky.clear', 'glance.sky.storm'] as $key) {
            foreach (['en', 'ar'] as $locale) {
                $this->assertNotSame('dashboard.'.$key, __('dashboard.'.$key, [], $locale), "{$key} {$locale}");
            }
        }
    }
}
