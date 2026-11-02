"""Language-aware text handling for Persian, Arabic and English.

The rules here are mirrored in the WordPress plugin (`WIA_Text`). Both editions are tested against
`shared/normalization-vectors.json`, so a query normalised in one edition matches content normalised
in the other.
"""

from __future__ import annotations

import json
import re
import unicodedata
from pathlib import Path

_CHARS = {
    "ي": "ی", "ى": "ی", "ئ": "ی", "ك": "ک", "ۀ": "ه", "ة": "ه",
    "أ": "ا", "إ": "ا", "آ": "ا", "ٱ": "ا", "ؤ": "و",
    "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4", "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
    "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4", "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
    "‌": " ", "‍": "", "‎": "", "‏": "", "‪": "", "‫": "",
    "‬": "", " ": " ", "ـ": "",
}
_TABLE = str.maketrans(_CHARS)
_HARAKAT = re.compile("[ً-ْٰٕٔ]")
_THOUSANDS = re.compile(r"(?<=\d)[,٬،](?=\d{3}(?:\D|$))")
_NON_WORD = re.compile(r"[^\w\s.\-+]|_", re.UNICODE)
_EDGE_DOT = re.compile(r"(?<!\d)[.\-+]|[.\-+](?!\d)")
_SPACES = re.compile(r"\s+")


def normalize(text: str) -> str:
    """Fold spelling, digit and spacing variants into one comparable form."""
    if not text:
        return ""
    s = unicodedata.normalize("NFC", str(text))
    s = _HARAKAT.sub("", s)
    s = s.translate(_TABLE)
    s = _THOUSANDS.sub("", s)
    s = s.lower()
    s = _NON_WORD.sub(" ", s)
    s = _EDGE_DOT.sub(" ", s)
    return _SPACES.sub(" ", s).strip()


LANGUAGE = json.loads((Path(__file__).resolve().parents[2] / "shared" / "language.json").read_text(encoding="utf-8"))
STOPWORDS = frozenset(LANGUAGE["stopwords"])
# Words that frame a question but do not name its subject. A page about a phone rarely contains the word "price",
# so their absence must not block an answer. They still score when they do match.
SOFT_WORDS = frozenset(LANGUAGE["soft_words"])

_FA_SUFFIXES = ("هایی", "هایش", "های", "ترین", "ها", "تر")
_AR_PREFIXES = (("وال", 4), ("بال", 4), ("فال", 4), ("کال", 4), ("لل", 4), ("ال", 3))
_AR_CLITIC = re.compile("^[لبوفک]")
_TOKEN = re.compile(r"[\w.+\-]+", re.UNICODE)
_LATIN_OR_NUM = re.compile(r"^[a-z0-9.+\-]+$")


def stem(word: str) -> str:
    """Light, predictable suffix folding. It never needs a dictionary, so it runs on shared hosting too."""
    if _LATIN_OR_NUM.match(word):
        if len(word) > 4 and word.endswith("ies"):
            return word[:-3] + "y"
        if len(word) > 3 and word.endswith("s") and not word.endswith("ss"):
            return word[:-1]
        return word
    for prefix, min_rest in _AR_PREFIXES:
        if word.startswith(prefix) and len(word) - len(prefix) >= min_rest:
            word = word[len(prefix):]
            break
    for suffix in _FA_SUFFIXES:
        if word.endswith(suffix) and len(word) - len(suffix) >= 3:
            return word[: -len(suffix)]
    return word


def tokenize(text: str, *, keep_stopwords: bool = False) -> list[str]:
    """Normalised, stemmed search tokens."""
    out: list[str] = []
    for raw in _TOKEN.findall(normalize(text)):
        if len(raw) < 2 and not raw.isdigit():
            continue
        if not keep_stopwords and raw in STOPWORDS:
            continue
        stemmed = stem(raw)
        if not keep_stopwords and stemmed in STOPWORDS:
            continue
        out.append(stemmed)
    return out


SOFT_STEMS = frozenset(stem(w) for w in SOFT_WORDS)


def arabic_clitic_variant(token: str) -> str:
    """Arabic attaches ل ب و ف ك to words («لمدة» = «ل» + «مدة»). Returns the bare form, or "" when not applicable."""
    if len(token) >= 5 and _AR_CLITIC.match(token) and not _LATIN_OR_NUM.match(token):
        return token[1:]
    return ""


# ---------------------------------------------------------------- language detection
_ARABIC_SCRIPT = re.compile(r"[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]")
_LATIN = re.compile(r"[A-Za-z]")
_FA_ONLY = re.compile("[پچژگ]")
_FA_YEH_KAF = re.compile("[یک]")
_AR_ONLY = re.compile("[ةىيكأإؤ]")
_FA_WORDS = frozenset(LANGUAGE["detect"]["fa_words"])
_AR_WORDS = frozenset(LANGUAGE["detect"]["ar_words"])


def detect_language(text: str, default: str = "en") -> str:
    """Detect 'fa', 'ar' or 'en'. Persian and Arabic share a script, so letters and function words decide."""
    if not text or not text.strip():
        return default
    arabic_script = len(_ARABIC_SCRIPT.findall(text))
    latin = len(_LATIN.findall(text))
    if arabic_script == 0 and latin == 0:
        return default
    if latin > arabic_script:
        return "en"

    words = re.findall(r"[؀-ۿ]+", text.replace("‌", " "))
    fa = ar = 0.0
    for w in words:
        if w in _FA_WORDS:
            fa += 2
        if w in _AR_WORDS:
            ar += 2
        if w.startswith("ال") and len(w) > 3:
            ar += 1
    fa += 1.5 * len(_FA_ONLY.findall(text)) + 0.25 * len(_FA_YEH_KAF.findall(text))
    ar += 1.0 * len(_AR_ONLY.findall(text))
    if fa == ar:
        return "fa" if default not in ("fa", "ar") else default
    return "fa" if fa > ar else "ar"


# «كيف» (Arabic "how") and «کیف» (Persian "bag") are the same string after normalisation, so these words cannot be
# stopwords. They stay searchable and are treated as question words only when the question itself is Arabic.
AR_FUNCTION_STEMS = frozenset(stem(w) for w in LANGUAGE["arabic_function_words"]["words"])


def soft_stems_for(text: str) -> frozenset[str]:
    return SOFT_STEMS | AR_FUNCTION_STEMS if detect_language(text, "fa") == "ar" else SOFT_STEMS


def subject_tokens(text: str) -> list[str]:
    """The words of a question that name its subject."""
    soft = soft_stems_for(text)
    return [w for w in tokenize(text) if w not in soft]


_SENTENCE = re.compile(r"(?<=[.!?؟…。])\s+|\n+")


def sentences(text: str) -> list[str]:
    return [s.strip() for s in _SENTENCE.split(text or "") if s and s.strip()]


_NUMBER = re.compile(r"\d+(?:\.\d+)?")


def numbers_in(text: str) -> set[str]:
    """Numeric facts in a text, after folding digits and thousands separators."""
    found = set()
    for raw in _NUMBER.findall(normalize(text)):
        # 999.00 and 999 state the same fact
        found.add(raw.rstrip("0").rstrip(".") if "." in raw else raw)
    return found
