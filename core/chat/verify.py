"""Measurable groundedness checks that do not trust the model's own opinion.

These checks are deliberately simple and explainable: lexical support per sentence, and exact matching of every
number. They catch invented figures and off-source sentences. They cannot judge subtle paraphrase, and they are
reported as measurements, not as proof that an answer is true.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

from core.text.normalize import numbers_in, sentences, tokenize

_CITATION = re.compile(r"\[\s*\d+\s*\]")
_SPACE_BEFORE_MARK = re.compile(r"[ \t]+([.,،؛:!?؟])")


@dataclass
class Verification:
    grounded: bool
    support: float
    unsupported_numbers: list[str] = field(default_factory=list)
    unsupported_sentences: list[str] = field(default_factory=list)


def strip_citations(text: str) -> str:
    return _SPACE_BEFORE_MARK.sub(r"\1", _CITATION.sub("", text)).strip()


def verify_answer(answer: str, sources: list[str], *, min_sentence_support: float = 0.5) -> Verification:
    plain = strip_citations(answer)
    source_text = "\n".join(sources)
    source_tokens = set(tokenize(source_text, keep_stopwords=True))
    source_numbers = numbers_in(source_text)
    missing_numbers = sorted(numbers_in(plain) - source_numbers)

    ratios: list[float] = []
    bad: list[str] = []
    for sentence in sentences(plain):
        tokens = tokenize(sentence)
        if len(tokens) < 3:
            continue
        ratio = sum(1 for token in tokens if token in source_tokens) / len(tokens)
        ratios.append(ratio)
        if ratio < min_sentence_support:
            bad.append(sentence)
    support = sum(ratios) / len(ratios) if ratios else (1.0 if plain.strip() and not missing_numbers else 0.0)
    return Verification(grounded=not missing_numbers and not bad, support=round(support, 3), unsupported_numbers=missing_numbers, unsupported_sentences=bad)
