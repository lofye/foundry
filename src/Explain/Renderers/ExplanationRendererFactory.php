<?php
declare(strict_types=1);

namespace Foundry\Explain\Renderers;

final class ExplanationRendererFactory
{
    public function forFormat(string $format): ExplanationRendererInterface
    {
        return match (strtolower($format)) {
            'markdown' => new MarkdownExplanationRenderer(),
            'json' => new JsonExplanationRenderer(),
            default => new TextExplanationRenderer(),
        };
    }
}
