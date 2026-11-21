import numpy as np

from semantic.data import load_grocery_qrels, load_qrels
from semantic.ranker import (
    BASE_FEATURES,
    GROCERY_FEATURES,
    grocery_feature_matrix,
    token_overlap_scores,
)
from train_grocery import MILK_ARTIFACTS


def test_production_base_feature_count_unchanged():
    assert len(BASE_FEATURES) == 32
    assert "cos_desc" not in BASE_FEATURES


def test_grocery_features_are_appended_not_interleaved():
    assert GROCERY_FEATURES[0] == "cos_desc"
    assert "bm25_name" in GROCERY_FEATURES


def test_grocery_qrels_skips_blank_grades():
    assert load_grocery_qrels() == {}


def test_milk_qrels_still_load():
    milk = load_qrels()
    assert len(milk) > 0


def test_token_overlap_and_grocery_matrix_shape():
    products = [
        {"name": "Argentina Corned Beef", "description": "Canned beef", "category": "Canned Goods & Soups"},
        {"name": "San Marino Corned Tuna", "description": "Tuna", "category": "Canned Goods & Soups"},
        {"name": "Ariel Detergent Powder", "description": "Laundry", "category": "Paper, Cleaning & Home"},
    ]
    overlap = token_overlap_scores("corned beef", products)
    assert overlap[0] > overlap[1]
    assert overlap[2] == 0
    q = np.array([1.0, 0.0], dtype=np.float64)
    desc = np.array([[1.0, 0.0], [0.0, 1.0], [0.0, 1.0]], dtype=np.float64)
    cat = np.ones_like(desc)
    cat = cat / np.linalg.norm(cat, axis=1, keepdims=True)
    q = q / np.linalg.norm(q)
    matrix = grocery_feature_matrix("corned beef", q, products, desc, cat)
    assert matrix.shape == (3, len(GROCERY_FEATURES))


def test_train_grocery_refuses_production_models_dir(tmp_path, monkeypatch):
    import train_grocery

    monkeypatch.setattr(train_grocery, "MODELS", tmp_path)
    monkeypatch.setattr("sys.argv", ["train_grocery.py", "--out-dir", str(tmp_path)])
    assert train_grocery.main() == 1


def test_milk_artifacts_list_does_not_include_only_new_ranker():
    assert "intent_classifier.joblib" in MILK_ARTIFACTS
    assert "product_milk_classifier.joblib" in MILK_ARTIFACTS
