<?php

namespace Omnitravel\Navitia\Tests;

use Omnisense\Contracts\Policy\Permission;
use Omnitravel\Exception\UsageLimitReachedException;
use Omnitravel\Model\DepartureQuery;
use Omnitravel\Navitia\NavitiaFactory;
use Omnitravel\Registry;
use Omnitravel\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/** Navitia's limits are unknown until its terms are read: a site sets its plan's quota itself. */
final class PolicyTest extends TestCase
{
    public function testWhatIsKnownIsDeclaredTheRestIsUnknown(): void
    {
        $registry = self::registry([]);
        $policy = $registry->policy('sncf');

        self::assertNull($policy->rateLimits);
        self::assertSame(Permission::Unknown, $policy->rights->commercial);
        self::assertTrue($policy->requirements->key);
        self::assertSame('Données SNCF', $policy->rights->attribution->text, 'the coverage\'s credit, by the attribution option');
        self::assertNull($policy->provenance->verifiedAt, 'not read at its source yet');
        self::assertSame('Navitia (https://navitia.io)', self::registry([], null)->policy('sncf')->rights->attribution->text);
    }

    public function testASitesQuotaIsEnforced(): void
    {
        $http = RecordedHttpClient::routes(['~/departures~' => [200, '{"departures": []}']]);
        $registry = self::registry(['policy' => ['rateLimits' => [['max' => 1, 'window' => 'month']]]], 'Données SNCF', $http);

        $registry->timetables('sncf')->departures(new DepartureQuery('stop_area:SNCF:87391003'));
        $this->expectException(UsageLimitReachedException::class);
        $registry->timetables('sncf')->departures(new DepartureQuery('stop_area:SNCF:87391003'));
    }

    /** @param array<string, mixed> $options */
    private static function registry(array $options, ?string $attribution = 'Données SNCF', ?RecordedHttpClient $http = null): Registry
    {
        return new Registry([new NavitiaFactory($http ?? RecordedHttpClient::routes([]))], ['sncf' => ['factory' => 'navitia', 'options' => $options + array_filter([
            'token' => 'not-a-real-token', 'coverage' => 'sncf', 'base_uri' => 'https://api.sncf.com/v1', 'attribution' => $attribution,
        ])]]);
    }
}
