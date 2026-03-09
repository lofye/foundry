<?php
declare(strict_types=1);

namespace Foundry\Tests\Unit;

use Foundry\Generation\FormSchemaRenderer;
use PHPUnit\Framework\TestCase;

final class FormSchemaRendererTest extends TestCase
{
    public function test_renders_supported_field_types_with_accessible_ids(): void
    {
        $renderer = new FormSchemaRenderer();
        $html = $renderer->render('post_form', [
            'title' => ['form' => 'text', 'required' => true],
            'body' => ['form' => 'textarea'],
            'email' => ['form' => 'email'],
            'password' => ['form' => 'password'],
            'status' => ['form' => 'select', 'enum' => ['draft', 'published']],
            'visibility' => ['form' => 'radio', 'enum' => ['public', 'private']],
            'is_featured' => ['form' => 'checkbox'],
            'published_at' => ['form' => 'datetime'],
            'csrf_token' => ['form' => 'hidden'],
            'attachment' => ['form' => 'file'],
            'tags' => ['form' => 'array'],
        ]);

        $this->assertStringContainsString('name="_csrf"', $html);
        $this->assertStringContainsString('id="post_form__title"', $html);
        $this->assertStringContainsString('<textarea id="post_form__body"', $html);
        $this->assertStringContainsString('type="email"', $html);
        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('<select id="post_form__status"', $html);
        $this->assertStringContainsString('type="radio" name="visibility"', $html);
        $this->assertStringContainsString('type="checkbox" id="post_form__is_featured"', $html);
        $this->assertStringContainsString('type="datetime-local"', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('id="post_form__csrf_token"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
        $this->assertStringContainsString('type="file" id="post_form__attachment"', $html);
        $this->assertStringContainsString('name="tags"', $html);
        $this->assertStringContainsString('aria-describedby="post_form__title__help post_form__title__error"', $html);
    }
}
