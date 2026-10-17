<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** 401: the API key is missing, invalid, revoked or expired. */
class AuthenticationException extends CatzPrintException
{
}
