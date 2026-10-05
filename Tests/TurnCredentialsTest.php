<?php

namespace Omnimeet\Direct\Tests;

use Omnimeet\Direct\TurnCredentials;
use Omnimeet\Exception\InvalidConfigException;
use PHPUnit\Framework\TestCase;

/** The temporary TURN credentials (coturn's use-auth-secret). */
final class TurnCredentialsTest extends TestCase
{
    public function testCredentialsAreTheHmacCoturnExpects(): void
    {
        $turn = new TurnCredentials('shared-secret', ['turn:turn.example.org:3478'], [], 600);
        $credentials = $turn->issue('u42', 1_800_000_000);

        self::assertSame('1800000600:u42', $credentials['username'], 'expiry:who');
        self::assertSame(base64_encode(hash_hmac('sha1', '1800000600:u42', 'shared-secret', true)), $credentials['credential']);
        self::assertSame(600, $credentials['ttl']);
        self::assertTrue($turn->verify($credentials['username'], $credentials['credential'], 1_800_000_300));
        self::assertFalse($turn->verify($credentials['username'], $credentials['credential'], 1_800_000_601), 'lapsed');
        self::assertFalse($turn->verify('1900000000:u42', $credentials['credential'], 1_800_000_300), 'a later expiry needs another signature');
        self::assertFalse((new TurnCredentials('another', ['turn:x']))->verify($credentials['username'], $credentials['credential'], 1_800_000_300));
    }

    public function testWhoIsReducedToWhatAUsernameMayCarry(): void
    {
        $turn = new TurnCredentials('s', ['turn:x'], [], 60);

        self::assertSame('1800000060:u42', $turn->issue('u:4 2', 1_800_000_000)['username'], 'no colon of ours in the username: the expiry is what comes before the first');
    }

    public function testIceServersCarryThemAndNoThirdPartyByDefault(): void
    {
        $servers = (new TurnCredentials('s', ['turn:localhost:3478?transport=udp,turn:localhost:3478?transport=tcp']))->iceServers('u1');

        self::assertCount(1, $servers, 'no STUN server of someone else\'s unless configured');
        self::assertSame(['turn:localhost:3478?transport=udp', 'turn:localhost:3478?transport=tcp'], $servers[0]['urls'], 'one environment variable, split');
        self::assertArrayHasKey('credential', $servers[0]);
        self::assertSame([], (new TurnCredentials(null, []))->iceServers('u1'), 'not configured: direct connections only');
        self::assertSame([['urls' => ['stun:stun.example.org:3478']]], (new TurnCredentials(null, [], ['stun:stun.example.org:3478']))->iceServers('u1'), 'a STUN server when one is asked for, without credentials');
    }

    public function testFromAGatewaysOptions(): void
    {
        $turn = TurnCredentials::fromOptions(['turn_secret' => 's', 'turn_urls' => 'turn:a:3478, turn:b:3478', 'turn_ttl' => 120]);

        self::assertTrue($turn->isConfigured());
        self::assertSame(['turn:a:3478', 'turn:b:3478'], $turn->turnUrls());
        self::assertSame(120, $turn->ttl());
        self::assertFalse(TurnCredentials::fromOptions(['turn_secret' => '', 'turn_urls' => ['turn:a']])->isConfigured(), 'a secret left empty is no secret');
        self::assertFalse(TurnCredentials::fromOptions([])->isConfigured());
        self::assertSame(3600, TurnCredentials::fromOptions([])->ttl());
    }

    public function testNothingIsIssuedWithoutARelay(): void
    {
        $this->expectException(InvalidConfigException::class);
        (new TurnCredentials('secret-without-an-address'))->issue('u1');
    }
}
