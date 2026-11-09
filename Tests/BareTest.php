<?php

namespace Omnitravel\Navitia\Tests;

use Omnitravel\Testing\BareTestCase;
use Omnitravel\Navitia\NavitiaProvider;

/** omnitravel/navitia' classes load with omnisense/omnitravel and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return NavitiaProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
