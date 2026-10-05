<?php

namespace Omnimeet\Direct\Tests;

use Omnimeet\Direct\Api;
use Omnimeet\Direct\DirectGatewayFactory;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Direct\TurnCredentials;
use Omnimeet\Exception\NotSupportedException;
use Omnimeet\Model\AccessKind;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Status;
use Omnimeet\Registry;
use Omnimeet\Request\Close;
use Omnimeet\Request\Fetch;
use Omnimeet\Request\Join;
use Omnimeet\Request\Notify;
use Omnimeet\Request\Open;
use PHPUnit\Framework\TestCase;

/** The gateway from browser to browser: what it says of itself, and a meeting from opening to closing. */
final class DirectGatewayTest extends TestCase
{
    public function testTwoPeopleEndToEndNoThirdPartyNothingRecorded(): void
    {
        $factory = new DirectGatewayFactory();
        $gateway = $factory->create();

        self::assertSame(['direct', 'direct', 'Direct'], [$factory->getName(), $gateway->getName(), $gateway->getTitle()]);
        $capabilities = $gateway->capabilities();
        self::assertSame(2, $capabilities->maxParticipants);
        self::assertFalse($capabilities->thirdParty);
        self::assertFalse($capabilities->recording);
        self::assertTrue($capabilities->endToEnd);
        self::assertSame([AccessKind::ENGINE], $capabilities->access);
        self::assertFalse($capabilities->takes(3), 'it does not become a group call');

        foreach ([Open::class, Join::class, Close::class, Fetch::class] as $request) {
            self::assertTrue($gateway->supports($request));
        }
        self::assertFalse($gateway->supports(Notify::class));
        $this->expectException(NotSupportedException::class);
        $gateway->notify('{}');
    }

    public function testAParticipantIsLetInByTheEngineWithTheirOwnRelayCredentials(): void
    {
        $gateway = (new DirectGatewayFactory())->create([
            'turn_secret' => 'shared-secret',
            'turn_urls' => 'turn:turn.example.org:3478?transport=udp,turn:turn.example.org:3478?transport=tcp',
            'turn_ttl' => 600,
        ]);
        $meeting = $gateway->open(new Meeting('k3y'));
        self::assertSame(['k3y', Status::OPEN], [$meeting->reference, $meeting->status], 'the room is the meeting\'s own key');

        $access = $gateway->join($meeting, Participant::host('u42', 'Dr Tilleul'));
        self::assertSame(AccessKind::ENGINE, $access->kind);
        self::assertSame(Api::ENGINE, $access->engine);
        self::assertFileExists((string) $access->script);
        self::assertStringEndsWith('/public/js/visio.js', (string) $access->script);
        self::assertSame([], $access->origins, 'no third party');
        self::assertSame(['room' => 'k3y', 'participant' => 'u42', 'role' => 'host'], \array_slice($access->options, 0, 3));
        self::assertArrayNotHasKey('name', $access->options, 'a name is of no use between two people who chose to meet');

        $ice = $access->options['iceServers'];
        self::assertCount(1, $ice);
        self::assertSame(['turn:turn.example.org:3478?transport=udp', 'turn:turn.example.org:3478?transport=tcp'], $ice[0]['urls']);
        self::assertStringEndsWith(':u42', $ice[0]['username']);
        self::assertTrue((new TurnCredentials('shared-secret', ['turn:x']))->verify($ice[0]['username'], $ice[0]['credential']), 'credentials coturn will accept');
        self::assertNotNull($access->expiresAt);
        self::assertEqualsWithDelta(time() + 600, $access->expiresAt->getTimestamp(), 5, 'they lapse with the relay\'s credentials');

        self::assertSame('guest', $gateway->join($meeting, Participant::guest('u7'))->options['role']);
        self::assertSame(json_encode($access->options), json_encode(json_decode(json_encode($access->options), true)), 'the options are a JSON document');
    }

    public function testWithoutARelayTheTwoConnectDirectlyOrNotAtAll(): void
    {
        $gateway = (new DirectGatewayFactory())->create(['turn_secret' => '', 'turn_urls' => '']);
        $access = $gateway->join($gateway->open(new Meeting('k')), Participant::guest('u7'));

        self::assertSame([], $access->options['iceServers']);
        self::assertNull($access->expiresAt);
    }

    public function testTheRoomIsReadFromItsHandshakeAndClosingPurgesIt(): void
    {
        $store = new InMemorySignalStore();
        $factory = new DirectGatewayFactory($store);
        $registry = new Registry([$factory], ['direct' => ['factory' => 'direct']]);
        $gateway = $registry->get('direct');
        $signaling = $factory->signaling();
        $meeting = $gateway->open(new Meeting('k3y', new \DateTimeImmutable('-5 minutes'), new \DateTimeImmutable('+25 minutes')));

        self::assertSame(Status::OPEN, $gateway->fetch($meeting)->status);
        $signaling->send('k3y', 'u7', 'hello');
        $waiting = $gateway->fetch($meeting);
        self::assertSame([Status::OPEN, ['u7']], [$waiting->status, array_map(static fn (Participant $p) => $p->id, $waiting->present)]);

        $signaling->send('k3y', 'u42', 'offer', '{"type":"offer"}');
        $signaling->send('k3y', 'u7', 'answer', '{"type":"answer"}');
        self::assertSame(Status::LIVE, $gateway->fetch($meeting)->status);

        $closed = $gateway->close($meeting);
        self::assertSame(Status::ENDED, $closed->status);
        self::assertSame([], $store->read('k3y'), 'nothing of the call is kept');
        self::assertSame(Status::ENDED, $gateway->fetch($closed)->status, 'a closed room has no trace: the meeting says it is over');
        self::assertSame(Status::ENDED, $gateway->fetch($gateway->open(new Meeting('old', new \DateTimeImmutable('-2 hours'), new \DateTimeImmutable('-1 hour'))))->status, 'and so does its end, once past');
    }
}
