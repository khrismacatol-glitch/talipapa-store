from semantic.cebu_ca_features import combine_scores, feature_row, parse_query


def test_powder_query_positive_form():
    slots = parse_query("gatas nga powder")
    assert "powder" in slots.positive
    assert "powder" not in slots.negated


def test_presko_is_fresh():
    slots = parse_query("gatas nga presko")
    assert "fresh" in slots.positive


def test_low_fat_but_not_powder():
    slots = parse_query("gatas nga low fat pero dili powder")
    assert "low_fat" in slots.positive
    assert "powder" in slots.negated
    assert "powder" not in slots.positive


def test_organic_no_sugar_is_positive_not_negation_of_organic():
    slots = parse_query("gatas nga organic ug walay sugar")
    assert "organic" in slots.positive
    assert "no_sugar" in slots.positive
    assert "organic" not in slots.negated


def test_coffee_not_sweet():
    slots = parse_query("gatas para sa kape pero dili tam-is")
    assert "coffee" in slots.positive
    assert "sweetened" in slots.negated


def test_soya_not_chocolate():
    slots = parse_query("gatas nga soya pero dili chocolate")
    assert "soya" in slots.positive
    assert "chocolate" in slots.negated


def test_dili_makataba_is_low_fat_not_negated_fat():
    slots = parse_query("gatas nga dili makataba")
    assert "low_fat" in slots.positive
    assert "low_fat" not in slots.negated


def test_negation_violation_on_powder_sku():
    powder = {
        "name": "Bear Brand Fortified Powdered Milk 320g",
        "description": "Powdered milk drink",
        "specs": {"form": "powder", "brand": "bear brand", "is_milk": True},
    }
    liquid = {
        "name": "Magnolia Low Fat Milk 1L",
        "description": "Fresh cow milk with reduced fat",
        "specs": {"form": "liquid", "fat": "low", "source": "cow", "brand": "magnolia", "is_milk": True, "volume_ml": 1000},
    }
    q = "gatas nga low fat pero dili powder"
    assert feature_row(q, powder)["neg_violate"] == 1.0
    assert feature_row(q, liquid)["neg_violate"] == 0.0
    assert feature_row(q, liquid)["attr_match"] > feature_row(q, powder)["attr_match"]


def test_combine_penalizes_violation():
    rows = [{"attr_match": 0.0, "neg_violate": 1.0, "brand_match": 0.0, "form_match": 0.0, "qty_match": 0.0},
            {"attr_match": 1.0, "neg_violate": 0.0, "brand_match": 0.0, "form_match": 1.0, "qty_match": 0.0}]
    scores = combine_scores([1.0, 0.5], rows, {"neg_violate": 2.0, "attr_match": 1.0, "form_match": 0.5})
    assert scores[1] > scores[0]
