<?php

namespace Tests\Feature;

use App\Services\PiiRedactor;
use Tests\TestCase;

class PiiRedactorTest extends TestCase
{
    public function test_redacts_english_identifiers_and_labeled_personal_fields(): void
    {
        $result = app(PiiRedactor::class)->redact(
            "Full name: Mazen Hossny\nEmail: mazen@example.com\nPhone: +966 50 530 8084\nOrder #1234567\nAddress: 4521 King Abdul Aziz Road\nhttps://example.com/order/123"
        );

        $this->assertSame(['name', 'address', 'order_number', 'url', 'email', 'phone'], $result['types']);
        $this->assertStringNotContainsString('Mazen Hossny', $result['text']);
        $this->assertStringNotContainsString('mazen@example.com', $result['text']);
        $this->assertStringNotContainsString('+966 50 530 8084', $result['text']);
        $this->assertStringNotContainsString('1234567', $result['text']);
        $this->assertStringNotContainsString('4521 King Abdul Aziz Road', $result['text']);
        $this->assertStringNotContainsString('https://example.com', $result['text']);
    }

    public function test_redacts_arabic_labels_and_arabic_indic_digits(): void
    {
        $result = app(PiiRedactor::class)->redact(
            "الاسم الكامل: مازن حسني\nالبريد mazen@example.com\nالهاتف ٠١١٥٢٨٧٩٧٥٥\nطلب رقم ١٢٣٤٥٦٧\nعنوان التوصيل: شارع الاختبار ١٢"
        );

        $this->assertContains('name', $result['types']);
        $this->assertContains('address', $result['types']);
        $this->assertContains('phone', $result['types']);
        $this->assertContains('order_number', $result['types']);
        $this->assertContains('email', $result['types']);
        $this->assertStringNotContainsString('مازن حسني', $result['text']);
        $this->assertStringNotContainsString('٠١١٥٢٨٧٩٧٥٥', $result['text']);
        $this->assertStringNotContainsString('١٢٣٤٥٦٧', $result['text']);
        $this->assertStringNotContainsString('mazen@example.com', $result['text']);
    }
}
