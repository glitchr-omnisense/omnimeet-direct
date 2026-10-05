<?php

namespace Omnimeet\Direct;

/**
 * The handshake kept in this PHP process: for tests, a harness, a
 * long-running server. Between two HTTP requests of an ordinary application
 * it forgets everything - that one implements SignalStoreInterface on what
 * it stores with.
 */
final class InMemorySignalStore implements SignalStoreInterface
{
    /** @var array<string, list<Signal>> */
    private array $rooms = [];
    private int $last = 0;

    public function append(string $room, string $sender, string $type, string $payload): int
    {
        $this->rooms[$room][] = new Signal(++$this->last, $room, $sender, $type, $payload);

        return $this->last;
    }

    public function read(string $room, int $after = 0, ?string $except = null, int $limit = 200): array
    {
        $signals = array_filter($this->rooms[$room] ?? [], static fn (Signal $signal) => $signal->id > $after && $signal->sender !== $except);

        return \array_slice(array_values($signals), 0, $limit);
    }

    public function purge(string $room): int
    {
        $count = \count($this->rooms[$room] ?? []);
        unset($this->rooms[$room]);

        return $count;
    }
}
