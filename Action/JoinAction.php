<?php

namespace Omnimeet\Direct\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Direct\Api;
use Omnimeet\Model\Access;
use Omnimeet\Request\Join;
use Omnimeet\Request\Request;

/**
 * The engine and what it starts with: the participant's side of the
 * handshake (the host offers, the guest answers) and the ICE servers - the
 * relay with credentials made for this participant, valid for the ttl.
 * Where the handshake is posted and read is the application's: its own
 * addresses, which it adds when it renders the page.
 */
final class JoinAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Join;
    }

    /** @param Join $request */
    public function execute(Request $request): void
    {
        $turn = $this->api->turn;
        $request->setResult(Access::engine(Api::ENGINE, Api::script(), [
            'room' => $request->meeting->reference(),
            'participant' => $request->participant->id,
            'role' => $request->participant->role->value,
            'iceServers' => $turn->iceServers($request->participant->id),
        ], $turn->isConfigured() ? new \DateTimeImmutable('+'.$turn->ttl().' seconds') : null));
    }
}
