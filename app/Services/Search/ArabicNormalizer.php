<?php

namespace App\Services\Search;

/**
 * تطبيع النص العربي لأغراض البحث والمقارنة فقط. النص الأصلي لا يُعدَّل أبدًا؛
 * الناتج يُخزَّن في أعمدة *_search المنفصلة.
 *
 * ما يفعله: حذف التشكيل والتطويل وعلامات الضبط القرآنية، توحيد صور الألف والياء والتاء المربوطة
 * والهمزات على الواو والياء، تحويل الأرقام الهندية إلى لاتينية، وتحويل علامات الترقيم إلى مسافة.
 */
class ArabicNormalizer
{
    /** تشكيل، ألف خنجرية، علامات قرآنية، تطويل، وحروف اتجاه غير مرئية. */
    private const IGNORABLE = '/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E8}\x{06EA}-\x{06ED}\x{0640}\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}]/u';

    private const MAP = [
        // صور الألف
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        // الألف المقصورة والهمزة على نبرة
        'ى' => 'ي', 'ئ' => 'ي', 'ؤ' => 'و',
        // التاء المربوطة
        'ة' => 'ه',
        // حروف فارسية/أردية تظهر في النسخ الممسوحة
        'ی' => 'ي', 'ې' => 'ي', 'ک' => 'ك', 'گ' => 'ك',
        // أرقام هندية شرقية وفارسية
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $text = preg_replace(self::IGNORABLE, '', $text) ?? '';
        $text = strtr($text, self::MAP);
        $text = mb_strtolower($text);
        // أي شيء ليس حرفًا ولا رقمًا (ترقيم، رموز) يصير فاصلًا بين الكلمات
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /** @return list<string> كلمات النص بعد التطبيع، بلا تكرار */
    public function tokens(?string $text): array
    {
        $normalized = $this->normalize($text);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    /** يهرّب محارف LIKE الخاصة حتى تُعامل نصًّا حرفيًّا. */
    public function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
