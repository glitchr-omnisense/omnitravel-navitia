<?php

namespace Omnitravel\Navitia;

use Omnitravel\Config;
use Omnitravel\Exception\InvalidConfigException;
use Omnitravel\Http\JsonClient;
use Omnitravel\ProviderFactory;
use Omnitravel\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "navitia": options token (required: the key navitia.io or api.sncf.com
 * gives, read from the environment or a vault by the application - never
 * written in a configuration file), coverage (required: "fr-idf", "fr-ne",
 * "sncf"...), base_uri (https://api.navitia.io/v1; https://api.sncf.com/v1
 * for SNCF's), timezone (the coverage's, in which Navitia writes and reads
 * times: Europe/Paris; each answer's context.timezone wins), attribution
 * (what the coverage's data licence asks to show), timeout (seconds).
 */
final class NavitiaFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnitravel.factory_name' => 'navitia',
            'omnitravel.factory_title' => 'Navitia',
            'omnitravel.required_options' => ['token', 'coverage'],
            'base_uri' => 'https://api.navitia.io/v1',
            'timezone' => 'Europe/Paris',
            'attribution' => null,
            'timeout' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $base = rtrim((string) $c->string('base_uri'), '/');
        if (!preg_match('~^https?://[^/\s]+~', $base)) {
            throw new InvalidConfigException(\sprintf('The "navitia" provider needs a base_uri such as https://api.navitia.io/v1, not "%s".', $base));
        }
        $coverage = (string) $c->string('coverage');
        if (!preg_match('~^[A-Za-z0-9_.\-]+$~', $coverage)) {
            throw new InvalidConfigException(\sprintf('The "navitia" provider needs a coverage such as "fr-idf" or "sncf", not "%s".', $coverage));
        }
        try {
            $timezone = new \DateTimeZone((string) $c->string('timezone'));
        } catch (\Exception) {
            throw new InvalidConfigException(\sprintf('The "navitia" provider needs an IANA time zone, not "%s".', (string) $c->string('timezone')));
        }
        $http = $this->http ?? HttpClient::create(['timeout' => 20]);
        if (null !== $timeout = $c->string('timeout')) {
            $http = $http->withOptions(['timeout' => (float) $timeout]);
        }

        return new NavitiaProvider(
            new JsonClient($http, 'Navitia', ['Authorization' => (string) $c->string('token'), 'Accept' => 'application/json']),
            $base,
            $coverage,
            $timezone,
            $c->string('attribution'),
        );
    }
}
