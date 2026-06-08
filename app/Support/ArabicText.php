<?php

namespace App\Support;

/**
 * ArabicText — self-contained Arabic glyph shaper + simplified bidi reordering
 * for PDF engines (dompdf) that have NO native Arabic shaping or bidi support.
 *
 * dompdf renders each Unicode codepoint with its *nominal* glyph in logical
 * (left-to-right) order. For Arabic this produces disconnected, reversed,
 * unreadable text ("ةطخلا" instead of "الخطة"). This class fixes that by:
 *
 *   1. Contextual shaping — replacing each Arabic letter with the correct
 *      isolated / initial / medial / final *presentation form* (U+FE70–FEFF),
 *      which DejaVu Sans (dompdf's bundled font) ships with full coverage of,
 *      so the cursive letters connect.
 *   2. Visual reordering — reversing the whole run so RTL reads correctly when
 *      laid out LTR, while keeping embedded Latin / digit runs (e.g. "Luminal A",
 *      "ER/PR+", "Ki-67", "90%") in their natural left-to-right order, and
 *      mirroring brackets that wrap RTL content.
 *
 * This is a pragmatic subset of UAX#9 — it covers the common medical-report
 * case (Arabic prose with embedded Latin acronyms/numbers) cleanly. The output
 * is presentation-form codepoints already in visual order, so the containing
 * element must NOT rely on the engine re-reordering it (dompdf doesn't).
 */
final class ArabicText
{
    /** letter => [isolated, final, initial, medial] presentation forms */
    private const FORMS = [
        0x0621 => [0xFE80, 0xFE80, 0xFE80, 0xFE80], // hamza
        0x0622 => [0xFE81, 0xFE82, 0xFE81, 0xFE82], // alef madda
        0x0623 => [0xFE83, 0xFE84, 0xFE83, 0xFE84], // alef hamza above
        0x0624 => [0xFE85, 0xFE86, 0xFE85, 0xFE86], // waw hamza
        0x0625 => [0xFE87, 0xFE88, 0xFE87, 0xFE88], // alef hamza below
        0x0626 => [0xFE89, 0xFE8A, 0xFE8B, 0xFE8C], // yeh hamza
        0x0627 => [0xFE8D, 0xFE8E, 0xFE8D, 0xFE8E], // alef
        0x0628 => [0xFE8F, 0xFE90, 0xFE91, 0xFE92], // beh
        0x0629 => [0xFE93, 0xFE94, 0xFE93, 0xFE94], // teh marbuta
        0x062A => [0xFE95, 0xFE96, 0xFE97, 0xFE98], // teh
        0x062B => [0xFE99, 0xFE9A, 0xFE9B, 0xFE9C], // theh
        0x062C => [0xFE9D, 0xFE9E, 0xFE9F, 0xFEA0], // jeem
        0x062D => [0xFEA1, 0xFEA2, 0xFEA3, 0xFEA4], // hah
        0x062E => [0xFEA5, 0xFEA6, 0xFEA7, 0xFEA8], // khah
        0x062F => [0xFEA9, 0xFEAA, 0xFEA9, 0xFEAA], // dal
        0x0630 => [0xFEAB, 0xFEAC, 0xFEAB, 0xFEAC], // thal
        0x0631 => [0xFEAD, 0xFEAE, 0xFEAD, 0xFEAE], // reh
        0x0632 => [0xFEAF, 0xFEB0, 0xFEAF, 0xFEB0], // zain
        0x0633 => [0xFEB1, 0xFEB2, 0xFEB3, 0xFEB4], // seen
        0x0634 => [0xFEB5, 0xFEB6, 0xFEB7, 0xFEB8], // sheen
        0x0635 => [0xFEB9, 0xFEBA, 0xFEBB, 0xFEBC], // sad
        0x0636 => [0xFEBD, 0xFEBE, 0xFEBF, 0xFEC0], // dad
        0x0637 => [0xFEC1, 0xFEC2, 0xFEC3, 0xFEC4], // tah
        0x0638 => [0xFEC5, 0xFEC6, 0xFEC7, 0xFEC8], // zah
        0x0639 => [0xFEC9, 0xFECA, 0xFECB, 0xFECC], // ain
        0x063A => [0xFECD, 0xFECE, 0xFECF, 0xFED0], // ghain
        0x0640 => [0x0640, 0x0640, 0x0640, 0x0640], // tatweel
        0x0641 => [0xFED1, 0xFED2, 0xFED3, 0xFED4], // feh
        0x0642 => [0xFED5, 0xFED6, 0xFED7, 0xFED8], // qaf
        0x0643 => [0xFED9, 0xFEDA, 0xFEDB, 0xFEDC], // kaf
        0x0644 => [0xFEDD, 0xFEDE, 0xFEDF, 0xFEE0], // lam
        0x0645 => [0xFEE1, 0xFEE2, 0xFEE3, 0xFEE4], // meem
        0x0646 => [0xFEE5, 0xFEE6, 0xFEE7, 0xFEE8], // noon
        0x0647 => [0xFEE9, 0xFEEA, 0xFEEB, 0xFEEC], // heh
        0x0648 => [0xFEED, 0xFEEE, 0xFEED, 0xFEEE], // waw
        0x0649 => [0xFEEF, 0xFEF0, 0xFEEF, 0xFEF0], // alef maksura
        0x064A => [0xFEF1, 0xFEF2, 0xFEF3, 0xFEF4], // yeh
    ];

    /** letters that join only to their right (do not connect to the next letter) */
    private const NO_NEXT = [
        0x0622, 0x0623, 0x0624, 0x0625, 0x0627, 0x0629,
        0x062F, 0x0630, 0x0631, 0x0632, 0x0648, 0x0621,
    ];

    /** lam + alef variants => single ligature [isolated, final] */
    private const LAM_ALEF = [
        0x0622 => [0xFEF5, 0xFEF6], // lam-alef madda
        0x0623 => [0xFEF7, 0xFEF8], // lam-alef hamza above
        0x0625 => [0xFEF9, 0xFEFA], // lam-alef hamza below
        0x0627 => [0xFEFB, 0xFEFC], // lam-alef
    ];

    private const MIRROR = [
        0x28 => 0x29, 0x29 => 0x28, 0x5B => 0x5D, 0x5D => 0x5B,
        0x7B => 0x7D, 0x7D => 0x7B, 0x3C => 0x3E, 0x3E => 0x3C,
        0xAB => 0xBB, 0xBB => 0xAB,
    ];

    /**
     * Shape + reorder a (possibly mixed Arabic/Latin) string for an RTL,
     * non-bidi-aware PDF engine. Strings with no Arabic are returned unchanged.
     *
     * Because the engine wraps lines AFTER we have reversed for RTL, a long
     * paragraph that wraps would read bottom-to-top. To avoid that we wrap the
     * *logical* text into lines of at most $maxChars (breaking on spaces),
     * reverse each line independently, and join them with <br> — so multi-line
     * Arabic reads top-to-bottom correctly. Pass $maxChars = 0 for single-line
     * content (headings/labels) that never wraps. The returned string contains
     * presentation-form glyphs in visual order (and literal <br> tags when
     * wrapped) and is meant to be injected as trusted HTML, not escaped again.
     */
    public static function shape(?string $text, int $maxChars = 0): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        if (!self::containsArabic(self::toCodepoints($text))) {
            return $text;
        }

        if ($maxChars > 0) {
            $lines = self::wrapLogical($text, $maxChars);
            $out = [];
            foreach ($lines as $line) {
                $out[] = self::shapeLine($line);
            }
            return implode('<br>', $out);
        }

        return self::shapeLine($text);
    }

    /** Greedy word-wrap on the LOGICAL (pre-shaping) text, breaking at spaces. */
    private static function wrapLogical(string $text, int $maxChars): array
    {
        $words = preg_split('/ +/u', trim($text));
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $candidate = $cur === '' ? $w : $cur . ' ' . $w;
            if (mb_strlen($candidate, 'UTF-8') > $maxChars && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $candidate;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines ?: [$text];
    }

    /** Shape + visually reorder a single line (no internal wrapping). */
    private static function shapeLine(string $text): string
    {
        $cps = self::toCodepoints($text);
        if (!self::containsArabic($cps)) {
            return $text;
        }

        // ── 1. lam-alef ligatures (lam followed by an alef variant) ──────────
        $merged = [];
        $n = count($cps);
        for ($i = 0; $i < $n; $i++) {
            $c = $cps[$i];
            if ($c === 0x0644 && $i + 1 < $n && isset(self::LAM_ALEF[$cps[$i + 1]])) {
                $merged[] = ['lamalef', $cps[$i + 1]];
                $i++;
                continue;
            }
            $merged[] = $c;
        }

        // ── 2. contextual shaping in logical order ───────────────────────────
        $shaped = [];
        $m = count($merged);
        for ($i = 0; $i < $m; $i++) {
            $tok = $merged[$i];

            // resolve joining neighbours (skip non-joining marks)
            $prev = self::neighbour($merged, $i, -1);
            $next = self::neighbour($merged, $i, +1);

            if (is_array($tok)) { // lam-alef ligature: only isolated/final
                $joinPrev = $prev !== null && !in_array($prev, self::NO_NEXT, true);
                $form = $joinPrev ? 1 : 0;
                $shaped[] = self::LAM_ALEF[$tok[1]][$form];
                continue;
            }

            if (!isset(self::FORMS[$tok])) {
                $shaped[] = $tok;
                continue;
            }

            $joinPrev = $prev !== null && !in_array($prev, self::NO_NEXT, true);
            $joinNext = $next !== null && !in_array($tok, self::NO_NEXT, true);
            $form = ($joinPrev && $joinNext) ? 3 : ($joinPrev ? 1 : ($joinNext ? 2 : 0));
            $shaped[] = self::FORMS[$tok][$form];
        }

        // ── 3. reverse whole run, then restore LTR sub-runs (Latin/digits) ───
        $rev = array_reverse($shaped);
        $len = count($rev);
        $i = 0;
        while ($i < $len) {
            if (!self::isArabic($rev[$i])) {
                $j = $i;
                while ($j < $len && !self::isArabic($rev[$j])) {
                    $j++;
                }
                $sub = array_slice($rev, $i, $j - $i);
                $hasStrongL = false;
                foreach ($sub as $cc) {
                    if (self::isStrongLtr($cc)) {
                        $hasStrongL = true;
                        break;
                    }
                }
                $sub = array_reverse($sub);
                if (!$hasStrongL) {
                    // pure-neutral run in RTL context → mirror brackets
                    foreach ($sub as $k => $cc) {
                        $sub[$k] = self::MIRROR[$cc] ?? $cc;
                    }
                }
                array_splice($rev, $i, $j - $i, $sub);
                $i = $j;
            } else {
                $i++;
            }
        }

        return self::fromCodepoints($rev);
    }

    /** nearest joining neighbour codepoint in $dir, skipping nothing but stopping at non-Arabic */
    private static function neighbour(array $merged, int $i, int $dir)
    {
        for ($j = $i + $dir; $j >= 0 && $j < count($merged); $j += $dir) {
            $t = $merged[$j];
            if (is_array($t)) {
                return 0x0644; // a lam-alef ligature joins like a lam on its right
            }
            if (isset(self::FORMS[$t]) || $t === 0x0640) {
                return $t;
            }
            if (!self::isArabic($t)) {
                return null;
            }
        }
        return null;
    }

    private static function toCodepoints(string $s): array
    {
        $r = [];
        $len = mb_strlen($s, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $r[] = mb_ord(mb_substr($s, $i, 1, 'UTF-8'), 'UTF-8');
        }
        return $r;
    }

    private static function fromCodepoints(array $cps): string
    {
        $s = '';
        foreach ($cps as $cp) {
            $s .= mb_chr($cp, 'UTF-8');
        }
        return $s;
    }

    private static function containsArabic(array $cps): bool
    {
        foreach ($cps as $c) {
            if (self::isArabic($c)) {
                return true;
            }
        }
        return false;
    }

    private static function isArabic(int $c): bool
    {
        return ($c >= 0x0600 && $c <= 0x06FF)
            || ($c >= 0x0750 && $c <= 0x077F)
            || ($c >= 0xFB50 && $c <= 0xFDFF)
            || ($c >= 0xFE70 && $c <= 0xFEFF);
    }

    private static function isStrongLtr(int $c): bool
    {
        return ($c >= 0x30 && $c <= 0x39)  // digits
            || ($c >= 0x41 && $c <= 0x5A)  // A-Z
            || ($c >= 0x61 && $c <= 0x7A); // a-z
    }
}
