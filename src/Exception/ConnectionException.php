<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** The request never got an HTTP answer (DNS, TLS, refused, timed out). */
class ConnectionException extends CatzPrintException
{
}
