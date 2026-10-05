<?php

namespace Omnimeet\Direct;

/**
 * What the direct gateway's actions work with - there is no provider to
 * call: the handshake's rules on the application's store, the relay's
 * temporary credentials, and the engine's file.
 */
final class Api
{
    public const ENGINE = 'direct';

    public function __construct(
        public readonly Signaling $signaling,
        public readonly TurnCredentials $turn,
    ) {
    }

    /** The JavaScript engine, as the package ships it: the application serves this file. */
    public static function script(): string
    {
        return __DIR__.'/public/js/visio.js';
    }
}
