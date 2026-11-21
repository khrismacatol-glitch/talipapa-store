"""Run from ml/: .venv/Scripts/python -m pytest tests"""
import pytest

from semantic.data import grade, is_milk, is_powder, load_products
from semantic.metrics import capped_recall_at_k, ndcg_at_k, precision_at_k, recall_at_k
from semantic.ranker import SemanticRanker, extra_query_tokens, leftover_query_tokens


def test_grocery_fillers_are_dropped_from_extra_terms():
    assert extra_query_tokens("tanan gatas") == ["gatas"]
    assert extra_query_tokens("all milk") == ["milk"]
    assert "nga" not in extra_query_tokens("sabon nga powder")
    assert leftover_query_tokens("sabon nga powder") == ["powder"]
    assert leftover_query_tokens("gatas para sa diabetic") == ["diabetic"]
    assert leftover_query_tokens("tanan gatas") == ["gatas"]


def test_metrics():
    grades = {"a": 2, "b": 2, "c": 1, "d": 2, "e": 2, "f": 2, "g": 2}
    ranked = ["a", "x", "b", "c", "d"]
    assert precision_at_k(ranked, grades) == pytest.approx(3 / 5)
    assert recall_at_k(ranked, grades) == pytest.approx(3 / 6)
    assert capped_recall_at_k(ranked, grades) == pytest.approx(3 / 5)
    assert ndcg_at_k(["a", "b", "d", "e", "f"], grades) == pytest.approx(1.0)


@pytest.fixture(scope="module")
def ranker():
    return SemanticRanker()


@pytest.fixture(scope="module")
def products():
    return load_products()


@pytest.mark.parametrize("query, intent", [
    ("Gatas sa baka", "cow"),
    ("Gatas nga dili makataba", "low_fat"),
    ("Gatas para sa mga diabetic", "diabetic"),
    ("Gatas na organic", "organic"),
    ("Gatas nga powder", "powder"),
])
def test_required_queries_rank_matching_milk_first(ranker, products, query, intent):
    result = ranker.rank(query, products, top_k=8)

    assert result.mode == "grocery_ltr"
    assert result.milk_query >= ranker.query_threshold
    assert max(result.intents, key=result.intents.get) == intent
    top = [products[r["index"]] for r in result.ranked]
    assert any(is_milk(p) and grade(intent, p) == 2 for p in top[:3])
    if intent == "powder":
        assert any(is_powder(p) for p in top[:3])


def test_diabetic_query_ranks_nutrition_milk_ahead_of_wafers(ranker, products):
    result = ranker.rank("gatas para sa diabetic", products, top_k=8)
    kept = [products[r["index"]]["name"] for r in result.ranked]
    assert result.mode == "grocery_ltr"
    assert "glucerna" in kept[0].lower() or "diabet" in kept[0].lower()


def test_non_milk_query_is_not_routed_to_milk(ranker):
    _, probs = ranker.intent_probs("barato na tuyo")
    assert probs[-1] > 1 - ranker.query_threshold


def test_non_milk_query_ranks_with_embeddings(ranker, products):
    result = ranker.rank("organic brown rice", products, top_k=5)
    assert result.mode in {"semantic", "grocery_ltr"}
    assert result.milk_query < ranker.query_threshold
    assert result.ranked
    top = products[result.ranked[0]["index"]]["name"]
    assert "rice" in top.lower()


def test_grocery_ltr_mode_for_required_query(ranker, products):
    result = ranker.rank("Gatas nga dili makataba", products, top_k=5)
    assert result.mode == "grocery_ltr"


def test_dessert_milk_query_keeps_condensed_creamer(ranker, products):
    result = ranker.rank("gatas para sa dessert", products, top_k=12)
    names = [products[r["index"]]["name"].lower() for r in result.ranked]
    assert result.mode == "grocery_ltr"
    assert any("condensed" in name or "creamer" in name for name in names)
    assert "fresh tomatoes 1kg" not in names


def test_fresh_milk_query_keeps_milk_drops_produce(ranker, products):
    result = ranker.rank("gatas nga fresh", products, top_k=8)
    names = [products[r["index"]]["name"] for r in result.ranked]
    lowered = [n.lower() for n in names]
    assert all(is_milk(products[r["index"]]) or "milk" in products[r["index"]]["name"].lower()
               for r in result.ranked)
    assert "Fresh Tomatoes 1kg" not in names
    assert any("fresh milk" in n for n in lowered[:3])


def test_fabric_conditioner_outranks_unrelated_grocery(ranker, products):
    extra = [
        {
            "key": "downy-fabcon",
            "id": 99981,
            "name": "Downy Fabcon Fabric Conditioner Passion 20ml",
            "description": "fabric conditioner",
            "category": "Laundry",
            "specs": None,
        },
        {
            "key": "mega-sardines",
            "id": 99982,
            "name": "Mega Sardines in Tomato Sauce 155g",
            "description": None,
            "category": "Canned",
            "specs": None,
        },
    ]
    catalog = products + extra
    result = ranker.rank("fabric conditioner", catalog, top_k=8)
    names = [catalog[r["index"]]["name"] for r in result.ranked]
    assert result.mode in {"semantic", "grocery_ltr"}
    assert "Downy Fabcon Fabric Conditioner Passion 20ml" in names[:2]
    assert "Mega Sardines in Tomato Sauce 155g" not in names[:5]


def test_soap_powder_query_uses_grocery_ltr(ranker, products):
    soap = {
        "key": "sariel-powder-detergent",
        "id": 99992,
        "name": "Sariel Powder Detergent 1kg",
        "description": "Sabon nga powder for laundry.",
        "category": "Laundry",
        "specs": None,
    }
    catalog = products + [soap]
    result = ranker.rank("sabon nga powder", catalog, top_k=5)
    names = [catalog[r["index"]]["name"] for r in result.ranked]
    assert result.mode in {"semantic", "grocery_ltr"}
    assert "Sariel Powder Detergent 1kg" in names[:3]


def test_diabetic_text_match_outranks_generic_milk(ranker, products):
    extra = {
        "key": "diabetasol-vanilla-milk-600g",
        "id": 99991,
        "name": "Diabetasol Vanilla Milk | 600g",
        "description": "a specialized milk-based nutritional powder formulated for people with diabetes or prediabetes.",
        "category": "Dairy, Eggs & Cheese",
        "specs": None,
    }
    catalog = products + [extra]
    result = ranker.rank("gatas para sa diabetic", catalog, top_k=8)
    names = [catalog[r["index"]]["name"] for r in result.ranked]
    assert "Fresh Tomatoes 1kg" not in names
    assert "Diabetasol Vanilla Milk | 600g" in names[:5]
