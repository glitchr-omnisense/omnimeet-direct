<?php

namespace Omnimeet\Direct\Tests;

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Testing\BareTestCase;

/** Every class of omnimeet/direct loads with the core and omnisense/contracts alone. */
final class LoadAllTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return DirectGatewayFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container'];
    }
}
