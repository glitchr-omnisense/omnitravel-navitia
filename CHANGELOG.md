# Changelog

Every version of omnitravel/navitia, newest first ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
[Semantic Versioning](https://semver.org/)). A release's notes are its section here: the release
workflow refuses a tag without one.

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable version. It requires `omnisense/contracts` `^1.0`, `omnisense/omnitravel` `^1.0` with no stability flag, so `composer require omnitravel/navitia` installs it in a project with Composer's default minimum-stability; the 1.x series follows semantic versioning. A tag `vX.Y.Z` is released by `.github/workflows/release.yml` once the package's CI passes on it.

Omnitravel's Navitia, under any PHP host: a stop area's or a stop point's next departures and arrivals in real time, journeys from A to B, the stops near a point or by name, the lines of a coverage and their stops, its networks - from navitia.io or SNCF's api.sncf.com (the same API), with the key given by reference.
