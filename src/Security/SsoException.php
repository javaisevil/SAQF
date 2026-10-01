<?php
declare(strict_types=1);

namespace Saqf\Security;

/** A university sign-in that cannot complete; the message is safe to show to the person signing in. */
final class SsoException extends \RuntimeException
{
}
