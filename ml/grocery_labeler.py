"""Local labeling UI for grocery qrels. Does not rank products or fill grades."""
from __future__ import annotations

import argparse
from http.server import SimpleHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

GROCERY = Path(__file__).resolve().parent / "data" / "grocery"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()
    if not (GROCERY / "labeler.html").exists():
        raise SystemExit(f"Missing {GROCERY / 'labeler.html'}")

    class Handler(SimpleHTTPRequestHandler):
        def __init__(self, *a, **kw):
            super().__init__(*a, directory=str(GROCERY), **kw)

    httpd = ThreadingHTTPServer(("127.0.0.1", args.port), Handler)
    print(f"Labeler: http://127.0.0.1:{args.port}/labeler.html")
    print("Grades stay empty until you set 2 / 1 / 0. Download qrels.csv when done.")
    httpd.serve_forever()


if __name__ == "__main__":
    main()
