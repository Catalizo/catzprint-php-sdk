<?php

declare(strict_types=1);

namespace CatzPrint\Exception;

/** 403: the key lacks the scope or role for this operation (or its IP isn't allowed). */
class PermissionException extends CatzPrintException
{
}
