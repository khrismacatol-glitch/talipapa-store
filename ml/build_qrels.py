"""Build graded qrels (query, product, grade) for every milk query.

Run after `php artisan search:export-catalog`. The output file is reviewed
and committed; it is the ground truth for training and evaluation.
"""
import csv

from semantic.data import DATA, grade, load_products, load_queries


def main() -> None:
    products = load_products()
    queries = [q for q in load_queries() if q["intent"] != "other"]
    rows = []
    for q in queries:
        for p in products:
            g = grade(q["intent"], p)
            if g > 0:
                rows.append({"query": q["query"], "product_key": p["key"], "grade": g})

    with (DATA / "qrels.csv").open("w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=["query", "product_key", "grade"])
        writer.writeheader()
        writer.writerows(rows)

    print(f"{len(rows)} judgements for {len(queries)} queries over {len(products)} products")


if __name__ == "__main__":
    main()
