<?php

namespace Omnimeet\Direct;

use Omnimeet\Direct\Exception\InvalidSignalException;
use Omnimeet\Model\Status;

/**
 * The WebRTC handshake relayed over plain HTTP: one side posts its hello,
 * its offer, its answer, its ICE candidates; the other asks, every second
 * while the call is being set up, for what came after the last message it
 * read. No WebSocket server to run; the media themselves never come here.
 *
 * The rules:
 *   - five messages, and no other: hello, offer, answer, candidate, bye;
 *   - a message carries one JSON document of at most MAX_PAYLOAD bytes;
 *   - a participant reads what the other sent, never their own, in the
 *     order it was sent, from the number they give (PAGE at most at once);
 *   - an answer means the two are connected; a hello or a bye, that the
 *     handshake starts again or stopped;
 *   - closing a room purges it: nothing of a call outlives it.
 *
 * Who may post in a room - its two participants, while it is open - is the
 * application's to check before it calls send().
 */
final class Signaling
{
    public const HELLO = 'hello';
    public const OFFER = 'offer';
    public const ANSWER = 'answer';
    public const CANDIDATE = 'candidate';
    public const BYE = 'bye';

    public const TYPES = [self::OFFER, self::ANSWER, self::CANDIDATE, self::BYE, self::HELLO];
    public const MAX_PAYLOAD = 65536;
    public const PAGE = 200;

    public function __construct(private readonly SignalStoreInterface $store)
    {
    }

    /**
     * @param string $payload a JSON document ("null" for a hello or a bye)
     *
     * @throws InvalidSignalException
     */
    public function send(string $room, string $sender, string $type, string $payload = 'null'): Signal
    {
        if ('' === $room || '' === $sender) {
            throw new InvalidSignalException('A signal goes from a participant, in a room.');
        }
        if (!\in_array($type, self::TYPES, true)) {
            throw new InvalidSignalException(\sprintf('Unknown signal "%s".', $type));
        }
        if (\strlen($payload) > self::MAX_PAYLOAD || (null === json_decode($payload) && 'null' !== $payload)) {
            throw new InvalidSignalException('The signal is not a JSON document of reasonable size.');
        }

        return new Signal($this->store->append($room, $sender, $type, $payload), $room, $sender, $type, $payload);
    }

    /**
     * What the other side sent after $after.
     *
     * @return list<array{id: int, type: string, payload: mixed}>
     */
    public function receive(string $room, string $recipient, int $after = 0): array
    {
        return array_map(static fn (Signal $signal) => $signal->toArray(), $this->store->read($room, max(0, $after), $recipient, self::PAGE));
    }

    /** The room closes: its handshake goes. */
    public function purge(string $room): int
    {
        return $this->store->purge($room);
    }

    /**
     * Where the room stands, read from its handshake alone: who said
     * something and has not said goodbye since, and whether an answer was
     * given since the handshake last started.
     *
     * @return array{status: Status, present: list<string>}
     */
    public function state(string $room): array
    {
        $present = [];
        $live = false;
        $after = 0;
        do {
            $page = $this->store->read($room, $after, null, self::PAGE);
            foreach ($page as $signal) {
                $after = max($after, $signal->id);
                if (self::BYE === $signal->type) {
                    unset($present[$signal->sender]);
                    $live = false;
                    continue;
                }
                $present[$signal->sender] = true;
                $live = match ($signal->type) {
                    self::ANSWER => true,
                    self::HELLO, self::OFFER => false,
                    default => $live,
                };
            }
        } while (self::PAGE === \count($page));

        return ['status' => $live && \count($present) > 1 ? Status::LIVE : Status::OPEN, 'present' => array_map('strval', array_keys($present))];
    }
}
