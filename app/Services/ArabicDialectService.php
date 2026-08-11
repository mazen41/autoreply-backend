<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ArabicDialectService
{
    /**
     * Detect Arabic dialect from text
     * Returns: 'egyptian', 'gulf', 'msa' (Modern Standard Arabic), or 'unknown'
     */
    public static function detectDialect(string $text): string
    {
        // Clean and normalize text
        $text = self::normalizeArabicText($text);
        
        if (empty($text) || !self::containsArabic($text)) {
            return 'unknown';
        }

        $egyptianScore = 0;
        $gulfScore = 0;
        $msaScore = 0;

        // Egyptian Arabic indicators
        $egyptianKeywords = [
            'مفيش', 'مش', 'ازاي', 'يلا', 'بصّ', 'قنبلي', 'حاجة', 'ناملي',
            'ده', 'دي', 'انام', 'تعالي', 'روح', 'نيجي', 'بقولك', 'عايز',
            'أهلاً', 'مرحباً', 'صباحي', 'مساءي', 'حاضر', 'معلش', 'تؤمر',
            'بص على', 'معاك', 'يا معلم', 'يا زميلي', 'يا ساتر', 'يا بطل',
        ];

        // Gulf Arabic indicators
        $gulfKeywords = [
            'شلو', 'ليش', 'ابشر', 'مادري', 'وش', 'لن', 'بنت', 'خير',
            'يا خالي', 'يا خوي', 'الله يعطيك العافية', 'الله يوفقك',
            'تفي', 'تضل', 'ضف', 'بس', 'ماشاء الله', 'ان شاء الله',
            'يا الغالي', 'يا حبيبي', 'الله يسعدك', 'بودي', 'يابو',
        ];

        // Modern Standard Arabic indicators
        $msaKeywords = [
            'مرحباً', 'أهلاً', 'كيف حالك', 'شكراً', 'عفواً', 'من فضلك',
            'أود', 'أرغب', 'يمكن', 'سوف', 'من أجل', 'حيث', 'الذي', 'التي',
            'إنه', 'أنه', 'لقد', 'فيما', 'عن', 'على', 'إلى', 'من', 'في',
        ];

        // Check for Egyptian keywords
        foreach ($egyptianKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $egyptianScore += 2; // Strong indicators
            }
        }

        // Check for Gulf keywords
        foreach ($gulfKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $gulfScore += 2; // Strong indicators
            }
        }

        // Check for MSA keywords
        foreach ($msaKeywords as $keyword) {
            if (str_contains($text, $keyword)) {
                $msaScore += 1; // Weaker indicators (common across dialects)
            }
        }

        // Additional dialect-specific patterns
        $egyptianPatterns = ['/ش$/u', '/بص/u', '/ده$/u', '/دي$/u'];
        $gulfPatterns = ['/ش$/u', '/لن$/u', '/مادري/u'];
        
        foreach ($egyptianPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $egyptianScore += 1;
            }
        }

        foreach ($gulfPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                $gulfScore += 1;
            }
        }

        // Determine dialect based on scores
        if ($egyptianScore > $gulfScore && $egyptianScore > $msaScore) {
            return 'egyptian';
        } elseif ($gulfScore > $egyptianScore && $gulfScore > $msaScore) {
            return 'gulf';
        } elseif ($msaScore > 0 && $msaScore >= max($egyptianScore, $gulfScore)) {
            return 'msa';
        }

        // If scores are close or low, check for mixed content
        if ($egyptianScore > 0 && $gulfScore > 0) {
            return 'mixed';
        }

        return 'unknown';
    }

    /**
     * Get dialect-specific response style
     */
    public static function getDialectStyle(string $dialect): array
    {
        $styles = [
            'egyptian' => [
                'tone' => 'friendly',
                'formality' => 'casual',
                'greeting' => 'أهلاً بيك',
                'closing' => 'مع السلامة',
            ],
            'gulf' => [
                'tone' => 'friendly',
                'formality' => 'casual',
                'greeting' => 'أهلاً وسهلاً',
                'closing' => 'مع السلامة',
            ],
            'msa' => [
                'tone' => 'professional',
                'formality' => 'formal',
                'greeting' => 'مرحباً',
                'closing' => 'شكراً لك',
            ],
            'unknown' => [
                'tone' => 'friendly',
                'formality' => 'casual',
                'greeting' => 'مرحباً',
                'closing' => 'مع السلامة',
            ],
        ];

        return $styles[$dialect] ?? $styles['unknown'];
    }

    /**
     * Normalize Arabic text for dialect detection
     */
    private static function normalizeArabicText(string $text): string
    {
        // Remove diacritics (tashkeel)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        
        // Normalize alef variants
        $text = str_replace(['أ', 'إ', 'آ'], 'ا', $text);
        
        // Normalize teh marbuta
        $text = str_replace('ة', 'ه', $text);
        
        // Normalize yeh variants
        $text = str_replace(['ى', 'ئ'], 'ي', $text);
        
        return $text;
    }

    /**
     * Check if text contains Arabic characters
     */
    private static function containsArabic(string $text): bool
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) === 1;
    }
}
