<?php

declare(strict_types=1);

namespace App\Service\Tool;

/**
 * A tool call failed in a way the caller (often an AI) can correct.
 */
final class ToolException extends \RuntimeException
{
}
