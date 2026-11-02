import pytest

from core.text.normalize import detect_language, normalize, numbers_in, subject_tokens, tokenize


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


@pytest.mark.parametrize("a,b", [("۲٬۵۰۰٬۰۰۰ تومان", "2500000"), ("٢٥٠٠", "2500"), ("1,250.50", "1250.5"), ("999.00", "999")])
def test_numbers_fold_across_scripts(a, b):
    assert b in numbers_in(a)


def test_mixed_latin_brand_inside_persian_sentence_stays_persian():
    assert detect_language("قیمت گوشی Samsung Galaxy چقدر است؟") == "fa"
