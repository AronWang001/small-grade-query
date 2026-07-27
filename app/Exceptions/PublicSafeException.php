<?php

declare(strict_types=1);

/**
 * An exception whose message has been deliberately written for end users.
 *
 * Database, filesystem, encryption, mail and other infrastructure exceptions
 * must never be wrapped in this type with their original message.
 */
class PublicSafeException extends RuntimeException
{
}
