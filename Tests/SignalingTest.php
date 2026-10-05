<?php

namespace Omnimeet\Direct\Tests;

use Omnimeet\Direct\Exception\InvalidSignalException;
use Omnimeet\Direct\InMemorySignalStore;
use Omnimeet\Direct\Signaling;
use Omnimeet\Exception\OmnimeetException;
use Omnimeet\Model\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The handshake's rules, on the store kept in memory. */
final class SignalingTest extends TestCase
{
    public function testTheHandshakeGoesFromOneToTheOtherInOrder(): void
    {
        $signaling = new Signaling(new InMemorySignalStore());

        $hello = $signaling->send('room', 'guest', 'hello');
        self::assertFalse($hello->connects());
        self::assertSame([], $signaling->receive('room', 'guest'), 'one does not hear oneself');
        self::assertSame([['id' => $hello->id, 'type' => 'hello', 'payload' => null]], $signaling->receive('room', 'host'));

        $offer = $signaling->send('room', 'host', 'offer', '{"type":"offer","sdp":"v=0"}');
        $candidate = $signaling->send('room', 'host', 'candidate', '{"candidate":"candidate:1"}');
        self::assertGreaterThan($offer->id, $candidate->id, 'numbered as they come');
        self::assertSame(
            [['id' => $offer->id, 'type' => 'offer', 'payload' => ['type' => 'offer', 'sdp' => 'v=0']], ['id' => $candidate->id, 'type' => 'candidate', 'payload' => ['candidate' => 'candidate:1']]],
            $signaling->receive('room', 'guest'),
        );
        self::assertSame([['id' => $candidate->id, 'type' => 'candidate', 'payload' => ['candidate' => 'candidate:1']]], $signaling->receive('room', 'guest', $offer->id), 'only what came after the last one read');
        self::assertSame([], $signaling->receive('room', 'guest', $candidate->id));
        self::assertSame([], $signaling->receive('another-room', 'guest'), 'a room hears only itself');

        self::assertTrue($signaling->send('room', 'guest', 'answer', '{"type":"answer","sdp":"v=0"}')->connects(), 'an answer: the two are connected');
    }

    public function testAPageIsReadAtMostAtOnce(): void
    {
        $signaling = new Signaling(new InMemorySignalStore());
        for ($i = 0; $i < Signaling::PAGE + 5; ++$i) {
            $signaling->send('room', 'host', 'candidate', '{"n":'.$i.'}');
        }

        $page = $signaling->receive('room', 'guest');
        self::assertCount(Signaling::PAGE, $page);
        self::assertCount(5, $signaling->receive('room', 'guest', $page[Signaling::PAGE - 1]['id']), 'the rest from the last one read');
    }

    #[DataProvider('refused')]
    public function testOnlyTheHandshakesOwnMessages(string $room, string $sender, string $type, string $payload, string $message): void
    {
        $store = new InMemorySignalStore();
        try {
            (new Signaling($store))->send($room, $sender, $type, $payload);
            self::fail('Refused.');
        } catch (InvalidSignalException $e) {
            self::assertSame($message, $e->getMessage());
            self::assertInstanceOf(OmnimeetException::class, $e);
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }
        self::assertSame([], $store->read('room'), 'and nothing is kept');
    }

    /** @return iterable<string, array{string, string, string, string, string}> */
    public static function refused(): iterable
    {
        yield 'an unknown message' => ['room', 'host', 'shout', 'null', 'Unknown signal "shout".'];
        yield 'not JSON' => ['room', 'host', 'offer', '{type: offer', 'The signal is not a JSON document of reasonable size.'];
        yield 'too large' => ['room', 'host', 'offer', '"'.str_repeat('a', Signaling::MAX_PAYLOAD).'"', 'The signal is not a JSON document of reasonable size.'];
        yield 'from nobody' => ['room', '', 'hello', 'null', 'A signal goes from a participant, in a room.'];
        yield 'nowhere' => ['', 'host', 'hello', 'null', 'A signal goes from a participant, in a room.'];
    }

    public function testTheStateIsReadFromTheHandshake(): void
    {
        $signaling = new Signaling(new InMemorySignalStore());
        self::assertSame(['status' => Status::OPEN, 'present' => []], $signaling->state('room'), 'nobody yet');

        $signaling->send('room', 'guest', 'hello');
        self::assertSame(['status' => Status::OPEN, 'present' => ['guest']], $signaling->state('room'), 'the guest waits');

        $signaling->send('room', 'host', 'offer', '{}');
        self::assertSame(Status::OPEN, $signaling->state('room')['status'], 'offered, not answered');
        $signaling->send('room', 'guest', 'answer', '{}');
        $signaling->send('room', 'host', 'candidate', '{}');
        self::assertSame(['status' => Status::LIVE, 'present' => ['guest', 'host']], $signaling->state('room'));

        // The guest's page is reloaded: the handshake starts again.
        $signaling->send('room', 'guest', 'hello');
        self::assertSame(Status::OPEN, $signaling->state('room')['status']);
        $signaling->send('room', 'host', 'offer', '{}');
        $signaling->send('room', 'guest', 'answer', '{}');
        self::assertSame(Status::LIVE, $signaling->state('room')['status']);

        $signaling->send('room', 'guest', 'bye');
        self::assertSame(['status' => Status::OPEN, 'present' => ['host']], $signaling->state('room'), 'the guest left');
    }

    public function testClosingARoomPurgesIt(): void
    {
        $store = new InMemorySignalStore();
        $signaling = new Signaling($store);
        $signaling->send('room', 'guest', 'hello');
        $signaling->send('room', 'host', 'offer', '{}');
        $kept = $signaling->send('other', 'host', 'hello');

        self::assertSame(2, $signaling->purge('room'));
        self::assertSame([], $store->read('room'));
        self::assertSame(0, $signaling->purge('room'));
        self::assertCount(1, $store->read('other'), 'the other rooms keep theirs');
        self::assertGreaterThan($kept->id, $signaling->send('room', 'guest', 'hello')->id, 'numbers never come back');
    }
}
