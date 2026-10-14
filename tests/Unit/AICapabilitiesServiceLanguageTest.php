<?php

namespace Tests\Unit;

use App\Services\AICapabilitiesService;
use PHPUnit\Framework\TestCase;

class AICapabilitiesServiceLanguageTest extends TestCase
{
    public function test_detect_language_handles_arabic_unicode_without_invalid_regex(): void
    {
        $this->assertSame('arabic', AICapabilitiesService::detectLanguage('هل يوجد توصيل إلى القاهرة؟')['language']);
    }

    public function test_detect_language_handles_english_text(): void
    {
        $this->assertSame('english', AICapabilitiesService::detectLanguage('Is there delivery to Cairo?')['language']);
    }
}
