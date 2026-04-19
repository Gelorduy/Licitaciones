#!/usr/bin/env python3
import json
import os
import sys

from pdf_extract import extract_pages_as_base64


def parse_page_numbers(raw_value: str) -> list[int]:
    page_numbers: list[int] = []
    for raw_token in (raw_value or "").split(","):
        token = raw_token.strip()
        if token == "":
            continue

        try:
            page_number = int(token)
        except ValueError:
            continue

        if page_number <= 0 or page_number in page_numbers:
            continue

        page_numbers.append(page_number)

    return page_numbers


def main() -> int:
    if len(sys.argv) < 3:
        print(json.dumps({"error": "Usage: pdf_page_images.py <pdf_path> <page_numbers_csv> [max_width] [quality]"}))
        return 1

    pdf_path = sys.argv[1]
    page_numbers = parse_page_numbers(sys.argv[2])
    max_width = int(sys.argv[3]) if len(sys.argv) > 3 else 1400
    quality = int(sys.argv[4]) if len(sys.argv) > 4 else 70

    if not os.path.exists(pdf_path):
        print(json.dumps({"error": f"File not found: {pdf_path}"}))
        return 1

    if not page_numbers:
        print(json.dumps({"page_images": [], "page_numbers": []}, ensure_ascii=False))
        return 0

    try:
        images = extract_pages_as_base64(
            pdf_path,
            page_numbers=page_numbers,
            max_width=max_width,
            quality=quality,
        )
        print(json.dumps({"page_images": images, "page_numbers": page_numbers}, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())