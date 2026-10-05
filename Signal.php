<?php

namespace Omnimeet\Direct;

/**
 * One message of the handshake, from one participant of a room to the
 * other: a hello, an offer, an answer, an ICE candidate, a goodbye.
 */
final readonly class Signal
{
    /**
     * @param int    $id      its number: greater than every one before it in the room
     * @param string $sender  the participant's opaque identifier (Omnimeet\Model\Participant::$id)
     * @param string $payload a JSON document: the session description, the candidate, or null
     */
    public function __construct(
        public int $id,
        public string $room,
        public string $sender,
        public string $type,
        public string $payload = 'null',
    ) {
    }

    /** An answer given: from here on the two browsers talk to each other. */
    public function connects(): bool
    {
        return Signaling::ANSWER === $this->type;
    }

    /** @return array{id: int, type: string, payload: mixed} what the other side's page is given */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'payload' => json_decode($this->payload, true)];
    }
}
