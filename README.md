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

## Install and a first call

```sh
composer require omnitravel/navitia
```

No account for this first call: Navitia's answer is recorded (`Omnitravel\Testing\RecordedHttpClient`
answers in place of the API), so nothing leaves the process. A real call needs a token: a
navitia.io account, or SNCF's at api.sncf.com for the `sncf` coverage. Give it by reference
(an environment variable, a secrets vault), never in a file.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnitravel\Navitia\NavitiaFactory;
use Omnitravel\Testing\RecordedHttpClient;

// Navitia's answer to a search by name, recorded: no token, nothing sent. HttpClient::create() for a real call.
$http = RecordedHttpClient::routes([
    '~^GET https://api\.sncf\.com/v1/coverage/sncf/places\?q=Angers~' => [200, '{"places": [{"id": "stop_area:SNCF:87484006", "embedded_type": "stop_area", "stop_area": {"id": "stop_area:SNCF:87484006", "name": "Angers Saint-Laud", "coord": {"lon": "-0.556583", "lat": "47.464511"}, "timezone": "Europe/Paris"}}]}'],
]);
$sncf = (new NavitiaFactory($http))->create(['token' => 'recorded', 'coverage' => 'sncf', 'base_uri' => 'https://api.sncf.com/v1']);

foreach ($sncf->searchStops('Angers', 1) as $station) {
    echo $station->name, ' ', $station->id, "\n";   // Angers Saint-Laud stop_area:SNCF:87484006
}
```

With your token, a station's next departures:

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
(`Europe/Paris`), `attribution`, `timeout`; `policy`, `usage` (the family's).

## Usage policy

[policy.json](policy.json) declares what is known: a key, the credit to navitia.io (the
`attribution` option's text when given). Its rate limits and rights are **unknown**: navitia.io's
terms page (https://navitia.io/en/?p=1571) could not be read on 2026-11-10 (HTTP 403) and
api.sncf.com publishes no quota, so nothing is enforced but what a site sets itself - its plan's
quota, as `'policy' => ['rateLimits' => [['max' => 5000, 'window' => 'month']]]`. A 429 and its
`Retry-After` are honoured all the same.

The manual: [docs/index.md](docs/index.md). MIT.
