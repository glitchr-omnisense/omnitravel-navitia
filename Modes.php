<?php

namespace Omnitravel\Navitia;

use Omnitravel\Model\Mode;

/**
 * Navitia's physical modes (NTFS's list: physical_mode:Bus, :Tramway,
 * :Metro, :RapidTransit...), its street modes (walking, bike, bss, car,
 * ridesharing, taxi) and omnitravel's, both ways.
 */
final class Modes
{
    private const PHYSICAL = [
        'Air' => Mode::Air, 'Boat' => Mode::Ferry, 'Ferry' => Mode::Ferry, 'Bus' => Mode::Bus, 'BusRapidTransit' => Mode::Bus,
        'Coach' => Mode::Coach, 'Funicular' => Mode::Funicular, 'LocalTrain' => Mode::RegionalRail,
        'LongDistanceTrain' => Mode::LongDistanceRail, 'Metro' => Mode::Metro, 'RailShuttle' => Mode::Rail,
        'RapidTransit' => Mode::Suburban, 'Shuttle' => Mode::Bus, 'SuspendedCableCar' => Mode::AerialLift,
        'Taxi' => Mode::OnDemand, 'Train' => Mode::Rail, 'Tramway' => Mode::Tram, 'Trolleybus' => Mode::Trolleybus,
        'Bike' => Mode::Bike, 'BikeSharingService' => Mode::Bike, 'Car' => Mode::Car,
    ];

    private const STREET = ['walking' => Mode::Walk, 'bike' => Mode::Bike, 'bss' => Mode::Bike, 'car' => Mode::Car, 'car_no_park' => Mode::Car, 'ridesharing' => Mode::OnDemand, 'taxi' => Mode::OnDemand];

    /** @var array<string, list<string>> */
    private const TO = [
        'tram' => ['Tramway'], 'metro' => ['Metro'], 'rail' => ['Train', 'RailShuttle'], 'suburban' => ['RapidTransit'],
        'regional-rail' => ['LocalTrain'], 'high-speed-rail' => ['LongDistanceTrain'], 'long-distance-rail' => ['LongDistanceTrain'],
        'night-rail' => ['LongDistanceTrain'], 'bus' => ['Bus', 'BusRapidTransit', 'Shuttle'], 'trolleybus' => ['Trolleybus'],
        'coach' => ['Coach'], 'ferry' => ['Ferry', 'Boat'], 'aerial-lift' => ['SuspendedCableCar'], 'cable-car' => ['SuspendedCableCar'],
        'funicular' => ['Funicular'], 'air' => ['Air'], 'on-demand' => ['Taxi'],
    ];

    /** From "physical_mode:Tramway" or "Tramway"; null when Navitia's mode is not one of NTFS's. */
    public static function physical(?string $id): ?Mode
    {
        $name = str_starts_with((string) $id, 'physical_mode:') ? substr((string) $id, 14) : (string) $id;

        return self::PHYSICAL[$name] ?? null;
    }

    /** A street section's mode ("walking", "bike"...); walking when Navitia names another. */
    public static function street(?string $mode): Mode
    {
        return self::STREET[strtolower((string) $mode)] ?? Mode::Walk;
    }

    /**
     * Navitia's physical modes for these, as allowed_id[] takes them.
     *
     * @param list<Mode> $modes
     *
     * @return list<string>
     */
    public static function toIds(array $modes): array
    {
        $ids = [];
        foreach ($modes as $mode) {
            foreach (self::TO[$mode->value] ?? [] as $one) {
                $ids['physical_mode:'.$one] = true;
            }
        }

        return array_keys($ids);
    }
}
