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

/**
 * Saqr Retriever — keyword scoring with UTF-8 byte-length weighting.
 *
 * The score-per-match uses strlen() which returns BYTE count in PHP,
 * not codepoint count. This is intentional: an Arabic keyword like
 * "ايزو" (4 codepoints / 8 UTF-8 bytes) scores 8, while "iso" scores 3.
 * A reimplementation using JS String.length (UTF-16 code units) would
 * silently re-rank Arabic-heavy queries. See:
 *   tests/Characterization/RetrieverCharacterizationTest.php
 *     "Arabic keyword strlen counts UTF-8 bytes, not codepoints"
 */
final class Retriever
{
    private Corpus $corpus;

    public function __construct(Corpus $corpus)
    {
        $this->corpus = $corpus;
    }

    /**
     * @return array<int, array{id: ?string, title: ?string, framework: ?string, category: ?string, keywords: array<int, string>, answer: string, refs: array<int, array{title: string, url: string}>}>
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
            'المجالات الرئيسية'               => 'ecc main domains',
            'ضوابط الأنظمة الحساسة'           => 'cscc critical systems controls',
            // stem of 'ضوابط الحوسبة السحابية', which it subsumes: the definite
            // article assimilates in "للحوسبة السحابية"
            'حوسبة السحابية'                  => 'nca ccc cloud cybersecurity controls',
            'ضوابط البيانات'                  => 'nca dcc data',
            'ضوابط العمل عن بعد'              => 'nca tcc telework',
            'ضوابط حسابات التواصل'            => 'nca osmacc social media',
            'إطار القوى العاملة'              => 'nca scywf workforce',
            // SAMA
            'البنك المركزي السعودي'           => 'who is sama saudi central bank',
            'مؤسسة النقد'                     => 'who is sama',
            'ساما'                            => 'who is sama',
            'إطار الأمن السيبراني ساما'       => 'sama csf cyber security framework',
            'إطار الأمن السيبراني لمؤسسة النقد' => 'sama csf sama cyber security framework',
            'إطار الأمن السيبراني للبنك المركزي' => 'sama csf sama cyber security framework',
            'إطار حوكمة تقنية المعلومات'      => 'sama itgf it governance',
            'استمرارية الأعمال'               => 'sama bcm business continuity',
            // CST / Aramco / PDPL / SDAIA / ISO
            'هيئة الاتصالات'                  => 'cst communications',
            'قطاع الاتصالات'                  => 'cst telecom regulator',
            'الإطار التنظيمي للأمن السيبراني' => 'cst crf',
            'أرامكو'                          => 'aramco sacs',
            'ارامكو'                          => 'aramco sacs',
            'نظام حماية البيانات'             => 'pdpl personal data',
            'حماية البيانات الشخصية'          => 'pdpl personal data',
            // PDPL is a نظام, but speakers reach for قانون ("law") just as often.
            'قانون حماية البيانات'            => 'pdpl personal data protection law',
            // Bare stem, so a question that names neither نظام/قانون nor
            // الشخصية still lands on PDPL. It subsumes the three keys above;
            // they stay because their values are not identical.
            'حماية البيانات'                  => 'pdpl personal data',
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
            // stem, not 'التدقيق': the definite article assimilates in "للتدقيق"
            'تدقيق'                           => 'audit',
            'الفحص'                           => 'audit inspection',
            'الطرف الثالث'                    => 'third party',
            'الموردين'                        => 'third party vendor',
            'قائمة الأطر'                     => 'list frameworks',
            'الأطر'                           => 'list frameworks',
            // Comparison intents. A comparison entry only outranks the frameworks
            // it compares when the Arabic phrasing pins the comparison itself,
            // by naming both sides or by asking whether one satisfies the other;
            // the value emits the entry's own acronym-pair keywords. Keep these
            // keys on the comparison, never on one framework alone: 'استخدام أيزو'
            // would drag every ordinary "using ISO 27001" question here.
            'أيزو 27001 لتلبية'               => 'iso vs nca iso and ecc',
            'أيزو 27001 وضوابط'               => 'iso vs nca iso and ecc',
            'مؤسسة النقد أم'                  => 'sama vs nca sama or nca',
            'حماية البيانات الشخصية وضوابط البيانات' => 'pdpl vs dcc pdpl and dcc',
            'الفرق بين متطلبات أرامكو'        => 'aramco vs nca aramco and nca',
        ];

        // Match on an orthographically folded copy so the same alias catches
        // both spellings a writer might use. Folding the keys once here keeps
        // the map itself readable in its canonical spelling.
        static $folded = null;
        if ($folded === null) {
            $folded = [];
            foreach ($map as $ar => $en) {
                if ($en !== '') {
                    $folded[] = [self::foldOrthography($ar), $en];
                }
            }
        }

        $subject = self::foldOrthography($q);
        $aliases = '';
        foreach ($folded as [$ar, $en]) {
            if (mb_strpos($subject, $ar) !== false) {
                $aliases .= ' ' . $en;
            }
        }
        // $q, not $subject: the caller's text reaches the generator unaltered.
        return $q . $aliases;
    }

    /**
     * Fold the Arabic letter pairs that writers use interchangeably and that
     * carry no distinction worth honouring in a keyword search: a final taa
     * marbuta written as a plain heh (حمايه for حماية) and an alef maqsura
     * written as a yaa (مستوي for مستوى). Applied to both sides of the alias
     * comparison — never to the string handed back to the retriever.
     */
    private static function foldOrthography(string $s): string
    {
        return strtr($s, ['ة' => 'ه', 'ى' => 'ي']);
    }
}
