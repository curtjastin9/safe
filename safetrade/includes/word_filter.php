<?php
declare(strict_types=1);

/**
 * Chat word filter — blocks English and Tagalog (Filipino/Bisaya) profanity,
 * including disguised spellings:  f*ck, f.u.c.k, f u c k, fuuuck, sh1t, @ss,
 * p u t a n g i n a, accented letters, zero-width characters, etc.
 *
 *   $bad = chat_find_bad_word($text);   // null = clean, string = the offending word
 *
 * To add words, append to the lists below (lowercase, plain letters).
 */

/** Words that are blocked only when they appear as a whole word. */
function wf_exact_words(): array
{
    return [
        // English
        'fuck','fucks','fucked','fucker','fuckers','fucking','fck','fuk','fvck','phuck','wtf','stfu',
        'shit','shits','shitty','shite','sht','bullshit','bitch','bitches','bitchy','biatch','btch',
        'asshole','assholes','arsehole','bastard','bastards','dick','dicks','dickhead','cock','cocks','cocksucker',
        'pussy','pussies','cunt','cunts','twat','whore','whores','slut','sluts','skank','douche','douchebag','wanker',
        'prick','jerkoff','blowjob','handjob','cumshot','jizz','dildo','porn','porno','motherfucker','motherfuckers',
        'mofo','nigga','niggas','nigger','niggers','faggot','faggots','fag','fags','dyke','tranny','retard','retarded',
        'spic','chink','kike','whoreson','sonofabitch','piss','pissed','crap','damn','goddamn','bollocks','tits',
        'titties','boobs','rape','rapist','horny','masturbate','vagina','penis','semen',
        // Tagalog / Filipino / Bisaya
        'puta','putang','putangina','potangina','tangina','tanginamo','tanginang','kingina','kinangina','pota','potek',
        'putek','pucha','puchang','putcha','putragis','punyeta','punyemas','gago','gaga','gagong','gagu','tanga','bobo',
        'boba','ulol','ulul','tarantado','tarantada','siraulo','engot','shunga','ungas','gunggong','hinayupak','leche',
        'lintik','buwisit','bwisit','bwiset','buwiset','kupal','pokpok','puki','pukinangina','pekpek','tite','titi',
        'burat','bayag','kantot','kantutan','jakol','jakolero','salsal','hindot','hindutan','iyot','tamod','walanghiya',
        'walangyo','pakyu','pakshet','yawa','giatay','buang','bilat','bayot','bwakanang','bwakananginamo','pisti',
        'pesteng','lintikan','hampaslupa','ogag','gagi','ampota','amputa','ampotek','putanginamo','pakingshet','pakingyu',
    ];
}

/** Whole words matched exactly as typed (no repeated-letter collapsing). */
function wf_raw_words(): array
{
    return ['ass', 'asses', 'arse', 'arses', 'asshat', 'jackass', 'dumbass', 'smartass', 'badass', 'fatass', 'kickass'];
}

/** Stems blocked even when attached to other letters (fuckingly, putangina-mo, ...). */
function wf_stems(): array
{
    return [
        'fuck','shit','bitch','cunt','nigg','faggot','motherf','asshole','bullshit','dickhead','whore','bastard',
        'putang','tangina','kingina','kinangina','putragis','punyeta','pakyu','pakshet','pakingshet','pekpek','kantot',
        'tarantado','hinayupak','pukinang','potangina','amputa','bwakanang','walanghiya','buwisit','bwisit','siraulo',
    ];
}

/** Stem matches that are legitimate words. */
function wf_allowed_words(): array
{
    return ['shiitake', 'shitake', 'shitzu', 'shihtzu', 'shitzus', 'bastardo', 'scunthorpe'];
}

/** Multi-word phrases, written without spaces, matched against adjacent words. */
function wf_phrases(): array
{
    return [
        'putangina','tangina','kingina','kinangina','potangina','pakyu','pakshet','pakingshet','pakingyu','putragis',
        'sonofabitch','sonofawhore','motherfucker','bwakananginamo','tanginamo','putanginamo','hayopka','animalka',
        'gagoka','tangaka','boboka','ulolka','tarantadoka','kupalka','sirauloka','pukingina','pukinangina','ampota',
        'fuckyou','fuckoff','shutthefuckup','suckmydick','jerkoff','blowjob','handjob','putangamo','gagongina',
        'yawaka','pakyo','puchang','pisti',
    ];
}

/** Lower-case, strip accents, strip invisible characters. */
function wf_clean(string $s): string
{
    $s = str_replace(
        ["\u{200B}", "\u{200C}", "\u{200D}", "\u{2060}", "\u{FEFF}", "\u{00AD}"],
        '',
        $s
    );
    if (function_exists('transliterator_transliterate')) {
        $t = @transliterator_transliterate('NFD; [:Nonspacing Mark:] Remove; NFC; Any-Latin; Latin-ASCII', $s);
        if (is_string($t)) $s = $t;
    } elseif (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if (is_string($t) && $t !== '') $s = $t;
    }
    return strtolower($s);
}

/** Map look-alike symbols/digits to letters. */
function wf_leet(string $s): string
{
    return strtr($s, [
        '@' => 'a', '4' => 'a', '^' => 'a',
        '8' => 'b',
        '(' => 'c',
        '3' => 'e',
        '6' => 'g', '9' => 'g',
        '!' => 'i', '1' => 'i', '|' => 'i',
        '0' => 'o',
        '$' => 's', '5' => 's',
        '7' => 't', '+' => 't',
        '*' => 'u', '#' => 'h',
    ]);
}

/** Letters only, with runs of the same letter collapsed ("fuuuck" -> "fuck"). */
function wf_norm(string $s): string
{
    $s = preg_replace('/[^a-z]/', '', $s) ?? '';
    return preg_replace('/(.)\1+/', '$1', $s) ?? '';
}

/**
 * Returns the blocked word that was found, or null when the text is clean.
 */
function chat_find_bad_word(string $text): ?string
{
    static $exact = null, $raw = null, $stems = null, $phrases = null, $allowed = null;
    if ($exact === null) {
        $exact   = array_flip(array_map('wf_norm', wf_exact_words()));
        $raw     = array_flip(wf_raw_words());
        $stems   = array_values(array_unique(array_map('wf_norm', wf_stems())));
        $phrases = array_flip(array_map('wf_norm', wf_phrases()));
        $allowed = array_flip(array_map('wf_norm', wf_allowed_words()));
    }

    $clean = wf_clean($text);
    if ($clean === '') return null;

    // Split into chunks on whitespace; each chunk keeps its inner punctuation
    // so "f.u.c.k" and "sh!t" survive as one unit.
    $chunks = preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    // Join runs of single-character chunks:  "f u c k" -> "fuck".
    $units = [];
    $run = '';
    foreach ($chunks as $c) {
        $bare = preg_replace('/[^a-z0-9@$!|+*#^()]/', '', $c) ?? '';
        if (strlen($bare) === 1 && strlen($c) <= 2) {
            $run .= $bare;
            continue;
        }
        if ($run !== '') { $units[] = $run; $run = ''; }
        $units[] = $c;
    }
    if ($run !== '') $units[] = $run;

    $words = [];   // normalised candidate words, in order
    foreach ($units as $u) {
        // Look-alike symbols/digits only count inside tokens that also contain a
        // real letter, so prices and phone numbers ("455", "$500") are never read as words.
        $leet = static fn(string $t): string => preg_match('/[a-z]/', $t) ? wf_leet($t) : $t;
        $variants = [wf_norm($leet($u))];
        // "hell.shit" / "you,fuck" -> also test each punctuation-separated piece
        foreach (preg_split('/[^a-z0-9@$!|+*#^()]+/', $u, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $variants[] = wf_norm($leet($piece));
        }
        $variants[] = wf_norm($u);
        $rawv = preg_replace('/[^a-z]/', '', $leet($u)) ?? '';
        if ($rawv !== '' && (isset($raw[$rawv]) || preg_match('/^as{2,}$/', $rawv))) return 'ass';
        $variants = array_values(array_filter(array_unique($variants), static fn($v) => $v !== ''));
        $words[] = $variants;
    }

    foreach ($words as $variants) {
        foreach ($variants as $v) {
            if (isset($allowed[$v])) continue;
            if (isset($exact[$v])) return $v;
            foreach ($stems as $st) {
                if (strlen($st) >= 4 && str_contains($v, $st)) return $st;
            }
        }
    }

    // Adjacent words joined: "tang ina", "pak yu", "son of a bitch", "putang ina mo"
    $n = count($words);
    for ($i = 0; $i < $n; $i++) {
        $a = $words[$i][0] ?? '';
        if ($a === '') continue;
        $pair = $a . ($words[$i + 1][0] ?? '');
        $trio = $pair . ($words[$i + 2][0] ?? '');
        $quad = $trio . ($words[$i + 3][0] ?? '');
        foreach ([$pair, $trio, $quad] as $j) {
            if ($j !== $a && isset($phrases[$j])) return $j;
        }
    }

    return null;
}

/** Friendly message shown to the sender. */
function chat_bad_word_message(): string
{
    return "Please keep it respectful — your message contains language that isn't allowed on SafeTrade.";
}
