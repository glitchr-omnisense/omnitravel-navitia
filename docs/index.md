---
title: Navitia
order: 40
---

# omnitravel/navitia

[Navitia](https://navitia.io) is an open-source journey planner (Hove) with a public API, v1, at
`https://api.navitia.io/v1`; SNCF serves the same API for its trains at `https://api.sncf.com/v1`
(coverage `sncf`). Both need a key. The calls and fields below are read from Navitia's
documentation (doc.navitia.io) and its Swagger schema (`https://api.navitia.io/v1/schema`), both read
on 2026-11-09.

```sh
composer require omnisense/omnitravel omnitravel/navitia
```

## Options

| Option | Default | |
|---|---|---|
| `token` | (required) | the key, sent as the `Authorization` header of every request. Give it by reference - an environment variable, a vault (`%env(NAVITIA_TOKEN)%` in Symfony) - never written in a committed file |
| `coverage` | (required) | the region: `fr-idf`, `fr-ne`, `sncf`... (letters, digits, `.`, `_`, `-`) |
| `base_uri` | `https://api.navitia.io/v1` | `https://api.sncf.com/v1` for SNCF's, a Navitia server of one's own |
| `timezone` | `Europe/Paris` | the coverage's: Navitia writes and reads times in it, without an offset (`20261109T143000`); an answer's `context.timezone` wins when it reads one |
| `attribution` | `Navitia (https://navitia.io)` | what the coverage's data licence asks to be shown (`Capabilities::$attribution`): SNCF's open data, a network's ODbL... each coverage says its feed publishers |
| `timeout` | the client's | seconds; the factory's own client waits 20 |

## Capabilities

`timetables`, `arrivals`, `realtime`, `itineraries`, `network` with `nearby`, `stopSearch` and
`lines`, `platforms`; not `keyless`; no `countries` (a coverage's); `terms` Navitia's documentation.

## The calls

All under `{base_uri}/coverage/{coverage}/`, with `Authorization: {token}`.

| Method | Call | Parameters |
|---|---|---|
| `departures()` | `stop_areas/{id}/departures`, `stop_points/{id}/departures` (by the id's prefix), `.../arrivals` for arrivals | `from_datetime` (the coverage's local time), `count`, `duration` (the window), `data_freshness=realtime`, `depth=2` (the route's line) |
| `journeys()` | `journeys`, `allowed_id[]=physical_mode:...` for the modes asked | `from`, `to` (a Navitia id, otherwise `lon;lat`), `datetime`, `datetime_represents=arrival`, `max_nb_transfers`, `count`, `wheelchair=true`, `data_freshness=realtime` |
| `stop()` | `stop_areas/{id}` or `stop_points/{id}` | `depth=1` |
| `nearby()` | `coords/{lon};{lat}/places_nearby` | `type[]=stop_area`, `distance` (the radius), `count`, `depth=1` |
| `searchStops()` | `places` | `q`, `type[]=stop_area`, `count` (1-100), `from` (`lon;lat`, to favour), `depth=1` |
| `lines()` | `lines` | `count=200`, `start_page` from the second page, at most 20 pages |
| `line()` | `lines/{id}` | `depth=1` |
| `lineStops()` | `lines/{id}/stop_areas`, or `routes/{direction}/stop_areas` when a direction (a route id) is given | `count=200`, `depth=1` |
| `stopLines()` | `stop_areas/{id}/lines` | `count=200`, `depth=1` |
| `operators()` | `networks` | `count=200` |

An identifier keeps its colons in the path (`stop_areas/stop_area:SNCF:87391003`); anything else
in it is URL-encoded. A 404 (`unknown_object`) is "not known": null or an empty list. Any other
error status is a `ProviderException` naming the path only (`Navitia answered 401 for GET
/v1/coverage/sncf/...`), a network failure an `UnreachableException`; the token, sent in a header,
is masked in the copies of the client's exceptions and out of every trace's arguments (the core's
`JsonClient` and `Redaction`).

## How fields are read

- **Times**: `Ymd\THis` in the answer's `context.timezone` (else the option), given back in UTC.
- **Departure**: `stop_date_time.base_departure_date_time` (`base_arrival_date_time` for arrivals)
  is the scheduled time, `departure_date_time` the expected one only when `data_freshness` is
  `realtime`. The headsign is `display_informations.direction`; Navitia's `headsign` and
  `trip_short_name` are the train's number (`number`). The platform is the stop point's
  `platform_code`; the trip is the `vehicle_journey` link. Cancellations are not read (Navitia
  leaves a deleted trip out of a realtime answer).
- **Line**: the route's line (`depth=2`): `id`, `code` (else `name`) as the name, `name` as the long
  name, `color` and `text_color`, its network as the operator, its first physical mode; without it,
  `display_informations` (`code`, `label`, `name`, `color`, `network`) and the `physical_mode` link.
- **Modes**: NTFS's physical modes - `Tramway`, `Metro`, `RapidTransit` (RER, Transilien:
  suburban), `LocalTrain` (TER: regional), `LongDistanceTrain`, `Train` and `RailShuttle`, `Bus`,
  `BusRapidTransit` and `Shuttle` (bus), `Trolleybus`, `Coach`, `Ferry` and `Boat`, `Funicular`,
  `SuspendedCableCar` (aerial lift), `Air`, `Taxi` (on demand); street sections' `walking`, `bike`
  and `bss`, `car`, `ridesharing` and `taxi`. Asking for high-speed rail allows `LongDistanceTrain`,
  NTFS having no mode of its own for it.
- **Journey**: its sections in order, but `waiting`, `boarding`, `alighting`, `stay_in`, `park` and
  the bike-share ones; `public_transport` and `on_demand_transport` are rides (their line, direction,
  vehicle journey, number, intermediate `stop_date_times`), the others walks or street rides, a
  `transfer` and a `crow_fly` on foot. `base_*` times are kept when real time moved them. The
  `geojson` line is encoded as a polyline (precision 6); a street section's distance is its
  `geojson.properties[0].length`. `nb_transfers` is the count of changes. A section's end is its stop
  point, its stop area (a station), or a point (an address, a point of interest, `lon;lat`).
- **Stop areas** are stations (`StopKind::Station`), **stop points** stops whose parent is their
  stop area, with their `platform_code`. Coordinates are Navitia's strings, read as numbers.
- **Near a point**: Navitia's stop areas within the distance; the core's `Stop::nearest()` sorts
  them by the distance to their own coordinates (Navitia's `distance` may be measured to an
  entrance).
- **A line's stops**: as Navitia lists them, which is not guaranteed to be the order vehicles call at
  them; a route (a direction) narrows them.

## Verified, and not

- Verified: the requests (paths, query, the Authorization header) and the reading of answers
  written from the Swagger schema's shapes (`Passage`, `StopDateTime`, `Journey`, `Section`,
  `PlaceNearby`, `Place`, `Line`, `StopArea`, `Network`), on the SNCF coverage; that the token is
  in no exception's message, copy or trace.
- Not verified: any live answer - this package was written without a key, and no call was made.
  The first run against a real coverage should be compared with these fixtures (the names of the
  physical modes, `platform_code`, how a cancelled train shows in `realtime`).
- Not used: disruptions, route schedules, stop schedules, isochrones, vehicle positions
  (`vehicle_positions`, beta), fares, `language`.
