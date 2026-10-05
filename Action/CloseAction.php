<?php

namespace Omnimeet\Direct\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Action\ApiAwareInterface;
use Omnimeet\Action\ApiAwareTrait;
use Omnimeet\Direct\Api;
use Omnimeet\Model\Status;
use Omnimeet\Request\Close;
use Omnimeet\Request\Request;

/** The room's handshake goes: nothing of the call is kept. */
final class CloseAction implements ActionInterface, ApiAwareInterface
{
    /** @use ApiAwareTrait<Api> */
    use ApiAwareTrait;

    public function __construct()
    {
        $this->apiClass = Api::class;
    }

    public function supports(Request $request): bool
    {
        return $request instanceof Close;
    }

    /** @param Close $request */
    public function execute(Request $request): void
    {
        $this->api->signaling->purge($request->meeting->reference());
        $request->setResult($request->meeting->with(status: Status::ENDED, present: []));
    }
}
