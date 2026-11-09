<?php

namespace Omnitravel\Navitia;

use Omnitravel\Capabilities;
use Omnitravel\Exception\NotSupportedException;
use Omnitravel\Http\Data;
use Omnitravel\Http\JsonClient;
use Omnitravel\ItinerariesInterface;
use Omnitravel\Model\Coordinates;
use Omnitravel\Model\Departure;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Model\Journey;
use Omnitravel\Model\JourneyQuery;
use Omnitravel\Model\Leg;
use Omnitravel\Model\Line;
use Omnitravel\Model\Mode;
use Omnitravel\Model\Operator;
use Omnitravel\Model\Place;
use Omnitravel\Model\Polyline;
use Omnitravel\Model\Stop;
use Omnitravel\Model\StopKind;
use Omnitravel\NetworkInterface;
use Omnitravel\TimetablesInterface;

/**
 * Navitia's v1 API on one coverage (navitia.io's "fr-idf", "fr-ne"...;
 * SNCF's "sncf" at api.sncf.com, the same API): a stop area's or a stop
 * point's departures and arrivals in real time, journeys, the stop areas
 * near a point and by name, the coverage's lines, a line's stop areas, the
 * lines of a stop area, its networks. The key goes in the Authorization
 * header of every request. Navitia writes and reads times in the
 * coverage's time zone, without an offset ("20261109T143000").
 */
final class NavitiaProvider implements TimetablesInterface, ItinerariesInterface, NetworkInterface
{
    private const FORMAT = 'Ymd\THis';
    private const PAGES = 20;

    public function __construct(
        private readonly JsonClient $json,
        private readonly string $baseUri,
        private readonly string $coverage,
        private readonly \DateTimeZone $timezone = new \DateTimeZone('Europe/Paris'),
        private readonly ?string $attribution = null,
    ) {
    }

    public function getName(): string
    {
        return 'navitia';
    }

    public function getTitle(): string
    {
        return 'Navitia';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            timetables: true,
            arrivals: true,
            realtime: true,
            itineraries: true,
            network: true,
            nearby: true,
            stopSearch: true,
            lines: true,
            platforms: true,
            modes: [Mode::Tram, Mode::Metro, Mode::Rail, Mode::Suburban, Mode::RegionalRail, Mode::LongDistanceRail, Mode::Bus, Mode::Trolleybus, Mode::Coach, Mode::Ferry, Mode::AerialLift, Mode::Funicular, Mode::Air, Mode::OnDemand, Mode::Walk, Mode::Bike, Mode::Car],
            attribution: $this->attribution ?? 'Navitia (https://navitia.io)',
            terms: ['https://doc.navitia.io/'],
        );
    }

    public function departures(DepartureQuery $query): array
    {
        $key = $query->arrivals ? 'arrivals' : 'departures';
        $data = $this->get($this->objectPath($query->stop).'/'.$key, [
            'from_datetime' => $this->format($query->from()),
            'count' => $query->limit,
            'duration' => $query->window,
            'data_freshness' => 'realtime',
            'depth' => 2,
        ]);
        if (null === $data) {
            return [];
        }
        $zone = $this->zone($data);

        $departures = [];
        foreach ($data->list($key) as $passage) {
            $time = $passage->data('stop_date_time');
            $field = $query->arrivals ? 'arrival_date_time' : 'departure_date_time';
            $actual = $this->date($time->string($field), $zone);
            $scheduled = $this->date($time->string('base_'.$field), $zone) ?? $actual;
            if (null === $scheduled) {
                continue;
            }
            $realtime = 'realtime' === $time->string('data_freshness');
            $info = $passage->data('display_informations');
            $line = $this->passageLine($passage);
            $departure = new Departure(
                $this->stopPoint($passage->data('stop_point')),
                $line,
                $info->string('direction') ?? $passage->string('route.direction.name') ?? '',
                $scheduled,
                $this->getName(),
                $realtime ? $actual : null,
                $realtime,
                platform: $passage->string('stop_point.platform_code'),
                tripId: self::linked($info, 'vehicle_journey'),
                number: $info->string('trip_short_name') ?? $info->string('headsign'),
                mode: $line?->mode,
                arrival: $query->arrivals,
            );
            if ($query->accepts($departure)) {
                $departures[] = $departure;
            }
        }

        return Departure::sorted($departures);
    }

    public function journeys(JourneyQuery $query): array
    {
        $allowed = array_map(static fn (string $id) => 'allowed_id[]='.rawurlencode($id), Modes::toIds($query->modes));
        $data = $this->get('journeys'.([] === $allowed ? '' : '?'.implode('&', $allowed)), [
            'from' => $this->placeParameter($query->from),
            'to' => $this->placeParameter($query->to),
            'datetime' => null === $query->time ? null : $this->format($query->time),
            'datetime_represents' => $query->arriveBy ? 'arrival' : null,
            'max_nb_transfers' => $query->maxTransfers,
            'count' => $query->limit,
            'wheelchair' => $query->wheelchair ? 'true' : null,
            'data_freshness' => 'realtime',
        ]);
        if (null === $data) {
            return [];
        }
        $zone = $this->zone($data);

        $journeys = [];
        foreach ($data->list('journeys') as $journey) {
            $legs = [];
            foreach ($journey->list('sections') as $section) {
                if (null !== $leg = $this->leg($section, $zone)) {
                    $legs[] = $leg;
                }
            }
            if ([] !== $legs) {
                $journeys[] = new Journey($legs, $this->getName(), $journey->int('nb_transfers'));
            }
        }

        return \array_slice($journeys, 0, $query->limit);
    }

    public function stop(string $id): ?Stop
    {
        $stopPoint = str_starts_with($id, 'stop_point:');
        $data = $this->get($this->objectPath($id), ['depth' => 1]);
        $found = $data?->list($stopPoint ? 'stop_points' : 'stop_areas')[0] ?? null;

        return null === $found ? null : ($stopPoint ? $this->stopPoint($found) : $this->stopArea($found));
    }

    public function nearby(Coordinates $point, int $radius = 500, int $limit = 20): array
    {
        $data = $this->get(\sprintf('coords/%s;%s/places_nearby', self::number($point->longitude), self::number($point->latitude)), [
            'type[]' => 'stop_area',
            'distance' => max(1, $radius),
            'count' => max(1, $limit),
            'depth' => 1,
        ]);
        $stops = [];
        foreach ($data?->list('places_nearby') ?? [] as $place) {
            if ('stop_area' === $place->string('embedded_type')) {
                $stops[] = $this->stopArea($place->data('stop_area'));
            }
        }

        return Stop::nearest($stops, $point, $radius, $limit);
    }

    public function searchStops(string $text, int $limit = 20, ?Coordinates $near = null): array
    {
        if ('' === trim($text)) {
            return [];
        }
        $data = $this->get('places', [
            'q' => $text,
            'type[]' => 'stop_area',
            'count' => max(1, min(100, $limit)),
            'from' => null === $near ? null : self::number($near->longitude).';'.self::number($near->latitude),
            'depth' => 1,
        ]);
        $stops = [];
        foreach ($data?->list('places') ?? [] as $place) {
            if ('stop_area' === $place->string('embedded_type')) {
                $stop = $this->stopArea($place->data('stop_area'));
                $stops[] = null === $near ? $stop : $stop->from($near);
            }
        }

        return \array_slice($stops, 0, $limit);
    }

    /** Every line of the coverage, Navitia's pages read one after the other (200 a page, 20 pages at most). */
    public function lines(): array
    {
        $lines = [];
        for ($page = 0; $page < self::PAGES; ++$page) {
            $data = $this->get('lines', ['count' => 200, 'start_page' => $page ?: null, 'depth' => 1]);
            foreach ($data?->list('lines') ?? [] as $line) {
                $lines[] = $this->lineOf($line);
            }
            $total = $data?->int('pagination.total_result') ?? 0;
            if (null === $data || \count($lines) >= $total || [] === $data->list('lines')) {
                break;
            }
        }

        return $lines;
    }

    public function line(string $id): ?Line
    {
        $line = $this->get($this->objectPath($id, 'lines'), ['depth' => 1])?->list('lines')[0] ?? null;

        return null === $line ? null : $this->lineOf($line);
    }

    /**
     * The line's stop areas as Navitia lists them (not guaranteed in the
     * order vehicles call at them); with a direction, the stop areas of that
     * route (a Navitia route id, "route:...").
     */
    public function lineStops(string $lineId, ?string $direction = null): array
    {
        $path = null === $direction ? $this->objectPath($lineId, 'lines') : $this->objectPath($direction, 'routes');
        $data = $this->get($path.'/stop_areas', ['count' => 200, 'depth' => 1]);

        return array_map($this->stopArea(...), $data?->list('stop_areas') ?? []);
    }

    public function stopLines(string $stopId): array
    {
        $data = $this->get($this->objectPath($stopId).'/lines', ['count' => 200, 'depth' => 1]);

        return array_map($this->lineOf(...), $data?->list('lines') ?? []);
    }

    /** The coverage's networks: an agency of the GTFS feeds Navitia imported is one of them. */
    public function operators(): array
    {
        $data = $this->get('networks', ['count' => 200]);
        $operators = [];
        foreach ($data?->list('networks') ?? [] as $network) {
            $id = $network->string('id');
            if (null !== $id) {
                $operators[] = new Operator($id, $network->string('name') ?? $id, $this->getName(), timezone: $this->timezone->getName());
            }
        }

        return $operators;
    }

    /** @param array<string, string|int|float|bool|null> $query */
    private function get(string $path, array $query = []): ?Data
    {
        return $this->json->get($this->baseUri.'/coverage/'.$this->coverage.'/'.$path, $query);
    }

    /** "stop_areas/stop_area:SNCF:87391003", "stop_points/..." from the id's own type; the collection given otherwise. */
    private function objectPath(string $id, string $collection = 'stop_areas'): string
    {
        foreach (['stop_point:' => 'stop_points', 'stop_area:' => 'stop_areas', 'line:' => 'lines', 'route:' => 'routes'] as $prefix => $type) {
            if (str_starts_with($id, $prefix)) {
                $collection = $type;
            }
        }

        return $collection.'/'.str_replace('%3A', ':', rawurlencode($id));
    }

    private function format(\DateTimeImmutable $time): string
    {
        return $time->setTimezone($this->timezone)->format(self::FORMAT);
    }

    private function zone(Data $data): \DateTimeZone
    {
        try {
            return new \DateTimeZone($data->string('context.timezone') ?? $this->timezone->getName());
        } catch (\Exception) {
            return $this->timezone;
        }
    }

    private function date(?string $value, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!'.self::FORMAT, $value, $zone);

        return false === $date ? null : $date->setTimezone(new \DateTimeZone('UTC'));
    }

    private function lineOf(Data $line): Line
    {
        $id = $line->string('id') ?? $line->string('code') ?? '';
        $mode = null;
        foreach ($line->list('physical_modes') as $physical) {
            $mode ??= Modes::physical($physical->string('id'));
        }
        $network = $line->data('network');

        return new Line(
            $id,
            $line->string('code') ?? $line->string('name') ?? $id,
            $mode ?? Mode::Other,
            $this->getName(),
            $line->string('name'),
            $line->string('color'),
            $line->string('text_color'),
            null === $network->string('id') ? null : new Operator((string) $network->string('id'), $network->string('name') ?? (string) $network->string('id'), $this->getName()),
        );
    }

    /** A passage's line: the route's line at depth 2, else what display_informations says. */
    private function passageLine(Data $passage): ?Line
    {
        $info = $passage->data('display_informations');
        if ($passage->has('route.line.id')) {
            $line = $this->lineOf($passage->data('route.line'));
            $mode = Mode::Other === $line->mode ? self::modeOf($passage) : $line->mode;

            return new Line($line->id, $info->string('code') ?? $line->name, $mode ?? Mode::Other, $this->getName(), $line->longName ?? $info->string('name'), $line->color ?? $info->string('color'), $line->textColor ?? $info->string('text_color'), $line->operator);
        }
        $name = $info->string('code') ?? $info->string('label') ?? $info->string('name');
        if (null === $name) {
            return null;
        }
        $network = $info->string('network');

        return new Line(self::linked($info, 'line') ?? $name, $name, self::modeOf($passage) ?? Mode::Other, $this->getName(), $info->string('name'), $info->string('color'), $info->string('text_color'),
            null === $network ? null : new Operator(self::linked($info, 'network') ?? $network, $network, $this->getName()));
    }

    /** The physical mode a passage or a section links to. */
    private static function modeOf(Data $data): ?Mode
    {
        foreach ([...$data->list('links'), ...$data->list('display_informations.links')] as $link) {
            if ('physical_mode' === $link->string('type') && null !== $mode = Modes::physical($link->string('id'))) {
                return $mode;
            }
        }

        return Modes::physical($data->string('display_informations.physical_mode'));
    }

    /** The id of the object of this type a list of links names. */
    private static function linked(Data $data, string $type): ?string
    {
        foreach ($data->list('links') as $link) {
            if ($type === $link->string('type')) {
                return $link->string('id');
            }
        }

        return null;
    }

    private function stopArea(Data $area): Stop
    {
        $id = $area->string('id') ?? '';

        return new Stop($id, $area->string('name') ?? $area->string('label') ?? $id, $this->getName(), self::coord($area), StopKind::Station,
            timezone: $area->string('timezone'),
            modes: self::modes($area),
            lines: array_map($this->lineOf(...), $area->list('lines')),
        );
    }

    private function stopPoint(Data $point): Stop
    {
        $id = $point->string('id') ?? '';

        return new Stop($id, $point->string('name') ?? $point->string('label') ?? $id, $this->getName(), self::coord($point), StopKind::Stop,
            parentId: $point->string('stop_area.id'),
            platform: $point->string('platform_code'),
            timezone: $point->string('stop_area.timezone'),
            modes: self::modes($point),
        );
    }

    /** @return list<Mode> */
    private static function modes(Data $place): array
    {
        $modes = [];
        foreach ($place->list('physical_modes') as $physical) {
            if (null !== $mode = Modes::physical($physical->string('id'))) {
                $modes[$mode->value] = $mode;
            }
        }

        return array_values($modes);
    }

    private static function coord(Data $place): ?Coordinates
    {
        $lat = $place->float('coord.lat');
        $lon = $place->float('coord.lon');

        return null === $lat || null === $lon || (0.0 === $lat && 0.0 === $lon) ? null : new Coordinates($lat, $lon);
    }

    private function leg(Data $section, \DateTimeZone $zone): ?Leg
    {
        $type = $section->string('type');
        $departure = $this->date($section->string('departure_date_time'), $zone);
        $arrival = $this->date($section->string('arrival_date_time'), $zone);
        if (null === $departure || null === $arrival || \in_array($type, ['waiting', 'boarding', 'alighting', 'stay_in', 'park', 'bss_rent', 'bss_put_back'], true)) {
            return null;
        }
        $transit = \in_array($type, ['public_transport', 'on_demand_transport'], true);
        $line = $transit ? $this->passageLine($section) : null;
        $mode = $transit ? ($line->mode ?? Mode::Other) : Modes::street($section->string('mode') ?? ('transfer' === $type || 'crow_fly' === $type ? 'walking' : null));
        $realtime = 'realtime' === $section->string('data_freshness');
        $scheduledDeparture = $this->date($section->string('base_departure_date_time'), $zone);
        $scheduledArrival = $this->date($section->string('base_arrival_date_time'), $zone);
        $times = $section->list('stop_date_times');
        $points = [];
        $coordinates = $section->get('geojson.coordinates');
        if (\is_array($coordinates)) {
            foreach ($coordinates as $pair) {
                if (\is_array($pair) && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null)) {
                    $points[] = new Coordinates((float) $pair[1], (float) $pair[0]);
                }
            }
        }
        $length = $section->float('geojson.properties.0.length');

        return new Leg(
            $mode,
            $this->place($section->data('from')),
            $this->place($section->data('to')),
            $departure,
            $arrival,
            $line,
            $transit ? $section->string('display_informations.direction') : null,
            $transit ? self::linked($section, 'vehicle_journey') : null,
            array_map(fn (Data $time) => $this->stopTime($time, $zone), \array_slice($times, 1, max(0, \count($times) - 2))),
            $realtime && null !== $scheduledDeparture && $scheduledDeparture != $departure ? $scheduledDeparture : null,
            $realtime && null !== $scheduledArrival && $scheduledArrival != $arrival ? $scheduledArrival : null,
            $realtime,
            false,
            $transit ? null : $length,
            [] === $points ? null : Polyline::encode($points, 6),
            6,
            $transit ? $section->string('display_informations.trip_short_name') : null,
        );
    }

    private function stopTime(Data $time, \DateTimeZone $zone): Place
    {
        $stop = $this->stopPoint($time->data('stop_point'));

        return new Place($stop->name, $stop->coordinates, $stop, $stop->platform, $this->date($time->string('arrival_date_time'), $zone), $this->date($time->string('departure_date_time'), $zone));
    }

    /** A section's end: a stop point, a stop area, an address, a point of interest, a bare coordinate. */
    private function place(Data $place): Place
    {
        $type = $place->string('embedded_type');
        $name = $place->string('name') ?? '';
        if ('stop_point' === $type) {
            $stop = $this->stopPoint($place->data('stop_point'));

            return new Place($name ?: $stop->name, $stop->coordinates, $stop, $stop->platform);
        }
        if ('stop_area' === $type) {
            $stop = $this->stopArea($place->data('stop_area'));

            return new Place($name ?: $stop->name, $stop->coordinates, $stop);
        }
        $point = null !== $type ? self::coord($place->data($type)) : null;

        return new Place($name, $point ?? self::coordFromId($place->string('id')) ?? new Coordinates(0, 0));
    }

    /** Navitia names a bare point by "lon;lat". */
    private static function coordFromId(?string $id): ?Coordinates
    {
        if (null === $id || !preg_match('~^(-?\d+(?:\.\d+)?);(-?\d+(?:\.\d+)?)$~', $id, $m)) {
            return null;
        }

        return Coordinates::parse($m[2].','.$m[1]);
    }

    /** "lon;lat", or the stop's id when the place is one of Navitia's. */
    private function placeParameter(Place $place): string
    {
        if (null !== $place->stop && $this->getName() === $place->stop->source) {
            return $place->stop->id;
        }
        $point = $place->coordinates() ?? throw NotSupportedException::call($this->getTitle(), \sprintf('plan from a stop of another provider without its coordinates ("%s")', $place->name));

        return self::number($point->longitude).';'.self::number($point->latitude);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.6F', $value), '0'), '.');
    }
}
