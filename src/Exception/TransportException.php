<?php
declare(strict_types=1);

namespace AthMovil\Exception;

/** No reliable response: the remote operation may already have taken effect. */
final class TransportException extends \RuntimeException {}
