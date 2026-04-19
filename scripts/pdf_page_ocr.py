#!/usr/bin/env python3
import json
import os
import sys

from pdf2image import convert_from_path

from pdf_extract import build_index_pages, extract_ocr_page


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


def extract_selected_ocr_pages(pdf_path: str, page_numbers: list[int], lang: str) -> list[dict[str, object]]:
    pages: list[dict[str, object]] = []

    for page_number in page_numbers:
        images = convert_from_path(pdf_path, dpi=300, first_page=page_number, last_page=page_number)
        if not images:
            continue

        page_text = extract_ocr_page(images[0], lang, page_number)
        pages.append({
            "page_number": page_number,
            "text": page_text,
        })

    return pages


def main() -> int:
    if len(sys.argv) < 3:
        print(json.dumps({"error": "Usage: pdf_page_ocr.py <pdf_path> <page_numbers_csv> [lang]"}))
        return 1

    pdf_path = sys.argv[1]
    page_numbers = parse_page_numbers(sys.argv[2])
    lang = sys.argv[3] if len(sys.argv) > 3 else "spa+eng"

    if not os.path.exists(pdf_path):
        print(json.dumps({"error": f"File not found: {pdf_path}"}))
        return 1

    if not page_numbers:
        print(json.dumps({"ocr_pages": [], "index_pages": [], "page_numbers": [], "index_text": ""}, ensure_ascii=False))
        return 0

    try:
        ocr_pages = extract_selected_ocr_pages(pdf_path, page_numbers=page_numbers, lang=lang)
        index_pages = build_index_pages(ocr_pages)
        index_text = "\n\n".join(page["text"] for page in index_pages if page.get("text"))
        print(json.dumps({
            "ocr_pages": ocr_pages,
            "index_pages": index_pages,
            "page_numbers": page_numbers,
            "index_text": index_text,
        }, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())