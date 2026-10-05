<?php

namespace Omnimeet\Direct\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Direct\Api;
use Omnimeet\Model\Participant;
use Omnimeet\Model\Status;
use Omnimeet\Request\Fetch;
use Omnimeet\Request\Request;

/**
 * The room read from its handshake, the only trace there is: who said
 * something and has not left since, and whether the two are connected
 * (LIVE). A closed room has no trace: a meeting given as ENDED, or whose
 * end is past, stays ENDED. Who each participant is - their role, their
 * name - the application knows; here they are identifiers.
 */
final class FetchAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Fetch;
    }

    /** @param Fetch $request */
    public function execute(Request $request): void
    {
        $meeting = $request->meeting;
        if (Status::ENDED === $meeting->status || (null !== $meeting->endsAt && $meeting->endsAt < new \DateTimeImmutable())) {
            $request->setResult($meeting->with(status: Status::ENDED, present: []));

            return;
        }
        $state = $this->api->signaling->state($meeting->reference());
        $request->setResult($meeting->with(status: $state['status'], present: array_map(static fn (string $id) => new Participant($id), $state['present'])));
    }
}
