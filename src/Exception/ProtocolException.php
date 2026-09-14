<?php
declare(strict_types=1);

namespace AthMovil\Exception;

/** Unusable response: do not infer that the remote operation failed. */
final class ProtocolException extends \RuntimeException {}
