from semantic.feedback import build_feedback_qrels


def _session(token, query, product_id, name, events, position=1):
    return {
        "token": token,
        "query": query,
        "query_norm": query.lower(),
        "impressions": [{"product_id": product_id, "name": name, "position": position, "page": 1}],
        "events": events,
    }


def test_build_feedback_qrels_requires_evidence():
    export = {"sessions": [
        _session("a", "gatas para sa dessert", 1, "Carnation Condensada", [{"type": "click", "product_id": 1, "position": 3}])
    ]}
    assert build_feedback_qrels(export) == []


def test_build_feedback_qrels_promotes_repeated_carts():
    sessions = []
    for i in range(5):
        sessions.append(_session(
            f"s{i}",
            "Gatas para sa dessert",
            9,
            "Carnation Condensada 388 g",
            [{"type": "cart", "product_id": 9, "position": 2}],
            position=2,
        ))
    rows = build_feedback_qrels({"sessions": sessions})
    assert len(rows) == 1
    assert rows[0]["grade"] == 2
    assert rows[0]["query"] == "gatas para sa dessert"
    assert rows[0]["product_name"] == "Carnation Condensada 388 g"


def test_build_feedback_qrels_drops_bot_sessions():
    events = [{"type": "click", "product_id": 1, "position": 1}] * 50
    export = {"sessions": [_session("bot", "gatas", 1, "Milk", events)]}
    assert build_feedback_qrels(export) == []


def test_negative_grade_after_many_ignored_impressions():
    sessions = []
    for i in range(20):
        sessions.append(_session(f"n{i}", "gatas nga fresh", 2, "Fresh Tomatoes 1kg", [], position=1))
    rows = build_feedback_qrels({"sessions": sessions})
    assert rows
    assert rows[0]["grade"] == 0
    assert "tomato" in rows[0]["product_name"].lower()
