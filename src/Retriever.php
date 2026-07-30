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
        $q = self::matchingText($question);

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
     * Build the string retrieval scores against: the question run through the
     * orthographic pipeline, with the English KB keywords of every matching
     * Arabic alias appended.
     *
     * The caller's string is never modified. Everything here is a matching
     * device; the generator must see the question exactly as it was typed.
     */
    private static function matchingText(string $question): string
    {
        $subject = self::normalize($question);

        // Alias lookup only pays off when the question carries Arabic at all.
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $subject)) {
            return $subject;
        }

        return $subject . self::arabicAliases($subject);
    }

    /**
     * Map common Arabic phrasings to their English KB keyword equivalents.
     * The corpus keywords are stored in English; this lets Arabic questions
     * hit the right entries without duplicating the corpus.
     *
     * Keys are written in their canonical spelling and are run through
     * self::normalize() once, so a key never needs a hand-written variant for
     * hamza, taa marbuta, alef maqsura, diacritics, or digit shape. Keys that
     * only differed by one of those folds have been removed, not rewritten.
     *
     * @return string the matching English keywords, each prefixed with a space
     */
    private static function arabicAliases(string $subject): string
    {
        static $map = [
            // meta / who-is-this
            'من أنت'                          => 'who are you',
            'مين انت'                         => 'who are you',
            'عرف عن نفسك'                     => 'who are you',
            'ماذا تفعل'                       => 'who are you',
            // common interrogatives (no-op stripping handled separately)
            'ما هو'                           => '',
            'ما هي'                           => '',
            'كيف'                             => '',
            // NCA authority + frameworks
            // stem, so the assimilated "للهيئة الوطنية للأمن السيبراني" lands.
            // The bare 'الهيئة الوطنية' below is deliberately NOT stemmed: the
            // truncated للهيئة الوطنية is how speakers refer to the regulator
            // inside a comparison question, and firing there puts the authority
            // entry ahead of the comparison entry that answers it.
            'هيئة الوطنية للأمن السيبراني'    => 'national cybersecurity authority',
            'الهيئة الوطنية'                  => 'national cybersecurity authority',
            // Arabic renderings of the acronyms. The corpus spells every acronym
            // in Latin, so a transliterated question matches nothing on its own.
            // Each one emits the entry's acronym keywords rather than its full
            // title: a transliteration should weigh what typing the Latin
            // acronym weighs, or naming two of them buries the comparison entry
            // that answers the question under the two entries it compares.
            'نكا'                             => 'nca overview who is nca',
            'ان سي اي'                        => 'nca overview who is nca',
            'الضوابط الأساسية للأمن السيبراني' => 'nca ecc essential cybersecurity controls',
            // stem, not 'الضوابط الأساسية': the definite article assimilates in
            // "للضوابط الأساسية"
            'ضوابط الأساسية'                  => 'nca ecc essential cybersecurity controls',
            'اي سي سي'                        => 'nca ecc',
            'المجالات الرئيسية'               => 'ecc main domains',
            'ضوابط الأنظمة الحساسة'           => 'cscc critical systems controls',
            // Observed ض/ظ typo. Folding the pair is not safe (ظاهر/ضاهر are
            // different words), so the misspelling gets its own key.
            'ظوابط الأنظمة الحساسة'           => 'cscc critical systems controls',
            'أنظمة الحرجة'                    => 'cscc critical systems controls',
            'بنية التحتية الحساسة'            => 'cscc critical national infrastructure',
            'سي اس سي سي'                     => 'cscc',
            // stem of 'ضوابط الحوسبة السحابية', which it subsumes: the definite
            // article assimilates in "للحوسبة السحابية"
            'حوسبة السحابية'                  => 'nca ccc cloud cybersecurity controls',
            'ضوابط السحابة'                   => 'nca ccc cloud cybersecurity controls',
            'خدمات السحابية'                  => 'nca ccc cloud cybersecurity controls',
            'سي سي سي'                        => 'nca ccc',
            'ضوابط البيانات'                  => 'nca dcc data',
            'تصنيف البيانات'                  => 'nca dcc data classification saudi',
            'نصنف البيانات'                   => 'nca dcc data classification saudi',
            'مستويات سرية البيانات'           => 'nca dcc data protection tiers',
            'دي سي سي'                        => 'nca dcc',
            'ضوابط العمل عن بعد'              => 'nca tcc telework',
            'عمل من البيت'                    => 'nca tcc telework controls',
            'دوام من البيت'                   => 'nca tcc telework controls',
            'نفاذ عن بعد'                     => 'nca tcc remote access security',
            'تي سي سي'                        => 'nca tcc',
            'ضوابط حسابات التواصل'            => 'nca osmacc social media',
            'سوشال ميديا'                     => 'nca osmacc social media accounts',
            'حسابات المنصات الرسمية'          => 'nca osmacc official social media',
            'إطار القوى العاملة'              => 'nca scywf workforce',
            'وظائف الأمن السيبراني'           => 'scywf cybersecurity workforce framework',
            'مسارات الوظيفية'                 => 'scywf saudi cybersecurity workforce',
            // Observed ئ/ي spelling of الوظائف. Folding that pair globally is
            // not safe either, so the observed word gets a key of its own.
            'وظايف والمسارات'                 => 'scywf cybersecurity workforce framework',
            // SAMA
            'البنك المركزي السعودي'           => 'who is sama saudi central bank',
            'مؤسسة النقد'                     => 'who is sama',
            'ساما'                            => 'who is sama',
            'إطار الأمن السيبراني ساما'       => 'sama csf cyber security framework',
            'إطار الأمن السيبراني لمؤسسة النقد' => 'sama csf sama cyber security framework',
            'إطار الأمن السيبراني للبنك المركزي' => 'sama csf sama cyber security framework',
            // Bare ساما resolves to sama-overview. Naming banks alongside it is
            // what pins the question on CSF rather than on the regulator.
            'الأمن السيبراني للبنوك'          => 'sama csf sama cyber security framework',
            'السايبر للبنوك'                  => 'sama csf sama cyber security framework',
            'إطار حوكمة تقنية المعلومات'      => 'sama itgf it governance',
            'حوكمة الآي تي'                   => 'sama itgf it governance framework',
            'اي تي جي اف'                     => 'sama itgf',
            'استمرارية الأعمال'               => 'sama bcm business continuity',
            'استمرارية التشغيل'               => 'sama bcm business continuity',
            'تعافي للبنوك'                    => 'sama bcm business continuity framework',
            // CST / Aramco / PDPL / SDAIA / ISO
            'هيئة الاتصالات'                  => 'cst communications',
            'قطاع الاتصالات'                  => 'cst telecom regulator',
            // stem, not 'الإطار التنظيمي': the definite article assimilates
            // in "للإطار التنظيمي"
            'إطار التنظيمي'                   => 'cst crf',
            // Paraphrase of the CRF title. It carries the full keyword set
            // because 'قطاع الاتصالات' would otherwise put the regulator entry
            // ahead of the framework the question is about.
            'إطار تنظيم الأمن السيبراني'      => 'cst crf cybersecurity regulatory framework',
            'سي ار اف'                        => 'cst crf',
            'أرامكو'                          => 'aramco sacs',
            'نظام حماية البيانات'             => 'pdpl personal data',
            'حماية البيانات الشخصية'          => 'pdpl personal data',
            // PDPL is a نظام, but speakers reach for قانون ("law") just as often.
            'قانون حماية البيانات'            => 'pdpl personal data protection law',
            // Bare stem, so a question that names neither نظام/قانون nor
            // الشخصية still lands on PDPL. It subsumes the three keys above;
            // they stay because their values are not identical.
            'حماية البيانات'                  => 'pdpl personal data',
            // Observed misspelling of البيانات; deletions are outside what
            // folding can repair.
            'حماية البيانت'                   => 'pdpl personal data',
            'سدايا'                           => 'sdaia',
            'هيئة البيانات والذكاء الاصطناعي' => 'sdaia',
            'أيزو 27001'                      => 'iso 27001',
            'أيزو'                            => 'iso 27001',
            // practitioner advice
            'من أين أبدأ'                     => 'where do i start',
            'كيف أبدأ'                        => 'where do i start',
            // stem, so نضجنا / بالنضج / للنضج all land
            'نضج'                             => 'maturity',
            // stem, not 'التدقيق': the definite article assimilates in "للتدقيق"
            'تدقيق'                           => 'audit',
            'مدقق'                            => 'audit inspection',
            'الفحص'                           => 'audit inspection',
            'مراجعة رقابية'                   => 'audit inspection',
            'مراجعة الرقابية'                 => 'audit inspection',
            'الطرف الثالث'                    => 'third party',
            'الموردين'                        => 'third party vendor',
            'موردون'                          => 'third party vendor',
            'مقاولين'                         => 'third party vendor',
            'مزودين'                          => 'third party vendor',
            'طرف الخارجي'                     => 'third party vendor',
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
            'اي سي سي وسي سي سي'              => 'ecc vs ccc ecc and ccc',
            'مؤسسة النقد أم ضوابط'            => 'sama vs nca sama or nca',
            'مؤسسة النقد أم هيئة'             => 'sama vs nca sama or nca',
            'حماية البيانات الشخصية وضوابط البيانات' => 'pdpl vs dcc pdpl and dcc',
            'الفرق بين متطلبات أرامكو'        => 'aramco vs nca aramco and nca',
        ];

        // The keys go through the same pipeline as the question, once, so both
        // sides of every comparison are in the same orthography.
        static $normalized = null;
        if ($normalized === null) {
            $normalized = [];
            foreach ($map as $ar => $en) {
                if ($en !== '') {
                    $normalized[self::normalize($ar)] = $en;
                }
            }
        }

        $aliases = '';
        foreach ($normalized as $ar => $en) {
            if (mb_strpos($subject, $ar) !== false) {
                $aliases .= ' ' . $en;
            }
        }

        return $aliases;
    }

    /**
     * Orthographic normalization for matching. Arabic is written with far more
     * variation than a substring search can absorb: optional diacritics, four
     * spellings of alef, a final taa marbuta written as a plain heh, three
     * digit families, and half a dozen dash characters. Every one of them
     * breaks a literal substring match against a key written one way.
     *
     * The folds are deliberately narrow. ؤ→و and ئ→ي are NOT applied: they
     * merge distinct spellings without repairing the deletion typos that
     * motivate them, and observed cases are better served by their own alias.
     * The definite article is never stripped either — ال is two of the most
     * common letters in the language, and removing it turns short stems into
     * broad false positives. Clitics are handled by curated stems instead.
     */
    private static function normalize(string $s): string
    {
        // Presentation forms (ﺍﻟﻬﻴﺌﺔ), full-width Latin, and compatibility
        // digits collapse onto their canonical codepoints. ext-intl is a
        // suggestion rather than a requirement, so this step is best-effort;
        // everything below stands on its own without it.
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KC) ?: $s;
        }

        $s = mb_strtolower($s, 'UTF-8');

        // Zero-width space/non-joiner/joiner, word joiner, BOM, and tatweel.
        // All invisible, all mid-word, all fatal to a substring match.
        $s = str_replace(
            ["\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{0640}"],
            '',
            $s
        );

        // Combining marks: honorifics, harakat, superscript alef, and the
        // Quranic annotation block.
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $s);

        // Arabic-Indic and Extended Arabic-Indic digits, then the letter folds.
        $s = strtr($s, self::FOLDS);

        // Hyphen, non-breaking hyphen, figure/en/em dash, horizontal bar, minus.
        $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $s);

        // Punctuation and every flavour of whitespace become one ASCII space,
        // so ‏"ضوابط، السحابة"‏ still contains the key "ضوابط السحابة". The
        // ASCII hyphen survives: it is load-bearing inside SACS-210.
        $s = trim(preg_replace('/[^\p{L}\p{N}-]+/u', ' ', $s));

        // Narrow identifier canonicalization. Both spellings of each identifier
        // are pinned to the form the corpus keywords use, and nothing else is
        // touched: collapsing spaces inside arbitrary text would join words.
        $s = preg_replace('/\biso[\s-]*27001\b/u', 'iso 27001', $s);
        $s = preg_replace('/\bsacs[\s-]*(210|002)\b/u', 'sacs-$1', $s);

        return $s;
    }

    /**
     * Digit and letter folds applied in one pass. The letter pairs are the ones
     * writers use interchangeably: the four alefs, an alef maqsura written as a
     * yaa (مستوي for مستوى), and a final taa marbuta written as a plain heh
     * (حمايه for حماية).
     */
    private const FOLDS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي',
        'ة' => 'ه',
    ];
}
