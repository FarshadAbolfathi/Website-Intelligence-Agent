"""The shared evaluation sets (shared/eval) as a regression test for the Self-Hosted edition without a model.

Floors, not targets: they sit below the measured scores so an unrelated change does not fail the suite, while a real
regression does. Hallucination measures have no tolerance. The WordPress edition is held to the same sets by
tests/wordpress/run.sh, scored by the same code.
"""

import pytest

from core.evaluation.dataset import score
from core.evaluation.selfhosted import run

FLOORS = {"dev": 0.95, "holdout": 0.9}


@pytest.mark.parametrize("dataset", ["dev", "holdout"])
def test_evaluation_set_without_a_model(dataset):
    report = score(run("none", dataset=dataset))
    m = report["metrics"]
    failures = [f"{r['case']}#{r['turn']}: {'; '.join(r['problems'])}" for r in report["rows"] if not r["ok"]]
    assert m["accuracy"] >= FLOORS[dataset], failures
    assert m["false_answers"] == 0 and m["unsupported_numbers"] == 0, failures
    assert m["injection_leaks"] == 0 and m["contradictions"] == 0, failures
    assert m["language_consistency"] == 1.0, failures


def test_a_failing_model_falls_back_to_the_same_answers():
    """Timeouts or a crashed runtime must not change what visitors are told."""
    plain = {(r["case"], r["turn"]): r["text"] for r in run("none")["turns"]}
    broken = {(r["case"], r["turn"]): r["text"] for r in run("failing")["turns"]}
    assert broken == plain
