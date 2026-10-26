<?php

declare(strict_types=1);

namespace App\Telegram\Errors;

/** What a Telegram error means for whoever got it. */
enum ErrorKind
{
    /** This peer can't receive: fail this message only. */
    case Peer;

    /** The account must wait (FLOOD_WAIT_N, SLOWMODE_WAIT_N): try the same message later. */
    case Wait;

    /** The account is spam-limited (PEER_FLOOD): stop the account for a long rest. */
    case PeerFlood;

    /** The account is frozen or banned: stop it until someone looks. */
    case Frozen;

    /** The session is dead (revoked, unregistered key): the account must log in again. */
    case DeadSession;

    /** Network or Telegram internal problem: try again later. */
    case Transient;

    /** Login step errors the user can fix by typing again. */
    case CodeInvalid;
    case PasswordInvalid;

    case Unknown;
}
