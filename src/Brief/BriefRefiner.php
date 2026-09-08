<?php
declare(strict_types=1);

namespace Sigil\Brief;

final class BriefRefiner
{
    public function __construct(private LlmClient $llm, private array $prompts, private int $maxChars = 800)
    {
    }

    /**
     * Turns the two user fields into a single logo concept paragraph.
     *
     * @return array{text: string, model_used: string}
     */
    public function refine(
        string $description,
        ?string $logoDescription,
        string $entityType,
        string $style,
        ?string $paletteHint
    ): array {
        $user = strtr($this->prompts['brief_user'], [
            '{description}' => $description,
            '{logo_description}' => ($logoDescription !== null && trim($logoDescription) !== '')
                ? trim($logoDescription)
                : '(not specified — propose something fitting)',
            '{entity_type}' => $entityType,
            '{style}' => $style === 'auto' ? '(your choice)' : $style,
        ]);

        $result = $this->llm->complete($this->prompts['brief_system'], $user);
        $text = $this->clean($result['text']);
        if ($text === '') {
            throw new BriefRefinementFailed('LLM returned an empty concept');
        }
        return ['text' => $text, 'model_used' => $result['model']];
    }

    /**
     * Asks for N genuinely different alternatives to a base concept, so one
     * run can offer a choice instead of a single take.
     *
     * Returns fewer than $count if the model under-delivers; the caller pads
     * with the base concept rather than failing the whole render.
     *
     * @return array<int, string>
     */
    public function concepts(string $description, string $entityType, string $baseConcept, int $count): array
    {
        $system = strtr($this->prompts['concepts_system'], ['{count}' => (string) $count]);
        $user = strtr($this->prompts['concepts_user'], [
            '{description}' => $description,
            '{entity_type}' => $entityType,
            '{brief}' => $baseConcept,
            '{count}' => (string) $count,
        ]);

        $result = $this->llm->complete($system, $user);
        return $this->splitNumberedList($result['text'], $count);
    }

    /**
     * qwen2.5 often answers in English and then restates the whole thing in
     * Chinese. Anything from the first CJK character onward is dropped; if
     * the reply is CJK from the start there is nothing usable to keep.
     */
    private function stripNonLatin(string $text): string
    {
        if (!preg_match('/[\x{3000}-\x{9FFF}\x{FF00}-\x{FFEF}]/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            return $text;
        }
        $cut = rtrim(mb_strcut($text, 0, $m[0][1]));
        return trim($cut) === '' ? $text : $cut;
    }

    /**
     * Pulls "1. …" lines out of the model's reply. Tolerates bullets, bold
     * markers and stray commentary before or after the list.
     *
     * @return array<int, string>
     */
    private function splitNumberedList(string $text, int $max): array
    {
        $out = [];
        foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^[\s.\-*•>]*(\d{1,2})\s*[.)\]:]\s*(.+)$/u', $line, $m)) {
                continue;
            }
            $concept = $this->clean($m[2]);
            if ($concept !== '') {
                $out[] = $concept;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * Shared post-processing. Beyond stripping fences it removes the
     * "Subject:/Style:/Palette:/Mood:" label blocks that small local models
     * emit no matter how the prompt is worded, and flattens the result to a
     * single paragraph — an image prompt is one string, not a document.
     */
    public function clean(string $text): string
    {
        $text = trim($text);

        if (preg_match('/^```[a-zA-Z]*\s*\n(.*)\n```$/s', $text, $m)) {
            $text = trim($m[1]);
        }

        // Drop a leading "Here is the concept:"-style preamble.
        $text = (string) preg_replace('/^\s*(here(\'s| is)[^:\n]{0,60}:|concept:|logo concept:)\s*/iu', '', $text);

        // Strip section labels, keeping the content that follows them.
        $text = (string) preg_replace(
            '/^\s*\**\s*(subject|style|palette|colou?rs?|mood|composition|concept|description|typography)\s*\**\s*:\s*/imu',
            '',
            $text
        );

        $text = $this->stripNonLatin($text);

        $text = str_replace(['**', '__', '`'], '', $text);
        $text = (string) preg_replace('/^\s*[-*•]\s+/mu', '', $text);

        // One paragraph: newlines become sentence breaks.
        $text = (string) preg_replace('/\s*\R+\s*/u', ' ', $text);
        $text = (string) preg_replace('/\s{2,}/u', ' ', $text);
        $text = trim($text, " \t\n\r\0\x0B\"'");

        if (mb_strlen($text) > $this->maxChars) {
            $cut = mb_substr($text, 0, $this->maxChars);
            $pos = mb_strrpos($cut, '.');
            if ($pos !== false && $pos > $this->maxChars * 0.5) {
                $cut = mb_substr($cut, 0, $pos + 1);
            }
            $text = rtrim($cut);
        }

        return trim($text);
    }
}
