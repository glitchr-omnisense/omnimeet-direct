<?php

namespace Omnimeet\Direct\Exception;

use Omnimeet\Exception\OmnimeetException;

/** What a participant sent is not a message of the handshake: an unknown type, a document that is not JSON or is too large. */
final class InvalidSignalException extends \InvalidArgumentException implements OmnimeetException
{
}
