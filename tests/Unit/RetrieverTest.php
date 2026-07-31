<?php
declare(strict_types=1);

use Saqr\Corpus;
use Saqr\Retriever;

$cases = json_decode(file_get_contents(__DIR__ . '/../fixtures/retrieval-cases.json'), true);

dataset('retrieval_cases', array_map(
    fn($c) => [$c['question'], $c['expected_top1_id_in'], $c['expected_min_score']],
    $cases
));

test('top-1 ranked correctly for fixture question', function (string $q, array $expected, int $minScore) {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../fixtures/corpus-tiny.json');
    $r = new Retriever($corpus);
    $results = $r->retrieveTopK($q, 3);
    if ($expected === []) {
        expect($results)->toBeEmpty();
        return;
    }
    expect($results)->not->toBeEmpty();
    // Corpus preserves 'id' on all entries (may be null if not supplied).
    expect($expected)->toContain($results[0]['id']);
})->with('retrieval_cases');

test('Arabic critical-systems phrase retrieves nca-cscc (official acronym)', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $ids = array_column($r->retrieveTopK('ما هي ضوابط الأنظمة الحساسة؟', 3), 'id');
    expect($ids[0])->toBe('nca-cscc');
});

test('cloud cybersecurity controls question retrieves nca-ccc (official acronym)', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $ids = array_column($r->retrieveTopK('What are the NCA cloud cybersecurity controls?', 3), 'id');
    expect($ids[0])->toBe('nca-ccc');
});

test('ordinary Arabic ISO questions are not misrouted to the comparison entry', function () {
    // Regression pin: the iso-vs-nca alias was keyed on 'استخدام أيزو' ("using
    // ISO"), which fired on any question about using ISO 27001. The comparison
    // intent lives in "لتلبية متطلبات" (does ISO satisfy the other framework),
    // not in "استخدام".
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    // "how do I start using ISO 27001 in my company" is a where-to-start
    // question, so program-starting-point winning here is correct; what must
    // not happen is the comparison entry taking the slot.
    $ids = array_column($r->retrieveTopK('كيف أبدأ استخدام أيزو 27001 في شركتي؟', 3), 'id');
    expect($ids[0])->toBe('program-starting-point');

    $ids = array_column($r->retrieveTopK('ما هي فوائد استخدام أيزو 27001 لتحسين الأمن؟', 3), 'id');
    expect($ids[0])->toBe('iso-27001');

    // The substitution question still reaches the comparison entry.
    $ids = array_column($r->retrieveTopK('هل يمكنني استخدام أيزو 27001 لتلبية متطلبات هيئة الأمن السيبراني؟', 3), 'id');
    expect($ids[0])->toBe('iso-vs-nca');
});

test('generic ECC-compliance question is not misrouted to a comparison entry', function () {
    // Regression pin: ecc-vs-ccc/ecc-vs-cscc previously carried a generic
    // "comply with ecc" style keyword that fired on any ECC question,
    // displacing nca-ecc and program-starting-point as top-1.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    $ids = array_column($r->retrieveTopK('Do I need to comply with ECC?', 3), 'id');
    expect($ids[0])->toBe('nca-ecc');

    $ids = array_column($r->retrieveTopK("I need to comply with ECC, where do I start?", 3), 'id');
    expect($ids[0])->toBe('program-starting-point');
});

test('the normalization pipeline is a matching device, not a rewrite of the question', function () {
    // Everything the pipeline folds is applied to a copy. The generator must
    // receive the question exactly as the visitor typed it, diacritics and all.
    $seen = null;
    $recorder = new class ($seen) implements \Saqr\GeneratorInterface {
        public ?string $question = null;
        public function __construct(&$_) {}
        public function generate(string $question, array $contextEntries): ?string
        {
            $this->question = $question;
            return '<strong>ok</strong>';
        }
    };

    $pipeline = new \Saqr\Pipeline(
        Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json'),
        $recorder
    );
    $asked = 'قانون حِماية البيانات وش يلزمني فيه؟';
    $result = $pipeline->ask($asked);

    expect($result['top'][0]['id'])->toBe('pdpl');
    expect($recorder->question)->toBe($asked);
});

test('orthographic noise a keyword search cannot see through is folded away', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    // Diacritics, tatweel, and a zero-width joiner inside the same alias.
    expect($top('قانون حِمَايَة البيانات'))->toBe('pdpl');
    expect($top('وش نظام حمايـة البيانات الشخصيه'))->toBe('pdpl');
    expect($top("نظام حما\u{200D}ية البيانات"))->toBe('pdpl');

    // Arabic presentation forms, as pasted out of a PDF.
    expect($top('ﺿﻮﺍﺑﻂ ﺍﻟﺒﻴﺎﻧﺎﺕ'))->toBe('nca-dcc');

    // All four alefs fold together, so one key covers every spelling.
    expect($top('هل إيزو 27001 مطلوب عندنا'))->toBe('iso-27001');
    expect($top('هل أيزو 27001 مطلوب عندنا'))->toBe('iso-27001');
    expect($top('هل ايزو 27001 مطلوب عندنا'))->toBe('iso-27001');

    // Arabic-Indic and Extended Arabic-Indic digits.
    expect($top('هل ايزو ٢٧٠٠١ الزامي بالسعوديه'))->toBe('iso-27001');
    expect($top('هل ايزو ۲۷۰۰۱ الزامي بالسعوديه'))->toBe('iso-27001');
});

test('identifier spellings are canonicalized to the form the corpus keywords use', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    // SACS-210 is written with a hyphen in the corpus and with a space by half
    // the people who ask about it. Both reach the entry, in either language.
    expect($top('هل شهادة SACS-210 مطلوبة لموردي ارامكو؟'))->toBe('aramco-sacs-002');
    expect($top('وش SACS 210 المطلوب من مقاولين ارامكو'))->toBe('aramco-sacs-002');
    expect($top('Is SACS 210 required for Aramco suppliers?'))->toBe('aramco-sacs-002');
    expect($top('وش SACS 002 المطلوب من ارامكو'))->toBe('aramco-sacs-002');

    // ISO 27001 arrives spaced, hyphenated, closed up, and with an en dash.
    foreach (['ISO 27001', 'ISO-27001', 'ISO27001', 'ISO–27001'] as $spelling) {
        expect($top("وش {$spelling} وهل هو الزامي"))->toBe('iso-27001');
        expect($top("Is {$spelling} mandatory in Saudi Arabia?"))->toBe('iso-27001');
    }
});

test('Arabic acronym transliterations reach the entry the Latin acronym names', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    expect($top('وش نكا ودورها بالامن السيبراني'))->toBe('nca-overview');
    expect($top('وش تسوي ان سي اي'))->toBe('nca-overview');
    expect($top('وش اي سي سي'))->toBe('nca-ecc');
    expect($top('وش سي سي سي'))->toBe('nca-ccc');
    expect($top('وش حوكمه الاي تي في ساما'))->toBe('sama-itgf');

    // Naming two of them is a comparison, and the comparison entry must win
    // over the two entries it compares. This is why a transliteration emits
    // the acronym keywords and not the framework's full title.
    expect($top('وش الفرق بين اي سي سي وسي سي سي'))->toBe('ecc-vs-ccc');
});

test('Saudi dialect spellings reach the entry the map is written for', function () {
    // Both found on production, 2026-07-31. Neither is reachable by a fold:
    // سوشيال and سوشال differ by a long vowel and neither contains the other,
    // and وين is a different word from أين, not a spelling of it.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    expect($top('وش سالفة ضوابط السوشيال ميديا'))->toBe('nca-osmacc');
    expect($top('وش ضوابط حسابات السوشال ميديا للجهه'))->toBe('nca-osmacc');

    expect($top('ابي اطلع شهادة سايبر للشركة من وين ابدا'))->toBe('program-starting-point');
    expect($top('وين ابدا في الامن السيبراني'))->toBe('program-starting-point');
    expect($top('من أين أبدأ برنامج الأمن السيبراني في منشأتي؟'))->toBe('program-starting-point');
});

test('the definite article assimilated after lam still reaches the entry', function () {
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    // لـ + ال collapses to للـ and deletes the alef a key beginning with ال
    // needs. Curated stems carry these, never a global ال strip.
    expect($top('وش المطلوب للضوابط الاساسيه'))->toBe('nca-ecc');
    expect($top('كيف أجهز للإطار التنظيمي'))->toBe('cst-crf');
    expect($top('كيف استعد للتدقيق'))->toBe('audit');
    expect($top('كيف نستعد للمراجعه الرقابيه'))->toBe('audit');
});

test('the SAMA-or-NCA comparison key does not fire inside an ordinary word', function () {
    // أم ("or") folds to ام, which is the opening of أمس and أمانة. Keying the
    // comparison on the disjunction alone would route any question that put a
    // word starting with ام after مؤسسة النقد to the comparison entry, so the
    // key names the alternative it compares against instead.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);
    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    expect($top('هل تتبع البنوك السعودية إطار مؤسسة النقد أم ضوابط NCA؟'))->toBe('sama-vs-nca');
    expect($top('وش دور مؤسسة النقد امس واليوم'))->toBe('sama-overview');
    expect($top('مؤسسة النقد أمانة على الاقتصاد'))->toBe('sama-overview');
    expect($top('هل تتبع البنوك ساما ام النقد؟'))->toBe('sama-overview');
});

test('Arabic orthographic variants reach the same entry as the canonical spelling', function () {
    // Real user query, 2026-07-30: "وش قانون حمايه البيانات" returned nothing.
    // حمايه is spelled with a final heh where every alias key writes حماية with
    // a taa marbuta, so substring matching failed — a whole class of spellings,
    // not one alias. Folding runs on both sides of the comparison.
    $corpus = Corpus::loadFromFile(__DIR__ . '/../../corpus/frameworks.json');
    $r = new Retriever($corpus);

    $top = fn (string $q) => array_column($r->retrieveTopK($q, 3), 'id')[0] ?? '(no match)';

    expect($top('وش قانون حمايه البيانات'))->toBe('pdpl');
    expect($top('ما هو قانون حماية البيانات؟'))->toBe('pdpl');

    // The fold is not PDPL-specific: any key carrying a taa marbuta matches its
    // heh spelling, and an alef maqsura written as a yaa (or the reverse) folds
    // in the same pass.
    expect($top('ما هي الهيئه الوطنيه للأمن السيبراني؟'))->toBe('nca-overview');
    expect($top('ما هي الهيئه الوطنيه للأمن السيبرانى؟'))->toBe('nca-overview');
    expect($top('ما هو مستوي النضج المطلوب؟'))->toBe('maturity');

    // The bare حماية البيانات stem must not drag data-control questions off the
    // DCC entry, nor unseat the PDPL/DCC comparison.
    expect($top('ما هي ضوابط البيانات؟'))->toBe('nca-dcc');
    expect($top('ما العلاقة بين نظام حماية البيانات الشخصية وضوابط البيانات؟'))->toBe('pdpl-vs-dcc');
});
