<?php

namespace Omnimeet\Direct\Tests;

use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Exception\UsageLimitReachedException;
use Omnimeet\Model\Meeting;
use Omnimeet\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\PolicyExport;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;

/** The direct gateway's policy: no third party, so no limit and every right - the site's own to tighten. */
final class PolicyTest extends TestCase
{
    public function testThePackageDeclaresItsPolicy(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        self::assertSame('direct', $policy->provider);
        self::assertSame([], $policy->rateLimits, 'no third party: no limit');
        self::assertSame(Permission::Allowed, $policy->rights->commercial);
        self::assertFalse($policy->requirements->key);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);

        $export = (new PolicyExport())->folders(__DIR__.'/..')->entries();
        self::assertSame(['omnimeet/direct', 'policy.json'], [$export[0]['package'], $export[0]['file']]);
        self::assertArrayNotHasKey('error', $export[0]);
    }

    public function testTheGatewayGivesItAndASiteTightensIt(): void
    {
        $registry = new Registry([new DirectGatewayFactory()], ['visio' => [
            'factory' => 'direct',
            // The site's relay takes so many rooms a day.
            'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['open']]]],
            'usage' => ['commercial' => true, 'open_source' => false],
        ]]);

        self::assertSame('direct', $registry->get('visio')->capabilities()->policy()->provider);
        self::assertSame(['open', 'join', 'close', 'fetch'], $registry->get('visio')->capabilities()->contracts());
        $gateway = $registry->guarded('visio');
        $gateway->open(new Meeting('k'));
        $this->expectException(UsageLimitReachedException::class);
        $gateway->open(new Meeting('k2'));
    }

    public function testASiteMayOnlyGoStricter(): void
    {
        $registry = new Registry([new DirectGatewayFactory()], ['visio' => ['factory' => 'direct', 'policy' => ['rights' => ['commercial' => 'forbidden']]]]);
        self::assertSame(Permission::Forbidden, $registry->policy('visio')->rights->commercial, 'a site may forbid itself what the gateway allows');
    }
}
