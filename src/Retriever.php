<?php
declare(strict_types=1);

namespace Saqr;

/**
 * Keyword-based top-k retriever for a Saqr corpus.
 *
 * Scoring: for each KB entry, sum strlen() of every keyword that appears
 * in the (lowercased, Arabic-normalized) question. Longer keyword matches
 * win over shorter ones. Simple, deterministic, no embeddings, no
 * external services — good enough for a curated practitioner corpus.
 */
final class Retriever
{
    private Corpus $corpus;

    public function __construct(Corpus $corpus)
    {
        $this->corpus = $corpus;
    }

    /**
     * @return array<int, array{category: ?string, keywords: array<int, string>, answer: string}>
     */
    public function retrieveTopK(string $question, int $k = 3): array
    {
        $q = mb_strtolower($this->normalizeArabic($question), 'UTF-8');

        $scored = [];
        foreach ($this->corpus->all() as $entry) {
            $score = 0;
            foreach ($entry['keywords'] as $kw) {
                if ($kw !== '' && mb_strpos($q, $kw) !== false) {
                    $score += strlen($kw);
                }
            }
            if ($score > 0) {
                $scored[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($scored, static fn ($a, $b) => $b['score'] - $a['score']);
        $top = array_slice($scored, 0, max(1, $k));

        return array_map(static fn ($row) => $row['entry'], $top);
    }

    /**
     * Map common Arabic phrasings to their English KB keyword equivalents.
     * The corpus keywords are stored in English; this lets Arabic questions
     * hit the right entries without duplicating the corpus.
     *
     * Returns the original question with English aliases appended (so both
     * the Arabic phrase and the English keywords are searchable).
     */
    private function normalizeArabic(string $q): string
    {
        // Quick exit if no Arabic glyphs present
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $q)) {
            return $q;
        }

        static $map = [
            // meta / who-is-this
            'من انت'                          => 'who are you',
            'من أنت'                          => 'who are you',
            'مين انت'                         => 'who are you',
            'عرف عن نفسك'                     => 'who are you',
            'ماذا تفعل'                       => 'who are you',
            // common interrogatives (no-op stripping handled separately)
            'ما هو'                           => '',
            'ما هي'                           => '',
            'كيف'                             => '',
            // NCA authority + frameworks
            'الهيئة الوطنية للأمن السيبراني'  => 'national cybersecurity authority',
            'الهيئة الوطنية'                  => 'national cybersecurity authority',
            'الضوابط الأساسية للأمن السيبراني' => 'nca ecc essential cybersecurity controls',
            'الضوابط الأساسية'                => 'nca ecc essential cybersecurity controls',
            'ضوابط الأنظمة الحساسة'           => 'nca ccc critical systems',
            'ضوابط الحوسبة السحابية'          => 'nca cscc cloud',
            'ضوابط البيانات'                  => 'nca dcc data',
            'ضوابط العمل عن بعد'              => 'nca tcc telework',
            'ضوابط حسابات التواصل'            => 'nca osmacc social media',
            'إطار القوى العاملة'              => 'nca scywf workforce',
            // SAMA
            'البنك المركزي السعودي'           => 'who is sama saudi central bank',
            'مؤسسة النقد'                     => 'who is sama',
            'ساما'                            => 'who is sama',
            'إطار الأمن السيبراني ساما'       => 'sama csf cyber security framework',
            'إطار حوكمة تقنية المعلومات'      => 'sama itgf it governance',
            'استمرارية الأعمال'               => 'sama bcm business continuity',
            // CST / Aramco / PDPL / SDAIA / ISO
            'هيئة الاتصالات'                  => 'cst communications',
            'الإطار التنظيمي للأمن السيبراني' => 'cst crf',
            'أرامكو'                          => 'aramco sacs',
            'ارامكو'                          => 'aramco sacs',
            'نظام حماية البيانات'             => 'pdpl personal data',
            'حماية البيانات الشخصية'          => 'pdpl personal data',
            'سدايا'                           => 'sdaia',
            'هيئة البيانات والذكاء الاصطناعي' => 'sdaia',
            'أيزو 27001'                      => 'iso 27001',
            'ايزو 27001'                      => 'iso 27001',
            'أيزو'                            => 'iso 27001',
            'ايزو'                            => 'iso 27001',
            // practitioner advice
            'من أين أبدأ'                     => 'where do i start',
            'من اين ابدأ'                     => 'where do i start',
            'كيف ابدأ'                        => 'where do i start',
            'كيف أبدأ'                        => 'where do i start',
            'النضج'                           => 'maturity',
            'مستوى النضج'                     => 'maturity',
            'التدقيق'                         => 'audit',
            'الفحص'                           => 'audit inspection',
            'الطرف الثالث'                    => 'third party',
            'الموردين'                        => 'third party vendor',
            'قائمة الأطر'                     => 'list frameworks',
            'الأطر'                           => 'list frameworks',
        ];

        $aliases = '';
        foreach ($map as $ar => $en) {
            if ($en !== '' && mb_strpos($q, $ar) !== false) {
                $aliases .= ' ' . $en;
            }
        }
        return $q . $aliases;
    }
}
