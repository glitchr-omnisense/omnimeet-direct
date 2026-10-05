<?php

namespace Omnimeet\Direct\Action;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Model\Status;
use Omnimeet\Request\Open;
use Omnimeet\Request\Request;

/**
 * Nothing to open anywhere: the room is the meeting's own key, under which
 * its handshake will be kept. The same key, the same room.
 */
final class OpenAction implements ActionInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof Open;
    }

    /** @param Open $request */
    public function execute(Request $request): void
    {
        $request->setResult($request->meeting->with($request->meeting->key, Status::OPEN));
    }
}
