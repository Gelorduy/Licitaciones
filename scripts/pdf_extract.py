#!/usr/bin/env python3
import json
import os
import re
import sys
import base64
from io import BytesIO
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


def extract_last_pages_as_base64(
    pdf_path: str,
    max_pages: int = 3,
    max_width: int = 1400,
    quality: int = 70,
) -> list[str]:
    if max_pages <= 0:
        return []

    reader = PdfReader(pdf_path)
    total_pages = len(reader.pages)
    if total_pages <= 0:
        return []

    first_page = max(1, total_pages - max_pages + 1)
    # Use a moderate DPI to keep payload size under control for local LLM calls.
    images = convert_from_path(pdf_path, dpi=180, first_page=first_page, last_page=total_pages)

    base64_images: list[str] = []
    for image in images:
        # Keep images lightweight while preserving stamp/readability regions.
        if image.width > max_width:
            ratio = max_width / float(image.width)
            new_height = int(image.height * ratio)
            image = image.resize((max_width, new_height))

        if image.mode != "RGB":
            image = image.convert("RGB")

        buff = BytesIO()
        image.save(buff, format="JPEG", quality=quality, optimize=True)
        base64_images.append(base64.b64encode(buff.getvalue()).decode("utf-8"))

    return base64_images


def main() -> int:
    if len(sys.argv) < 2:
        print(
            json.dumps(
                {
                    "error": "Usage: pdf_extract.py <pdf_path> [lang] [vision_pages] [vision_max_width] [vision_quality]"
                }
            )
        )
        return 1

    pdf_path = sys.argv[1]
    lang = sys.argv[2] if len(sys.argv) > 2 else "spa+eng"
    vision_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 3
    vision_max_width = int(sys.argv[4]) if len(sys.argv) > 4 else 1400
    vision_quality = int(sys.argv[5]) if len(sys.argv) > 5 else 70

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

        vision_pages_payload = extract_last_pages_as_base64(
            pdf_path,
            max_pages=vision_pages,
            max_width=vision_max_width,
            quality=vision_quality,
        )

        print(
            json.dumps(
                {
                    "method": method,
                    "chars": len(text),
                    "text": text,
                    "vision_pages": vision_pages_payload,
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
