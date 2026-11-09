<?php

namespace Omnitravel\Navitia\Tests;

use Omnitravel\Exception\InvalidConfigException;
use Omnitravel\Exception\ProviderException;
use Omnitravel\Exception\UnreachableException;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Place;
use Omnitravel\Model\Stop;
use Omnitravel\Model\StopKind;
use Omnitravel\Navitia\Modes;
use Omnitravel\Navitia\NavitiaFactory;
use Omnitravel\Navitia\NavitiaProvider;
use Omnitravel\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Navitia's answers written from its Swagger schema (api.navitia.io/v1/schema,
 * read on 2026-11-09: Passage, StopDateTime, Journey, Section, PlaceNearby,
 * Place, Line, StopArea, Network) on SNCF's coverage; no call made (a key is
 * needed). Every test checks the exact request: path, query, Authorization.
 */
final class NavitiaProviderTest extends TestCase
{
    private const TOKEN = 'not-a-real-navitia-token';
    private const BASE = 'https://api.sncf.com/v1/coverage/sncf/';

    public function testAStopAreasDeparturesInRealTime(): void
    {
        $http = self::http();
        $departures = self::provider($http)->departures(new DepartureQuery('stop_area:SNCF:87391003', new \DateTimeImmutable('2026-11-09T13:00:00Z'), 3));

        self::assertSame(['N', 'Paris - Angers - Nantes'], array_map(static fn (Departure $d) => $d->line?->name, $departures), 'by the time to go by: the train leaves late');
        $train = $departures[1];
        self::assertSame(['Paris - Angers - Nantes', 'Angers Saint-Laud (Angers)', '8807', Mode::LongDistanceRail], [$train->line?->name, $train->headsign, $train->number, $train->mode()]);
        self::assertSame(['2026-11-09T13:02:00+00:00', '2026-11-09T13:10:00+00:00', 480, true], [$train->scheduled->format(\DATE_ATOM), $train->expected?->format(\DATE_ATOM), $train->delay(), $train->realtime]);
        self::assertSame(['4', 'stop_point:SNCF:87391003:LongDistanceTrain', 'stop_area:SNCF:87391003'], [$train->platform, $train->stop->id, $train->stop->parentId]);
        self::assertSame('vehicle_journey:SNCF:2026-11-09:8807:1187:Train', $train->tripId);
        self::assertSame('TGV INOUI', $train->line?->operator->name ?? null);
        $rer = $departures[0];
        self::assertSame(['N', '#00A88F', '#FFFFFF', Mode::Suburban, null, false], [$rer->line?->name, $rer->line?->color, $rer->line?->textColor, $rer->mode(), $rer->expected, $rer->realtime]);
        self::assertSame('Transilien', $rer->line?->operator->name ?? null);

        self::assertCount(1, $http->requests);
        self::assertSame(self::BASE.'stop_areas/stop_area:SNCF:87391003/departures', $http->requests[0]['url']);
        self::assertSame(['from_datetime' => '20261109T140000', 'count' => 3, 'data_freshness' => 'realtime', 'depth' => 2], $http->requests[0]['options']['query'] ?? null);
        self::assertSame(self::TOKEN, $http->header('Authorization'));
    }

    public function testArrivalsAtAStopPoint(): void
    {
        $http = self::http();
        $arrivals = self::provider($http)->departures(new DepartureQuery('stop_point:SNCF:87391003:LongDistanceTrain', new \DateTimeImmutable('2026-11-09T13:00:00Z'), window: 3600, arrivals: true));

        self::assertCount(1, $arrivals);
        self::assertSame(['8820', '7', true, 0], [$arrivals[0]->number, $arrivals[0]->platform, $arrivals[0]->arrival, $arrivals[0]->delay()]);
        self::assertSame(self::BASE.'stop_points/stop_point:SNCF:87391003:LongDistanceTrain/arrivals', $http->requests[0]['url']);
        self::assertSame(['from_datetime' => '20261109T140000', 'count' => 10, 'duration' => 3600, 'data_freshness' => 'realtime', 'depth' => 2], $http->requests[0]['options']['query'] ?? null);
    }

    public function testAJourneyItsLegsAndItsDelay(): void
    {
        $http = self::http();
        $journeys = self::provider($http)->journeys(new JourneyQuery(Place::at(48.8432, 2.3245, 'Home'), new Place('Angers Saint-Laud', stop: new Stop('stop_area:SNCF:87484006', 'Angers Saint-Laud', 'navitia')), new \DateTimeImmutable('2026-11-09T13:30:00+01:00'), modes: [Mode::HighSpeedRail, Mode::RegionalRail], limit: 2));

        self::assertCount(1, $journeys);
        $journey = $journeys[0];
        self::assertSame([Mode::Walk, Mode::LongDistanceRail, Mode::Walk], array_map(static fn ($leg) => $leg->mode, $journey->legs), 'the wait is not a leg');
        self::assertSame(['2026-11-09T12:55:00+00:00', '2026-11-09T14:41:00+00:00', 0], [$journey->departure()->format(\DATE_ATOM), $journey->arrival()->format(\DATE_ATOM), $journey->transfers()]);
        $train = $journey->legs[1];
        self::assertSame(['8807', 'Angers Saint-Laud (Angers)', 480, true, '4'], [$train->number, $train->headsign, $train->delay(), $train->realtime, $train->from->platform]);
        self::assertSame(['Le Mans'], array_map(static fn (Place $p) => $p->name, $train->stops));
        self::assertSame('2026-11-09T14:01:00+00:00', $train->stops[0]->arrival?->format(\DATE_ATOM));
        self::assertCount(3, $train->path());
        self::assertEqualsWithDelta(0.192246, $train->path()[1]->longitude, 1e-6);
        self::assertSame([412.0, 'Rue de l\'Arrivée (Paris)'], [$journey->legs[0]->distance, $journey->legs[0]->from->name]);
        self::assertSame(StopKind::Station, $journey->legs[2]->to->stop?->kind);

        self::assertSame(self::BASE.'journeys?allowed_id[]=physical_mode%3ALongDistanceTrain&allowed_id[]=physical_mode%3ALocalTrain', $http->requests[0]['url']);
        self::assertSame(['from' => '2.3245;48.8432', 'to' => 'stop_area:SNCF:87484006', 'datetime' => '20261109T133000', 'count' => 2, 'data_freshness' => 'realtime'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testAJourneyArrivingByWithoutTransfersInAWheelchair(): void
    {
        $http = self::http();
        self::provider($http)->journeys(new JourneyQuery(Place::at(48.8432, 2.3245), Place::at(47.4645, -0.5566), new \DateTimeImmutable('2026-11-09T16:00:00Z'), arriveBy: true, maxTransfers: 0, limit: 1, wheelchair: true));

        self::assertSame(self::BASE.'journeys', $http->requests[0]['url']);
        self::assertSame(['from' => '2.3245;48.8432', 'to' => '-0.5566;47.4645', 'datetime' => '20261109T170000', 'datetime_represents' => 'arrival', 'max_nb_transfers' => 0, 'count' => 1, 'wheelchair' => 'true', 'data_freshness' => 'realtime'], $http->requests[0]['options']['query'] ?? null);
    }

    public function testAStopAreaNearbyStopsAndASearch(): void
    {
        $http = self::http();
        $provider = self::provider($http);

        $stop = $provider->stop('stop_area:SNCF:87391003');
        self::assertNotNull($stop);
        self::assertSame(['Paris Montparnasse', StopKind::Station, 'Europe/Paris', [Mode::LongDistanceRail, Mode::Suburban]], [$stop->name, $stop->kind, $stop->timezone, $stop->modes]);
        self::assertSame(self::BASE.'stop_areas/stop_area:SNCF:87391003', $http->requests[0]['url']);
        self::assertNull($provider->stop('stop_area:SNCF:00000000'), '404: not known');

        $near = $provider->nearby(new Coordinates(48.8432, 2.3245), 700, 5);
        self::assertSame(['stop_area:SNCF:87391003', 'stop_area:SNCF:87391102'], array_map(static fn (Stop $s) => $s->id, $near), 'stop areas only, nearest first');
        self::assertEqualsWithDelta(369, $near[0]->distance, 5);
        self::assertSame(self::BASE.'coords/2.3245;48.8432/places_nearby', $http->requests[2]['url']);
        self::assertSame(['type[]' => 'stop_area', 'distance' => 700, 'count' => 5, 'depth' => 1], $http->requests[2]['options']['query'] ?? null);

        $found = $provider->searchStops('Angers', 5, new Coordinates(47.4712, -0.5518));
        self::assertSame(['stop_area:SNCF:87484006'], array_map(static fn (Stop $s) => $s->id, $found), 'the city is not a stop');
        self::assertEqualsWithDelta(826, $found[0]->distance, 5);
        self::assertSame(['q' => 'Angers', 'type[]' => 'stop_area', 'count' => 5, 'from' => '-0.5518;47.4712', 'depth' => 1], $http->requests[3]['options']['query'] ?? null);
    }

    public function testLinesPageByPageAndALinesStops(): void
    {
        $http = self::http();
        $provider = self::provider($http);
        $lines = $provider->lines();

        self::assertSame(['N', 'Paris - Angers - Nantes', 'TER'], array_map(static fn (Line $l) => $l->name, $lines), 'a line without a code is named by its name');
        self::assertSame([Mode::Suburban, Mode::LongDistanceRail, Mode::RegionalRail], array_map(static fn (Line $l) => $l->mode, $lines));
        self::assertCount(2, $http->requests);
        self::assertSame(['count' => 200, 'depth' => 1], $http->requests[0]['options']['query'] ?? null);
        self::assertSame(['count' => 200, 'start_page' => 1, 'depth' => 1], $http->requests[1]['options']['query'] ?? null);

        self::assertSame(['Paris Montparnasse', 'Le Mans', 'Angers Saint-Laud', 'Nantes'], array_map(static fn (Stop $s) => $s->name, $provider->lineStops('line:SNCF:FR:Line::PARIS-NANTES:')));
        self::assertSame(self::BASE.'lines/line:SNCF:FR:Line::PARIS-NANTES:/stop_areas', $http->requests[2]['url']);
        $provider->lineStops('line:SNCF:FR:Line::PARIS-NANTES:', 'route:SNCF:FR:Line::PARIS-NANTES:');
        self::assertSame(self::BASE.'routes/route:SNCF:FR:Line::PARIS-NANTES:/stop_areas', $http->requests[3]['url']);

        self::assertSame('Transilien', $provider->line('line:SNCF:N')?->operator->name ?? null);
        self::assertSame(['N', 'Paris - Angers - Nantes'], array_map(static fn (Line $l) => $l->name, $provider->stopLines('stop_area:SNCF:87391003')));
        self::assertSame(self::BASE.'stop_areas/stop_area:SNCF:87391003/lines', $http->requests[5]['url']);
        self::assertSame(['network:SNCF:TGV', 'network:SNCF:Transilien'], array_map(static fn ($o) => $o->id, $provider->operators()));
    }

    public function testTheTokenIsSentAndNeverShownInAnError(): void
    {
        $http = RecordedHttpClient::routes(['~/departures~' => [401, '{"message": "no token '.self::TOKEN.' found"}']]);
        try {
            self::provider($http)->departures(new DepartureQuery('stop_area:SNCF:87391003'));
            self::fail('no exception');
        } catch (ProviderException $e) {
            self::assertStringStartsWith('Navitia answered 401 for GET /v1/coverage/sncf/stop_areas/stop_area:SNCF:87391003/departures.', $e->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $e);
        }
        self::assertSame(self::TOKEN, $http->header('Authorization'));

        // Unreached: nothing of the chain - messages, copies, traces' arguments, where the request's headers stood - holds the token.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $mock = new MockHttpClient(static fn (string $method, string $url): never => throw new TransportException(\sprintf('Could not resolve host: api.sncf.com for "%s".', $url)));
        try {
            self::provider($mock)->departures(new DepartureQuery('stop_area:SNCF:87391003'));
            self::fail('no exception');
        } catch (UnreachableException $e) {
            self::assertSame('Navitia could not be reached for GET /v1/coverage/sncf/stop_areas/stop_area:SNCF:87391003/departures: Could not resolve host: api.sncf.com.', $e->getMessage());
            for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                self::assertStringNotContainsString(self::TOKEN, $one->getMessage());
                self::assertStringNotContainsString(self::TOKEN, print_r($one->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', false === $ignoreArgs ? '1' : $ignoreArgs);
        }
    }

    public function testTheTokenAndTheCoverageAreRequired(): void
    {
        try {
            (new NavitiaFactory(self::http()))->create(['coverage' => 'sncf']);
            self::fail('no exception');
        } catch (InvalidConfigException $e) {
            self::assertSame('The "navitia" provider needs: token.', $e->getMessage());
        }

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('a coverage such as "fr-idf" or "sncf", not "../sncf"');
        (new NavitiaFactory(self::http()))->create(['token' => self::TOKEN, 'coverage' => '../sncf']);
    }

    public function testCapabilities(): void
    {
        $capabilities = self::provider(self::http(), ['attribution' => 'Données SNCF (Licence Ouverte)'])->capabilities();

        self::assertSame(['timetables', 'itineraries', 'network'], $capabilities->contracts());
        self::assertFalse($capabilities->keyless);
        self::assertTrue($capabilities->lines);
        self::assertSame('Données SNCF (Licence Ouverte)', $capabilities->attribution);
    }

    public function testModes(): void
    {
        self::assertSame([Mode::Tram, Mode::Suburban, Mode::RegionalRail, Mode::AerialLift, null], array_map(Modes::physical(...), ['physical_mode:Tramway', 'physical_mode:RapidTransit', 'LocalTrain', 'physical_mode:SuspendedCableCar', 'physical_mode:Hovercraft']));
        self::assertSame([Mode::Walk, Mode::Bike, Mode::Car, Mode::Walk], array_map(Modes::street(...), ['walking', 'bss', 'car', 'teleport']));
        self::assertSame(['physical_mode:Bus', 'physical_mode:BusRapidTransit', 'physical_mode:Shuttle', 'physical_mode:Metro'], Modes::toIds([Mode::Bus, Mode::Metro]));
    }

    /** @param array<string, mixed> $options */
    private static function provider(HttpClientInterface $http, array $options = []): NavitiaProvider
    {
        $provider = (new NavitiaFactory($http))->create($options + ['token' => self::TOKEN, 'coverage' => 'sncf', 'base_uri' => 'https://api.sncf.com/v1/']);
        self::assertInstanceOf(NavitiaProvider::class, $provider);

        return $provider;
    }

    private static function http(): RecordedHttpClient
    {
        $f = __DIR__.'/Fixtures/';

        return RecordedHttpClient::routes([
            '~^GET \S+/stop_areas/stop_area:SNCF:87391003/departures\?~' => [200, '@'.$f.'departures-montparnasse.json'],
            '~^GET \S+/stop_points/stop_point:SNCF:87391003:LongDistanceTrain/arrivals\?~' => [200, '@'.$f.'arrivals-montparnasse.json'],
            '~^GET \S+/journeys\?~' => [200, '@'.$f.'journeys-paris-angers.json'],
            '~^GET \S+/stop_areas/stop_area:SNCF:87391003/lines\?~' => [200, '@'.$f.'lines-page-0.json'],
            '~^GET \S+/stop_areas/stop_area:SNCF:87391003\?~' => [200, '@'.$f.'stop-area-montparnasse.json'],
            '~^GET \S+/places_nearby\?~' => [200, '@'.$f.'places-nearby.json'],
            '~^GET \S+/places\?q=Angers~' => [200, '@'.$f.'places-angers.json'],
            '~^GET \S+/coverage/sncf/lines\?count=200&start_page=1~' => [200, '@'.$f.'lines-page-1.json'],
            '~^GET \S+/coverage/sncf/lines\?count=200~' => [200, '@'.$f.'lines-page-0.json'],
            '~^GET \S+/lines/line:SNCF:N\?~' => [200, '@'.$f.'lines-page-0.json'],
            '~^GET \S+/(lines|routes)/[^/]+/stop_areas\?~' => [200, '@'.$f.'line-stop-areas.json'],
            '~^GET \S+/networks\?~' => [200, '@'.$f.'networks.json'],
        ]);
    }
}
