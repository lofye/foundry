<?php
declare(strict_types=1);

namespace Foundry\Documentation;

final class MarkdownPageRenderer
{
    public function render(string $markdown): string
    {
        $lines = preg_split('/\R/', $markdown) ?: [];
        $html = [];
        $paragraph = [];
        $listType = null;
        $inCodeFence = false;
        $codeLanguage = '';
        $codeLines = [];

        $flushParagraph = function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }

            $text = trim(implode(' ', array_map(static fn (string $line): string => trim($line), $paragraph)));
            if ($text !== '') {
                $html[] = '<p>' . $this->renderInline($text) . '</p>';
            }

            $paragraph = [];
        };

        $flushList = function () use (&$listType, &$html): void {
            if ($listType === null) {
                return;
            }

            $html[] = '</' . $listType . '>';
            $listType = null;
        };

        $flushCode = function () use (&$inCodeFence, &$codeLanguage, &$codeLines, &$html): void {
            if (!$inCodeFence) {
                return;
            }

            $class = $codeLanguage !== '' ? ' class="language-' . htmlspecialchars($codeLanguage, ENT_QUOTES) . '"' : '';
            $html[] = '<pre><code' . $class . '>' . htmlspecialchars(implode("\n", $codeLines), ENT_QUOTES) . '</code></pre>';
            $inCodeFence = false;
            $codeLanguage = '';
            $codeLines = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^```([\w-]+)?\s*$/', $line, $matches) === 1) {
                $flushParagraph();
                $flushList();

                if ($inCodeFence) {
                    $flushCode();
                } else {
                    $inCodeFence = true;
                    $codeLanguage = (string) ($matches[1] ?? '');
                    $codeLines = [];
                }

                continue;
            }

            if ($inCodeFence) {
                $codeLines[] = $line;
                continue;
            }

            if (trim($line) === '') {
                $flushParagraph();
                $flushList();
                continue;
            }

            if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $flushList();

                $level = strlen((string) $matches[1]);
                $text = trim((string) $matches[2]);
                $html[] = sprintf(
                    '<h%d id="%s">%s</h%d>',
                    $level,
                    $this->headingId($text),
                    $this->renderInline($text),
                    $level,
                );
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $flushList();
                    $listType = 'ul';
                    $html[] = '<ul>';
                }

                $html[] = '<li>' . $this->renderInline((string) $matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $flushList();
                    $listType = 'ol';
                    $html[] = '<ol>';
                }

                $html[] = '<li>' . $this->renderInline((string) $matches[1]) . '</li>';
                continue;
            }

            $paragraph[] = $line;
        }

        $flushCode();
        $flushParagraph();
        $flushList();

        return implode("\n", $html) . "\n";
    }

    private function headingId(string $text): string
    {
        $slug = strtolower($text);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'section';
    }

    private function renderInline(string $text): string
    {
        $tokens = [];

        $text = preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $matches) use (&$tokens): string {
                $token = '__CODE_TOKEN_' . count($tokens) . '__';
                $tokens[$token] = '<code>' . htmlspecialchars((string) $matches[1], ENT_QUOTES) . '</code>';

                return $token;
            },
            $text,
        ) ?? $text;

        $escaped = htmlspecialchars($text, ENT_QUOTES);
        $escaped = preg_replace_callback(
            '/\[(.+?)\]\((.+?)\)/',
            static function (array $matches): string {
                return '<a href="' . htmlspecialchars((string) $matches[2], ENT_QUOTES) . '">'
                    . htmlspecialchars((string) $matches[1], ENT_QUOTES)
                    . '</a>';
            },
            $escaped,
        ) ?? $escaped;
        $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;

        return strtr($escaped, $tokens);
    }
}
