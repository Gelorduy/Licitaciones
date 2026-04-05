#!/usr/bin/env python3
import json
import os
import re
import sys
from pypdf import PdfReader
from pdf2image import convert_from_path
import pytesseract


def normalize_text(text: str) -> str:
    text = text or ""
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def extract_native(pdf_path: str) -> str:
    reader = PdfReader(pdf_path)
    parts = []
    for page in reader.pages:
        try:
            parts.append(page.extract_text() or "")
        except Exception:
            parts.append("")
    return normalize_text("\n".join(parts))


def extract_ocr(pdf_path: str, lang: str) -> str:
    images = convert_from_path(pdf_path, dpi=250)
    text_parts = []
    for image in images:
        text_parts.append(pytesseract.image_to_string(image, lang=lang))
    return normalize_text("\n".join(text_parts))


def main() -> int:
    if len(sys.argv) < 2:
        print(json.dumps({"error": "Usage: pdf_extract.py <pdf_path> [lang]"}))
        return 1

    pdf_path = sys.argv[1]
    lang = sys.argv[2] if len(sys.argv) > 2 else "spa+eng"

    if not os.path.exists(pdf_path):
        print(json.dumps({"error": f"File not found: {pdf_path}"}))
        return 1

    try:
        text = ""
        method = "native"

        try:
            text = extract_native(pdf_path)
        except Exception:
            text = ""

        # If native extraction is empty/too short or failed, fallback to OCR.
        if len(text) < 120:
            text = extract_ocr(pdf_path, lang)
            method = "ocr"

        print(
            json.dumps(
                {
                    "method": method,
                    "chars": len(text),
                    "text": text,
                },
                ensure_ascii=False,
            )
        )
        return 0
    except Exception as exc:
        print(json.dumps({"error": str(exc)}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
