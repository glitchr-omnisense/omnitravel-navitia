# omnitravel/navitia

[omnisense/omnitravel](https://github.com/glitchr-omnisense/omnitravel)'s Navitia, under any PHP
host: the [Navitia](https://navitia.io) v1 API on one coverage - navitia.io's (`fr-idf`, `fr-ne`...)
or SNCF's (`sncf` at `https://api.sncf.com/v1`, the same API).

- **a stop's departures and arrivals** (`stop_areas/{id}/departures`, `stop_points/{id}/arrivals`):
  the scheduled and the real-time time, the platform, the train's number, the line and its colours;
- **journeys** from A to B (`journeys`): walks, rides with their stops and path, transfers;
- **the network**: a stop area, the stop areas near a point (`coords/{lon};{lat}/places_nearby`)
  and by name (`places`), the coverage's lines page by page, a line's or a route's stop areas, the
  lines of a stop area, the networks as operators.

```php
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Navitia\NavitiaFactory;

$sncf = (new NavitiaFactory($httpClient))->create([
    'token' => getenv('SNCF_API_TOKEN'),          // by reference: never written in a file
    'coverage' => 'sncf',
    'base_uri' => 'https://api.sncf.com/v1',
    'attribution' => 'Données SNCF',
]);
foreach ($sncf->departures(new DepartureQuery('stop_area:SNCF:87391003', limit: 5)) as $departure) {
    echo $departure->number, ' ', $departure->headsign, ' ', $departure->time()->format('H:i'), ' voie ', $departure->platform, "\n";
}
```

Options: `token` and `coverage` (required), `base_uri` (`https://api.navitia.io/v1`), `timezone`
(`Europe/Paris`), `attribution`, `timeout`. The manual: [docs/index.md](docs/index.md). MIT.
