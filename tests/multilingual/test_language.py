import pytest

from core.text.normalize import detect_language, normalize, numbers_in, match_group, subject_tokens, suffix_variants, tokenize


def test_normalize_vectors(vectors):
    for case in vectors["normalize"]:
        assert normalize(case["in"]) == case["out"], case["why"]


def test_language_vectors(vectors):
    wrong = [(c["in"], detect_language(c["in"])) for c in vectors["detect_language"] if detect_language(c["in"]) != c["out"]]
    assert not wrong


def test_token_equivalence_vectors(vectors):
    for case in vectors["tokens_equal"]:
        assert tokenize(case["a"]) == tokenize(case["b"]), case["why"]


def test_subject_token_vectors(vectors):
    for case in vectors["subject_tokens"]:
        assert subject_tokens(case["in"]) == case["out"], case["why"]


def test_synonym_vectors(vectors):
    for case in vectors["synonyms"]:
        assert tokenize(case["b"])[0] in match_group(tokenize(case["a"])[0]), case["why"]
    for case in vectors["not_synonyms"]:
        assert tokenize(case["b"])[0] not in match_group(tokenize(case["a"])[0]), case["why"]


def test_colloquial_endings_are_tried_for_unknown_words():
    assert "سفارش" in suffix_variants("سفارشم") and "سفارش" in suffix_variants("سفارشتون")
    assert suffix_variants("laptops") == [] and suffix_variants("کیف") == []
    assert "تولید" in suffix_variants("تولیدی", unknown=True) and "تولید" not in suffix_variants("تولیدی")


def test_soft_words_never_hide_a_synonym(vectors):
    from core.text.normalize import LANGUAGE  # noqa: PLC0415

    assert not set(LANGUAGE["soft_words"]) & {w for g in LANGUAGE["synonyms"]["groups"] for w in g}


@pytest.mark.parametrize("a,b", [("۲٬۵۰۰٬۰۰۰ تومان", "2500000"), ("٢٥٠٠", "2500"), ("1,250.50", "1250.5"), ("999.00", "999")])
def test_numbers_fold_across_scripts(a, b):
    assert b in numbers_in(a)


def test_mixed_latin_brand_inside_persian_sentence_stays_persian():
    assert detect_language("قیمت گوشی Samsung Galaxy چقدر است؟") == "fa"


def test_unknown_word_variants_vectors(vectors):
    for case in vectors["unknown_variants"]:
        assert case["contains"] in suffix_variants(normalize(case["in"]), unknown=True), case


def test_soft_variant_vectors(vectors):
    from core.text.normalize import is_soft, soft_stems_for
    for case in vectors["soft_variants"]:
        token = tokenize(case["in"])[0]
        assert is_soft(token, soft_stems_for(case["in"])) is case["soft"], case
