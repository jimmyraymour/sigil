<?php
declare(strict_types=1);

namespace Sigil\Brief;

/**
 * Turns a concept paragraph into the actual image-model prompt.
 *
 * The shape of this prompt was chosen by testing against the free model, not
 * by reasoning about it. Three things mattered, in order:
 *
 *  1. The style tokens must come FIRST. A prompt that opens with a wordy
 *     concept and mentions "flat vector" at the end comes back as a muddy
 *     textured painting — the model weights the opening tokens hardest.
 *  2. The concept must be SHORT. Long descriptive prose pulls the result
 *     toward illustration, so only the first couple of sentences are used.
 *  3. No long list of negatives. A trailing "avoid: no gradients, no
 *     shadows, no 3D…" clause made results worse, not better; these models
 *     have no true negative-prompt channel, so naming a thing can summon it.
 *     Only the few short negatives that tested clean are kept.
 *
 * Keeping this in code rather than in the local LLM's output means every
 * render gets the same discipline no matter how the concept came out.
 */
final class LogoPromptBuilder
{
    /** Style directive per logo style. 'auto' lets the concept decide. */
    private const STYLE_DIRECTIVES = [
        'auto' => 'flat vector logo, minimal geometric emblem',
        'wordmark' => 'flat vector wordmark logo, clean custom lettering, no separate symbol',
        'lettermark' => 'flat vector lettermark logo, one initial built as a geometric mark',
        'monogram' => 'flat vector monogram logo, interlocking initials',
        'abstract' => 'flat vector logo, abstract geometric mark',
        'pictorial' => 'flat vector logo, single simplified pictorial symbol, strong silhouette',
        'combination' => 'flat vector logo, symbol above the name',
        'emblem' => 'flat vector emblem logo, mark enclosed in a badge',
        'mascot' => 'flat vector mascot logo, simple geometric character',
    ];

    /** Tested tokens that reliably produce a clean, flat, centred mark. */
    private const QUALITY = 'thick clean lines, sharp edges, high contrast, centered, simple';

    /**
     * Background is its own token, placed early. Late in the prompt it gets
     * ignored and the model fills the canvas with a dark painterly ground.
     */
    private const BACKGROUND = 'solid white background, plain background';

    /** How much of the concept survives into the image prompt. */
    private const CONCEPT_CHARS = 160;

    public function __construct(private array $styles)
    {
    }

    public function build(string $concept, string $style, ?string $palette, ?string $brandName = null): string
    {
        $parts = [];

        // 1. Style tokens lead.
        $parts[] = self::STYLE_DIRECTIVES[$style] ?? self::STYLE_DIRECTIVES['auto'];

        // 2. Then the (trimmed) concept.
        $parts[] = $this->condense($concept);

        // 3. Colour, then background, then text — this ordering is the one
        //    that tested clean. Moving the background token later loses it.
        $parts[] = ($palette !== null && trim($palette) !== '')
            ? trim($palette) . ' color palette, flat colors'
            : 'two flat colors';

        $parts[] = self::BACKGROUND;

        // Text is the most common failure mode, so it is opt-in: only the
        // styles actually made of letters ask for any.
        $textStyles = ['wordmark', 'lettermark', 'monogram', 'combination', 'emblem'];
        if ($brandName !== null && trim($brandName) !== '' && in_array($style, $textStyles, true)) {
            $parts[] = 'the word "' . trim($brandName) . '" spelled exactly and legibly, no other text';
        } else {
            $parts[] = 'no text, no letters';
        }

        // 4. Quality tail.
        $parts[] = self::QUALITY;

        return implode(', ', array_filter($parts, static fn(string $p): bool => trim($p) !== ''));
    }

    /**
     * Keeps the first whole sentences that fit inside the budget, so the
     * concept reads as a phrase rather than a truncated clause.
     */
    private function condense(string $concept): string
    {
        $concept = trim(preg_replace('/\s+/u', ' ', $concept) ?? '');
        if ($concept === '') {
            return '';
        }
        if (mb_strlen($concept) <= self::CONCEPT_CHARS) {
            return rtrim($concept, '.');
        }

        $out = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $concept) ?: [] as $sentence) {
            $candidate = $out === '' ? $sentence : $out . ' ' . $sentence;
            if (mb_strlen($candidate) > self::CONCEPT_CHARS) {
                break;
            }
            $out = $candidate;
        }
        if ($out === '') {
            $out = mb_substr($concept, 0, self::CONCEPT_CHARS);
            $lastSpace = mb_strrpos($out, ' ');
            if ($lastSpace !== false && $lastSpace > self::CONCEPT_CHARS * 0.6) {
                $out = mb_substr($out, 0, $lastSpace);
            }
        }
        return rtrim(trim($out), '.');
    }

    public function styles(): array
    {
        return $this->styles;
    }
}
