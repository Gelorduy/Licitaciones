#!/usr/bin/env python3
import json
import os
import re
import sys
import base64
from io import BytesIO
from pypdf import PdfReader
from pdf2image import convert_from_path
from PIL import ImageFilter, ImageOps
import pytesseract


VISION_KEYWORDS = {
    "registro publico de comercio": 6,
    "folio mercantil": 6,
    "inscripcion": 4,
    "notaria": 4,
    "notario": 4,
    "escritura": 3,
    "libro": 3,
    "sello": 3,
    "instrumento": 2,
}

OCR_KEYWORDS = {
    "ESCRITURA": 4,
    "LIBRO": 4,
    "FECHA": 4,
    "ACTO": 5,
    "REGISTRO PUBLICO DE COMERCIO": 5,
    "FOLIO MERCANTIL": 5,
    "NOTARIA": 4,
    "NOTARIO": 4,
    "CONSTITUCION": 4,
    "CONSTITUCIÓN": 4,
}

OCR_FIELD_PATTERNS = [
    (r"\bESCRITURA\b.{0,24}\d", 4),
    (r"\bLIBRO\b.{0,24}\d", 4),
    (r"\bFECHA\b.{0,40}\d", 4),
    (r"\bACTO\b.{0,80}(CONSTITUCION|CONSTITUCIÓN|SOCIEDAD|S\.A\.)", 6),
    (r"REGISTRO\s+PUBLICO\s+DE\s+COMERCIO", 5),
]

OCR_LAYOUT_PRIORITY_PAGES = 3
OCR_ACCEPTABLE_SCORE = 12

COMMON_TILDE_WORDS = {
    "n~mero": "número",
    "m~xico": "méxico",
    "p~blico": "público",
    "inscripci~n": "inscripción",
    "manifestaci~n": "manifestación",
    "declaraci~n": "declaración",
    "administraci~n": "administración",
    "denominaci~n": "denominación",
    "constituci~n": "constitución",
    "an~nima": "anónima",
    "cl~usula": "cláusula",
    "cl~usulas": "cláusulas",
    "trav~s": "través",
    "d~cimo": "décimo",
    "vig~simo": "vigésimo",
    "trig~simo": "trigésimo",
    "s~ptimo": "séptimo",
    "t~rmino": "término",
    "t~rminos": "términos",
    "ap~ndice": "apéndice",
    "participaci~n": "participación",
    "car~cter": "carácter",
    "p~rdida": "pérdida",
    "cr~dito": "crédito",
    "pr~stamos": "préstamos",
    "dem~s": "demás",
    "ser~n": "serán",
    "s~lo": "sólo",
    "c~dula": "cédula",
    "c~dulas": "cédulas",
    "trat~ndose": "tratándose",
    "diecis~is": "dieciséis",
    "duraci~n": "duración",
    "t~cnicos": "técnicos",
    "intermediaci~n": "intermediación",
    "colocaci~n": "colocación",
    "jim~nez": "jiménez",
    "s~nchez": "sánchez",
    "adquisici~n": "adquisición",
    "notificaci~n": "notificación",
    "resoluci~n": "resolución",
    "petici~n": "petición",
    "secci~n": "sección",
    "admisi~n": "admisión",
    "designaci~n": "designación",
    "acci~n": "acción",
    "despu~s": "después",
    "estar~n": "estarán",
    "deber~n": "deberán",
    "podr~n": "podrán",
    "tendr~n": "tendrán",
    "firmar~n": "firmarán",
    "observar~n": "observarán",
    "est~n": "están",
    "teni~ndose": "teniéndose",
    "expres~ndose": "expresándose",
    "econ~micas": "económicas",
    "v~lidamente": "válidamente",
    "claus~ra": "clausura",
    "m~<ico": "méxico",
    "reducci6~n": "reducción",
}

COMMON_TILDE_PATTERN = re.compile(
    r"\b(" + "|".join(re.escape(word) for word in COMMON_TILDE_WORDS.keys()) + r")\b",
    re.IGNORECASE,
)


def normalize_text(text: str) -> str:
    text = text or ""
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def normalize_ocr_candidate(text: str) -> str:
    text = text or ""
    text = text.replace("\r\n", "\n").replace("\r", "\n")

    normalized_lines: list[str] = []
    previous_blank = False
    for raw_line in text.split("\n"):
        line = normalize_notarial_hyphen_sequences(raw_line)
        line = re.sub(r"[ \t\xa0]+", " ", line).strip()
        line = restore_common_tilde_words(line)
        if line == "":
            if normalized_lines and not previous_blank:
                normalized_lines.append("")
            previous_blank = True
            continue

        normalized_lines.append(line)
        previous_blank = False

    while normalized_lines and normalized_lines[-1] == "":
        normalized_lines.pop()

    return "\n".join(normalized_lines)


def is_noisy_ocr_line(line: str) -> bool:
    normalized = line.strip()
    if normalized == "":
        return False

    if re.fullmatch(r"[-—_=~.*,:;|\\/()\[\]{}'\"`]+", normalized) is not None:
        return True

    alnum_count = sum(1 for char in normalized if char.isalnum())
    alpha_count = sum(1 for char in normalized if char.isalpha())
    symbol_count = sum(1 for char in normalized if not char.isalnum() and not char.isspace())

    if alnum_count == 0 and symbol_count >= 4:
        return True

    if alpha_count < 3 and symbol_count > alnum_count:
        return True

    return False


def build_ocr_index_text(page_texts: list[str]) -> str:
    kept_lines: list[str] = []
    previous_blank = False

    for page in build_index_pages(page_texts):
        page_text = page["text"]
        if page_text:
            for line in page_text.split("\n"):
                normalized = line.strip()
                if normalized == "":
                    if kept_lines and not previous_blank:
                        kept_lines.append("")
                    previous_blank = True
                    continue

                kept_lines.append(normalized)
                previous_blank = False

        if kept_lines and not previous_blank:
            kept_lines.append("")
            previous_blank = True

    while kept_lines and kept_lines[-1] == "":
        kept_lines.pop()

    return "\n".join(kept_lines)


def extract_native(pdf_path: str) -> str:
    reader = PdfReader(pdf_path)
    parts = []
    for page in reader.pages:
        try:
            parts.append(page.extract_text() or "")
        except Exception:
            parts.append("")
    return normalize_text("\n\n".join(parts))


def extract_native_pages(pdf_path: str) -> list[dict[str, object]]:
    reader = PdfReader(pdf_path)
    pages: list[dict[str, object]] = []
    for page_number, page in enumerate(reader.pages, start=1):
        try:
            page_text = page.extract_text() or ""
        except Exception:
            page_text = ""

        pages.append({
            "page_number": page_number,
            "text": normalize_ocr_candidate(page_text),
        })

    return pages


def preprocess_ocr_image(image):
    image = ImageOps.grayscale(image)
    image = ImageOps.autocontrast(image)
    image = image.filter(ImageFilter.SHARPEN)

    # A light threshold improves high-contrast document labels without fully erasing thin characters.
    return image.point(lambda pixel: 255 if pixel > 170 else 0)


def score_ocr_text(text: str) -> int:
    if not text:
        return 0

    upper_text = text.upper()
    score = 0

    for keyword, weight in OCR_KEYWORDS.items():
        score += upper_text.count(keyword) * weight

    for pattern, weight in OCR_FIELD_PATTERNS:
        if re.search(pattern, upper_text) is not None:
            score += weight

    score += page_registry_score(text)

    non_space_chars = len(re.sub(r"\s+", "", text))
    if non_space_chars > 0:
        alnum_chars = sum(1 for char in text if char.isalnum())
        score += int(6 * (alnum_chars / non_space_chars))

    score += min(len(text) // 120, 4)

    return score


def run_ocr_variant(image, lang: str, config: str = "") -> str:
    text = pytesseract.image_to_string(image, lang=lang, config=config)
    return normalize_ocr_candidate(text)


def extract_ocr_page(image, lang: str, page_number: int) -> str:
    base_image = image if image.mode == "RGB" else image.convert("RGB")
    preprocessed_image = preprocess_ocr_image(base_image)

    candidates: list[tuple[int, int, str]] = []

    default_text = run_ocr_variant(base_image, lang)
    candidates.append((score_ocr_text(default_text), len(default_text), default_text))

    should_try_layout_variants = page_number <= OCR_LAYOUT_PRIORITY_PAGES or candidates[0][0] < OCR_ACCEPTABLE_SCORE
    if should_try_layout_variants:
        for config in [
            "--oem 3 --psm 6 -c preserve_interword_spaces=1",
            "--oem 3 --psm 4 -c preserve_interword_spaces=1",
        ]:
            variant_text = run_ocr_variant(preprocessed_image, lang, config=config)
            candidates.append((score_ocr_text(variant_text), len(variant_text), variant_text))

    candidates.sort(key=lambda item: (item[0], item[1]), reverse=True)
    return candidates[0][2] if candidates else ""


def extract_ocr_page_texts(pdf_path: str, lang: str) -> list[str]:
    images = convert_from_path(pdf_path, dpi=300)
    text_parts: list[dict[str, object]] = []
    for page_number, image in enumerate(images, start=1):
        page_text = extract_ocr_page(image, lang, page_number)
        text_parts.append({
            "page_number": page_number,
            "text": page_text,
        })

    return text_parts


def extract_ocr(pdf_path: str, lang: str) -> str:
    text_parts = extract_ocr_page_texts(pdf_path, lang)

    return normalize_text("\n\n".join(page["text"] for page in text_parts if page["text"]))


def extract_ocr_index_text(pdf_path: str, lang: str) -> str:
    text_parts = extract_ocr_page_texts(pdf_path, lang)
    return build_ocr_index_text([page["text"] for page in text_parts])


def build_index_page_text(page_text: str) -> str:
    kept_lines: list[str] = []
    previous_blank = False

    for line in normalize_ocr_candidate(page_text).split("\n"):
        if is_noisy_ocr_line(line):
            continue

        normalized = line.strip()
        if normalized == "":
            if kept_lines and not previous_blank:
                kept_lines.append("")
            previous_blank = True
            continue

        kept_lines.append(normalized)
        previous_blank = False

    while kept_lines and kept_lines[-1] == "":
        kept_lines.pop()

    return "\n".join(kept_lines)


def build_index_pages(page_texts: list[dict[str, object]] | list[str]) -> list[dict[str, object]]:
    index_pages: list[dict[str, object]] = []

    for page_number, page in enumerate(page_texts, start=1):
        if isinstance(page, dict):
            current_page_number = int(page.get("page_number") or page_number)
            raw_text = str(page.get("text") or "")
        else:
            current_page_number = page_number
            raw_text = str(page or "")

        index_pages.append({
            "page_number": current_page_number,
            "text": build_index_page_text(raw_text),
        })

    return index_pages


def normalize_notarial_hyphen_sequences(text: str) -> str:
    text = re.sub(r"(?:\s*[-—]\s*){2,}\s*$", "", text)
    text = re.sub(r"(?:\s*[-—]\s*){2,}", " ", text)
    return text


def match_replacement_case(source: str, replacement: str) -> str:
    if source.isupper():
        return replacement.upper()

    if source.islower():
        return replacement.lower()

    if source[:1].isupper() and source[1:].islower():
        return replacement.capitalize()

    return replacement


def restore_common_tilde_words(text: str) -> str:
    if "~" not in text:
        return text

    def replace(match: re.Match[str]) -> str:
        original = match.group(0)
        replacement = COMMON_TILDE_WORDS.get(original.lower())
        if replacement is None:
            return original

        return match_replacement_case(original, replacement)

    return COMMON_TILDE_PATTERN.sub(replace, text)


def extract_last_pages_as_base64(
    pdf_path: str,
    max_pages: int = 3,
    scan_pages: int = 8,
    max_width: int = 1400,
    quality: int = 70,
) -> list[str]:
    if max_pages <= 0:
        return []

    selected_pages = select_vision_pages(pdf_path, max_pages=max_pages, scan_pages=scan_pages)
    if not selected_pages:
        return []

    base64_images: list[str] = []
    for page_number in selected_pages:
        images = convert_from_path(pdf_path, dpi=180, first_page=page_number, last_page=page_number)
        if not images:
            continue
        image = images[0]
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


def extract_pages_as_base64(
    pdf_path: str,
    page_numbers: list[int],
    max_width: int = 1400,
    quality: int = 70,
    dpi: int = 180,
) -> list[str]:
    if not page_numbers:
        return []

    base64_images: list[str] = []
    for page_number in page_numbers:
        images = convert_from_path(pdf_path, dpi=dpi, first_page=page_number, last_page=page_number)
        if not images:
            continue

        image = images[0]
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


def select_first_pages(total_pages: int, max_pages: int = 3) -> list[int]:
    if total_pages <= 0 or max_pages <= 0:
        return []

    return list(range(1, min(total_pages, max_pages) + 1))


def page_registry_score(text: str) -> int:
    if not text:
        return 0

    lowered = text.lower()
    score = 0
    for keyword, weight in VISION_KEYWORDS.items():
        if keyword in lowered:
            score += weight

    return score


def select_vision_pages(pdf_path: str, max_pages: int = 3, scan_pages: int = 8) -> list[int]:
    if max_pages <= 0:
        return []

    reader = PdfReader(pdf_path)
    total_pages = len(reader.pages)
    if total_pages <= 0:
        return []

    start_page = max(1, total_pages - max(scan_pages, 1) + 1)
    scored_pages: list[tuple[int, int]] = []
    for idx in range(start_page, total_pages + 1):
        page = reader.pages[idx - 1]
        try:
            page_text = page.extract_text() or ""
        except Exception:
            page_text = ""

        score = page_registry_score(page_text)
        if idx >= max(1, total_pages - 2):
            # Bias toward final pages where RPC/notarial stamps frequently appear.
            score += 2

        scored_pages.append((idx, score))

    scored_pages.sort(key=lambda item: (item[1], item[0]), reverse=True)

    selected: list[int] = []
    for page_num, score in scored_pages:
        if score <= 0 and selected:
            continue
        selected.append(page_num)
        if len(selected) >= max_pages:
            break

    # Safety net: always include final page.
    if total_pages not in selected:
        if len(selected) >= max_pages:
            selected[-1] = total_pages
        else:
            selected.append(total_pages)

    return sorted(set(selected))


def main() -> int:
    if len(sys.argv) < 2:
        print(
            json.dumps(
                {
                    "error": "Usage: pdf_extract.py <pdf_path> [lang] [vision_pages] [vision_scan_pages] [vision_max_width] [vision_quality]"
                }
            )
        )
        return 1

    pdf_path = sys.argv[1]
    lang = sys.argv[2] if len(sys.argv) > 2 else "spa+eng"
    vision_pages = int(sys.argv[3]) if len(sys.argv) > 3 else 3
    vision_scan_pages = int(sys.argv[4]) if len(sys.argv) > 4 else 8
    vision_max_width = int(sys.argv[5]) if len(sys.argv) > 5 else 1400
    vision_quality = int(sys.argv[6]) if len(sys.argv) > 6 else 70

    if not os.path.exists(pdf_path):
        print(json.dumps({"error": f"File not found: {pdf_path}"}))
        return 1

    try:
        text = ""
        index_text = ""
        index_pages: list[dict[str, object]] = []
        method = "native"

        try:
            native_pages = extract_native_pages(pdf_path)
            text = normalize_text("\n\n".join(page["text"] for page in native_pages if page["text"]))
            index_pages = build_index_pages(native_pages)
        except Exception:
            text = ""
            index_pages = []

        index_text = "\n\n".join(page["text"] for page in index_pages if page["text"])

        # If native extraction is empty/too short or failed, fallback to OCR.
        if len(text) < 120:
            ocr_page_texts = extract_ocr_page_texts(pdf_path, lang)
            text = normalize_text("\n\n".join(page["text"] for page in ocr_page_texts if page["text"]))
            index_pages = build_index_pages(ocr_page_texts)
            index_text = "\n\n".join(page["text"] for page in index_pages if page["text"])
            method = "ocr"

        reader = PdfReader(pdf_path)
        total_pages = len(reader.pages)
        vision_page_numbers = select_vision_pages(pdf_path, max_pages=vision_pages, scan_pages=vision_scan_pages)
        vision_first_page_numbers = select_first_pages(total_pages, max_pages=max(vision_pages, 3))

        vision_pages_payload = extract_last_pages_as_base64(
            pdf_path,
            max_pages=vision_pages,
            scan_pages=vision_scan_pages,
            max_width=vision_max_width,
            quality=vision_quality,
        )

        vision_first_pages_payload = extract_pages_as_base64(
            pdf_path,
            page_numbers=vision_first_page_numbers,
            max_width=vision_max_width,
            quality=vision_quality,
        )

        print(
            json.dumps(
                {
                    "method": method,
                    "chars": len(text),
                    "text": text,
                    "index_text": index_text,
                    "index_pages": index_pages,
                    "vision_pages": vision_pages_payload,
                    "vision_page_numbers": vision_page_numbers,
                    "vision_first_pages": vision_first_pages_payload,
                    "vision_first_page_numbers": vision_first_page_numbers,
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
