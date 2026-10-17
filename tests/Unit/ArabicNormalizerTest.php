<?php

namespace Tests\Unit;

use App\Services\Search\ArabicNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArabicNormalizerTest extends TestCase
{
    private ArabicNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new ArabicNormalizer;
    }

    #[DataProvider('cases')]
    public function test_normalizes(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->n->normalize($input));
    }

    public static function cases(): array
    {
        return [
            'تشكيل' => ['مُحَمَّدُ بْنُ إِسْمَاعِيلَ', 'محمد بن اسماعيل'],
            'همزات الألف' => ['أحمد إبراهيم آل ٱلله', 'احمد ابراهيم ال الله'],
            'ألف مقصورة وتاء مربوطة' => ['مصطفى المدينة', 'مصطفي المدينه'],
            'همزة على نبرة وواو' => ['مسئول مؤمن', 'مسيول مومن'],
            'تطويل' => ['عبـــد', 'عبد'],
            'ألف خنجرية' => ['الرحمٰن', 'الرحمن'],
            'أرقام هندية' => ['سنة ٢٥٦', 'سنه 256'],
            'ترقيم' => ['البخاري، (ت. 256هـ)', 'البخاري ت 256ه'],
            'مسافات متعددة' => ["  ابن   حجر \n العسقلاني ", 'ابن حجر العسقلاني'],
            'لاتيني يُخفَّض' => ['Al-Bukhari', 'al bukhari'],
            'فارغ' => ['', ''],
            'ترقيم فقط' => ['...،،', ''],
        ];
    }

    public function test_null_is_empty(): void
    {
        $this->assertSame('', $this->n->normalize(null));
        $this->assertSame([], $this->n->tokens(null));
    }

    public function test_is_idempotent(): void
    {
        $once = $this->n->normalize('مُحَمَّدُ بْنُ أَحْمَدَ الإِسْكَنْدَرَانِيّ');
        $this->assertSame($once, $this->n->normalize($once));
    }

    public function test_tokens_are_unique_and_ordered(): void
    {
        $this->assertSame(['محمد', 'بن', 'احمد'], $this->n->tokens('محمد بن أحمد محمد'));
    }

    public function test_escape_like_neutralises_wildcards(): void
    {
        $this->assertSame('100\\%\\_x\\\\', $this->n->escapeLike('100%_x\\'));
    }

    public function test_original_text_is_never_the_stored_form(): void
    {
        // الدالة تُرجع نسخة جديدة؛ لا شيء يُعدَّل في المُدخل
        $original = 'أَحْمَدُ';
        $this->n->normalize($original);
        $this->assertSame('أَحْمَدُ', $original);
    }
}
