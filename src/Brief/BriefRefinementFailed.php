<?php
declare(strict_types=1);

namespace Sigil\Brief;

/**
 * Raised when the LLM returns an empty/unusable brief for refinement.
 */
final class BriefRefinementFailed extends \RuntimeException
{
}
