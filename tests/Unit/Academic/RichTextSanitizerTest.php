<?php

namespace Tests\Unit\Academic;

use App\Modules\Academic\Services\RichTextSanitizer;
use PHPUnit\Framework\TestCase;

class RichTextSanitizerTest extends TestCase
{
    public function test_it_keeps_supported_formatting_and_removes_executable_markup(): void
    {
        $html = '<h2>Titre sûr</h2><p onclick="alert(1)"><strong>Important</strong></p>'
            .'<ol type="a" style="color:red"><li>Premier</li></ol>'
            .'<script>alert(2)</script><img src=x onerror=alert(3)>';

        $result = (new RichTextSanitizer)->sanitize($html);

        $this->assertStringContainsString('<strong>Important</strong>', $result);
        $this->assertStringContainsString('<h2>Titre sûr</h2>', $result);
        $this->assertStringContainsString('<ol type="a"><li>Premier</li></ol>', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('style=', $result);
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('<img', $result);
        $this->assertStringNotContainsString('alert(2)', $result);
    }

    public function test_it_preserves_line_breaks_from_plain_text_clients(): void
    {
        $result = (new RichTextSanitizer)->sanitize("Première ligne\nDeuxième ligne");

        $this->assertStringContainsString('Première ligne<br>', $result);
        $this->assertStringContainsString('Deuxième ligne', $result);
    }
}
