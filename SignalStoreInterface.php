<?php

namespace Omnimeet\Direct;

/**
 * Where the handshake waits between the request that posts a message and
 * the request that reads it: a table, a cache, a file - the application's
 * choice, and the only thing this package needs from it. A room is named
 * by the meeting's reference, a participant by their opaque identifier.
 *
 * Nothing of the call itself ever comes here: a few kilobytes per call,
 * gone when the meeting closes.
 */
interface SignalStoreInterface
{
    /** @return int the number given to the message: greater than every one before it in the room */
    public function append(string $room, string $sender, string $type, string $payload): int;

    /**
     * The room's messages numbered above $after, oldest first.
     *
     * @param string|null $except leave out what this participant sent: nobody hears themselves
     *
     * @return list<Signal>
     */
    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array;

    /** @return int how many messages went */
    public function purge(string $room): int;
}
