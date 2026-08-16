import argparse
import json
import math
import os
import pickle
import re
from collections import defaultdict
from difflib import SequenceMatcher


ROOT_DIR = os.path.dirname(os.path.dirname(__file__))
ML_MODEL_PATH = os.path.join(os.path.dirname(__file__), "models", "food_classifier.joblib")
ML_CONFIDENCE_THRESHOLD = 0.7
ML_CATEGORY_SUGGESTION_THRESHOLD = 0.45
ML_CLASSIFIER = None
ML_CLASSIFIER_STATUS = {
    "enabled": False,
    "status": "not_loaded",
    "model_path": ML_MODEL_PATH,
    "message": "ML food classifier has not been loaded yet.",
}
ML_MODEL_BUNDLE = {}

PRICE_PATTERN = re.compile(r"(?:[$£€]|rs\.?|lkr|usd)?\s*\d+[.,]\d{2}\b", re.IGNORECASE)
PACKAGE_MEASURE_PATTERN = re.compile(
    r"\b\d+(?:[.,]\d+)?\s*(?:kg|g|mg|l|ml|oz|lb|lbs|pcs|pc|ct|cl|pk|pack|packs)\b",
    re.IGNORECASE,
)
LEADING_QUANTITY_PATTERN = re.compile(r"^\s*(\d+(?:\.\d+)?)\s*(?:x|\*)?\s+[a-z]", re.IGNORECASE)
INLINE_QUANTITY_PATTERN = re.compile(r"\b(?:x\s*(\d+(?:\.\d+)?)|(\d+(?:\.\d+)?)\s*x)\b", re.IGNORECASE)
TRAILING_INTEGER_PATTERN = re.compile(r"\b(\d{1,3})\s*$")

RECEIPT_METADATA_PATTERNS = [
    re.compile(pattern, re.IGNORECASE)
    for pattern in [
        r"^(?:sub\s*)?total\b",
        r"^cash\b",
        r"^change\b",
        r"^(?:card|credit|debit|payment|paid|balance)\b",
        r"^(?:chip|chip\s*card|gift\s*card)\b",
        r"^(?:net\s*)?sales\b",
        r"^(?:savings?|returns?|refunds?)\b",
        r"^(?:sold|items?)\b",
        r"^(?:tax|vat|gst|service\s*charge)\b",
        r"^discount\b",
        r"^(?:receipt|invoice|transaction|trans|txn|reference|ref|auth|approval|terminal|merchant|cashier|operator|store|branch)\b",
        r"^(?:date|time)\b",
        r"^(?:feedback|survey|website|www|https?|please\s+visit|learn\s+more|go\s+to)\b",
        r"^addi\s*tional\b",
        r"^(?:prime|members?|amazon|visa|mastercard|amex|discover)\b",
        r"\b(?:mastercard|card\s*aid|gift\s*card|prime\s*rewards|whole\s+foods\s+market|amazon|allazon|shopping\s+experience)\b",
        r"\b\d+\s*(?:%?\s*)back\b",
        r"\b(?:\.com|/returns|/feedback|http)\b",
        r"x{4,}",
        r"\boff\s*/?\s*lb\b",
        r"^\d{3}[-\s]?\d{3}[-\s]?\d{4}$",
        r"^(?:thank\s*you|thanks|goodbye|welcome|visit\s*again)\b",
        r"^\*?\s*(?:thank\s*you|thanks)\b",
    ]
]
RECEIPT_METADATA_TOKENS = {
    "total",
    "subtotal",
    "cash",
    "change",
    "card",
    "credit",
    "debit",
    "payment",
    "chip",
    "paid",
    "tax",
    "vat",
    "gst",
    "discount",
    "receipt",
    "invoice",
    "transaction",
    "txn",
    "reference",
    "ref",
    "auth",
    "aid",
    "approval",
    "sales",
    "savings",
    "saving",
    "net",
    "sold",
    "items",
    "item",
    "paid",
    "payment",
    "mastercard",
    "visa",
    "returns",
    "return",
    "refund",
    "feedback",
    "website",
    "market",
    "prime",
    "members",
    "amazon",
    "allazon",
    "video",
    "music",
    "musict",
    "bathroom",
    "rathroom",
    "additional",
    "code",
    "enter",
}
NON_FOOD_PHRASES = {
    "wrapping paper",
    "garden gloves",
    "toilet paper",
    "paper towel",
    "paper towels",
    "washing powder",
    "dish soap",
    "laundry detergent",
}
NON_FOOD_TOKENS = {
    "gloves",
    "detergent",
    "soap",
    "shampoo",
    "conditioner",
    "toothpaste",
    "toothbrush",
    "battery",
    "batteries",
    "napkins",
    "tissues",
    "tissue",
    "foil",
    "wrap",
    "wrapping",
    "cleaner",
    "bleach",
    "sponge",
}
GENERIC_ALIAS_TOKENS = {
    "fresh",
    "free",
    "range",
    "plain",
    "gold",
    "instant",
    "assorted",
    "salt",
    "salted",
    "sweet",
    "low",
    "fat",
    "processed",
    "mixed",
    "pack",
    "packet",
    "bottle",
    "carton",
    "cup",
    "tin",
    "can",
    "bag",
    "box",
    "family",
    "large",
    "small",
    "organic",
    "og",
    "white",
    "sliced",
    "shredded",
    "whole",
    "wfm",
    "365wfm",
    "s65wfm",
}
OCR_TOKEN_REPLACEMENTS = {
    "bf": "beef",
    "chk": "chuck",
    "ched": "cheddar",
    "mshrm": "mushroom",
    "mshrms": "mushrooms",
    "slcd": "sliced",
    "shred": "shredded",
    "wht": "white",
}
OCR_NOISE_TOKENS = {
    "365wfm",
    "s65wfm",
    "wfm",
    "og",
}
ML_WEAK_EVIDENCE_TOKENS = {
    "cream",
    "meat",
    "organic",
    "white",
    "sliced",
    "shredded",
    "pulp",
}


FOOD_GRAPH = {
    "milk": {
        "aliases": ["milk", "fresh milk", "low fat milk"],
        "category": "dairy",
        "sugar_g": 12,
        "saturated_fat_g": 3,
        "sodium_mg": 100,
        "fiber_g": 0,
        "risk": "moderate dairy sugar",
        "recommendation": "Choose low-fat unsweetened milk when possible.",
    },
    "bread": {
        "aliases": ["bread", "white bread", "sandwich bread"],
        "category": "grain",
        "sugar_g": 3,
        "saturated_fat_g": 1,
        "sodium_mg": 180,
        "fiber_g": 1,
        "risk": "refined carbohydrate",
        "recommendation": "Replace some white bread with whole-grain bread.",
    },
    "soda": {
        "aliases": ["soda", "cola", "soft drink", "carbonated drink"],
        "category": "sugary drink",
        "sugar_g": 39,
        "saturated_fat_g": 0,
        "sodium_mg": 45,
        "fiber_g": 0,
        "risk": "high sugar",
        "recommendation": "Reduce sugary drinks and choose water or unsweetened tea.",
    },
    "chips": {
        "aliases": ["chips", "potato chips", "crisps"],
        "category": "snack",
        "sugar_g": 1,
        "saturated_fat_g": 3,
        "sodium_mg": 220,
        "fiber_g": 1,
        "risk": "high sodium snack",
        "recommendation": "Limit salty snacks and add fruit, nuts, or yogurt instead.",
    },
    "apple": {
        "aliases": ["apple", "apples"],
        "category": "fruit",
        "sugar_g": 19,
        "saturated_fat_g": 0,
        "sodium_mg": 2,
        "fiber_g": 4,
        "risk": "low risk",
        "recommendation": "Keep fruits as a regular purchase for fiber and micronutrients.",
    },
    "rice": {
        "aliases": ["rice", "white rice", "raw rice"],
        "category": "grain",
        "sugar_g": 0,
        "saturated_fat_g": 0,
        "sodium_mg": 2,
        "fiber_g": 1,
        "risk": "low fiber grain",
        "recommendation": "Balance rice-heavy meals with vegetables and protein.",
    },
    "vegetables": {
        "aliases": ["vegetables", "carrot", "broccoli", "spinach"],
        "category": "vegetable",
        "sugar_g": 4,
        "saturated_fat_g": 0,
        "sodium_mg": 35,
        "fiber_g": 5,
        "risk": "low risk",
        "recommendation": "Good vegetable variety improves nutrient diversity.",
    },
}

FOOD_GRAPH.update({
    "yogurt": {
        "aliases": ["yogurt", "curd", "flavored yogurt"],
        "category": "dairy",
        "sugar_g": 17,
        "saturated_fat_g": 2,
        "sodium_mg": 85,
        "fiber_g": 0,
        "risk": "sweetened dairy risk",
        "recommendation": "Choose plain yogurt and add fresh fruit.",
    },
    "cheese": {
        "aliases": ["cheese", "cheddar", "mozzarella", "cheddar cheese"],
        "category": "dairy",
        "sugar_g": 1,
        "saturated_fat_g": 6,
        "sodium_mg": 350,
        "fiber_g": 0,
        "risk": "high sodium dairy",
        "recommendation": "Use smaller portions or reduced-sodium cheese.",
    },
    "oats": {
        "aliases": ["oats", "oatmeal", "rolled oats"],
        "category": "whole grain",
        "sugar_g": 1,
        "saturated_fat_g": 1,
        "sodium_mg": 2,
        "fiber_g": 4,
        "risk": "low risk",
        "recommendation": "Keep oats as a high-fiber breakfast option.",
    },
    "cereal": {
        "aliases": ["cereal", "corn flakes", "breakfast cereal"],
        "category": "breakfast",
        "sugar_g": 15,
        "saturated_fat_g": 1,
        "sodium_mg": 220,
        "fiber_g": 2,
        "risk": "added sugar breakfast",
        "recommendation": "Choose low-sugar cereal with higher fiber.",
    },
    "juice": {
        "aliases": ["juice", "orange juice", "fruit juice", "orange no pulp", "orange pulp", "no pulp orange"],
        "category": "sugary drink",
        "sugar_g": 24,
        "saturated_fat_g": 0,
        "sodium_mg": 10,
        "fiber_g": 0,
        "risk": "liquid sugar",
        "recommendation": "Prefer whole fruit over juice.",
    },
    "cookies": {
        "aliases": ["cookies", "biscuits", "cream biscuit"],
        "category": "sweet snack",
        "sugar_g": 14,
        "saturated_fat_g": 5,
        "sodium_mg": 120,
        "fiber_g": 1,
        "risk": "high sugar snack",
        "recommendation": "Reduce sweet packaged snacks.",
    },
    "chocolate": {
        "aliases": ["chocolate", "choco", "candy"],
        "category": "sweet snack",
        "sugar_g": 24,
        "saturated_fat_g": 8,
        "sodium_mg": 30,
        "fiber_g": 2,
        "risk": "high sugar and saturated fat",
        "recommendation": "Keep chocolate as an occasional item.",
    },
    "banana": {
        "aliases": ["banana", "bananas"],
        "category": "fruit",
        "sugar_g": 14,
        "saturated_fat_g": 0,
        "sodium_mg": 1,
        "fiber_g": 3,
        "risk": "low risk",
        "recommendation": "Use bananas as a convenient fiber-rich snack.",
    },
    "orange": {
        "aliases": ["orange", "oranges"],
        "category": "fruit",
        "sugar_g": 12,
        "saturated_fat_g": 0,
        "sodium_mg": 0,
        "fiber_g": 3,
        "risk": "low risk",
        "recommendation": "Add citrus fruit for micronutrient diversity.",
    },
    "beans": {
        "aliases": ["beans", "lentils", "chickpeas", "dhal", "dal"],
        "category": "legume",
        "sugar_g": 1,
        "saturated_fat_g": 0,
        "sodium_mg": 5,
        "fiber_g": 8,
        "risk": "low risk",
        "recommendation": "Beans improve fiber and plant protein.",
    },
    "chicken": {
        "aliases": ["chicken", "chicken breast"],
        "category": "protein",
        "sugar_g": 0,
        "saturated_fat_g": 2,
        "sodium_mg": 70,
        "fiber_g": 0,
        "risk": "low risk",
        "recommendation": "Choose grilled or boiled chicken more often.",
    },
    "fish": {
        "aliases": ["fish", "tuna", "salmon", "sardine"],
        "category": "protein",
        "sugar_g": 0,
        "saturated_fat_g": 1,
        "sodium_mg": 80,
        "fiber_g": 0,
        "risk": "low risk",
        "recommendation": "Fish adds protein and healthy fats.",
    },
    "sausages": {
        "aliases": ["sausages", "sausage", "hot dog", "processed meat"],
        "category": "processed meat",
        "sugar_g": 2,
        "saturated_fat_g": 7,
        "sodium_mg": 650,
        "fiber_g": 0,
        "risk": "processed meat sodium",
        "recommendation": "Limit processed meats and choose fresh protein.",
    },
    "noodles": {
        "aliases": ["noodles", "instant noodles", "ramen"],
        "category": "processed grain",
        "sugar_g": 2,
        "saturated_fat_g": 7,
        "sodium_mg": 850,
        "fiber_g": 2,
        "risk": "high sodium instant food",
        "recommendation": "Reduce instant noodles and add vegetables if used.",
    },
    "sauce": {
        "aliases": ["sauce", "ketchup", "soy sauce", "tomato sauce"],
        "category": "condiment",
        "sugar_g": 8,
        "saturated_fat_g": 0,
        "sodium_mg": 500,
        "fiber_g": 0,
        "risk": "hidden sugar and sodium",
        "recommendation": "Use sauces sparingly.",
    },
    "oil": {
        "aliases": ["oil", "cooking oil", "vegetable oil"],
        "category": "fat",
        "sugar_g": 0,
        "saturated_fat_g": 2,
        "sodium_mg": 0,
        "fiber_g": 0,
        "risk": "high energy density",
        "recommendation": "Measure oil portions while cooking.",
    },
    "nuts": {
        "aliases": ["nuts", "peanuts", "almonds", "cashew"],
        "category": "healthy fat",
        "sugar_g": 2,
        "saturated_fat_g": 2,
        "sodium_mg": 5,
        "fiber_g": 3,
        "risk": "low risk",
        "recommendation": "Choose unsalted nuts for healthy fats.",
    },
})

FOOD_GRAPH.update({
    "pasta": {
        "aliases": ["pasta", "dry pasta", "organic pasta", "vodka pasta"],
        "category": "grain",
        "sugar_g": 1,
        "saturated_fat_g": 0,
        "sodium_mg": 5,
        "fiber_g": 2,
        "risk": "refined grain",
        "recommendation": "Balance pasta with vegetables and protein.",
    },
    "beef stew meat": {
        "aliases": ["beef stew meat", "beef chuck stew meat", "beef chuck", "stew meat"],
        "category": "protein",
        "sugar_g": 0,
        "saturated_fat_g": 4,
        "sodium_mg": 70,
        "fiber_g": 0,
        "risk": "moderate saturated fat protein",
        "recommendation": "Use lean portions and balance beef with vegetables or beans.",
    },
    "heavy cream": {
        "aliases": ["heavy cream", "organic heavy cream"],
        "category": "dairy fat",
        "sugar_g": 1,
        "saturated_fat_g": 7,
        "sodium_mg": 10,
        "fiber_g": 0,
        "risk": "high saturated fat dairy",
        "recommendation": "Use heavy cream sparingly or choose lighter dairy when possible.",
    },
    "chicken sausage": {
        "aliases": ["chicken sausage", "organic chicken sausage"],
        "category": "processed meat",
        "sugar_g": 1,
        "saturated_fat_g": 4,
        "sodium_mg": 520,
        "fiber_g": 0,
        "risk": "processed meat sodium",
        "recommendation": "Limit chicken sausage and choose fresh protein more often.",
    },
    "cucumber": {
        "aliases": ["cucumber", "english cucumber"],
        "category": "vegetable",
        "sugar_g": 2,
        "saturated_fat_g": 0,
        "sodium_mg": 2,
        "fiber_g": 1,
        "risk": "low risk",
        "recommendation": "Keep cucumbers as a low-sodium vegetable option.",
    },
    "mushrooms": {
        "aliases": ["mushrooms", "mushroom", "white sliced mushroom", "white sliced mushrooms"],
        "category": "vegetable",
        "sugar_g": 1,
        "saturated_fat_g": 0,
        "sodium_mg": 5,
        "fiber_g": 1,
        "risk": "low risk",
        "recommendation": "Mushrooms add vegetable variety with low sodium.",
    },
    "shredded jack cheese": {
        "aliases": ["shredded jack cheese", "cheddar jack shredded", "cheddar jack shred", "ched jack shred", "ched jack shredded"],
        "category": "dairy",
        "sugar_g": 1,
        "saturated_fat_g": 6,
        "sodium_mg": 360,
        "fiber_g": 0,
        "risk": "high sodium dairy",
        "recommendation": "Use shredded cheese in smaller portions or compare lower-sodium options.",
    },
    "almond tortillas": {
        "aliases": ["almond tortillas", "almond tortilla"],
        "category": "grain alternative",
        "sugar_g": 1,
        "saturated_fat_g": 1,
        "sodium_mg": 180,
        "fiber_g": 2,
        "risk": "packaged grain sodium",
        "recommendation": "Check tortilla sodium and pair with vegetables and protein.",
    },
    "cassava tortillas": {
        "aliases": ["cassava tortillas", "cassava tortilla"],
        "category": "grain alternative",
        "sugar_g": 1,
        "saturated_fat_g": 1,
        "sodium_mg": 180,
        "fiber_g": 2,
        "risk": "packaged grain sodium",
        "recommendation": "Check tortilla sodium and balance with higher-fiber foods.",
    },
    "salmon fillet": {
        "aliases": ["salmon fillet", "salmon filet"],
        "category": "protein",
        "sugar_g": 0,
        "saturated_fat_g": 1,
        "sodium_mg": 80,
        "fiber_g": 0,
        "risk": "low risk",
        "recommendation": "Salmon is a strong protein choice with healthy fats.",
    },
})

ANOMALY_BASELINES = {
    "soda": {"mean": 1.0, "std": 0.7},
    "chips": {"mean": 1.0, "std": 0.8},
    "bread": {"mean": 2.0, "std": 1.0},
    "milk": {"mean": 2.0, "std": 1.0},
    "cookies": {"mean": 1.0, "std": 0.7},
    "chocolate": {"mean": 1.0, "std": 0.8},
    "noodles": {"mean": 1.0, "std": 0.7},
    "sausages": {"mean": 1.0, "std": 0.7},
    "juice": {"mean": 1.0, "std": 0.8},
}

OCR_STATUS = {
    "engine": "text_file",
    "status": "text_input",
    "message": "Text receipt was read directly.",
    "confidence": 1.0,
    "confidence_label": "High",
}

SHOPPING_ALTERNATIVES = {
    "soda": [
        "water",
        "unsweetened tea",
        "unsweetened coconut water in small portions (it still has natural sugar)",
        "sparkling water without sugar",
    ],
    "juice": ["whole fruit", "water with citrus", "unsweetened tea"],
    "chips": ["unsalted nuts", "fruit", "plain yogurt"],
    "cookies": ["fruit", "oats", "plain yogurt with banana"],
    "chocolate": ["fruit", "unsalted nuts", "small dark chocolate portion"],
    "noodles": ["rice with vegetables", "oats", "beans with vegetables"],
    "sausages": ["fresh chicken", "fish", "beans"],
    "sauce": ["fresh herbs", "lime", "homemade low-salt sauce"],
    "cheese": ["smaller cheese portion", "plain yogurt", "low-sodium cheese"],
    "bread": ["whole-grain bread", "oats", "beans"],
}

BUDGET_ALTERNATIVES = {
    "water": "low cost",
    "unsweetened tea": "low cost",
    "unsweetened coconut water in small portions (it still has natural sugar)": "moderate cost",
    "sparkling water without sugar": "moderate cost",
    "whole fruit": "moderate cost",
    "water with citrus": "low cost",
    "unsalted nuts": "moderate cost",
    "fruit": "moderate cost",
    "plain yogurt": "moderate cost",
    "oats": "low cost",
    "plain yogurt with banana": "moderate cost",
    "small dark chocolate portion": "moderate cost",
    "rice with vegetables": "low cost",
    "beans with vegetables": "low cost",
    "fresh chicken": "moderate cost",
    "fish": "moderate cost",
    "beans": "low cost",
    "fresh herbs": "low cost",
    "lime": "low cost",
    "homemade low-salt sauce": "low cost",
    "smaller cheese portion": "moderate cost",
    "low-sodium cheese": "higher cost",
    "whole-grain bread": "moderate cost",
}


def confidence_label(score):
    if score >= 0.85:
        return "High"
    if score >= 0.6:
        return "Medium"
    return "Low"


def extract_receipt_metadata(text):
    """Extract only explicit, low-risk store/date evidence; never infer values."""
    lines = [re.sub(r"\s+", " ", line).strip(" *\t") for line in str(text or "").splitlines()]
    store = ""
    receipt_date = ""
    date_patterns = [
        re.compile(r"\b(20\d{2}[-/]\d{1,2}[-/]\d{1,2})\b"),
        re.compile(r"\b(\d{1,2}[-/]\d{1,2}[-/]20\d{2})\b"),
        re.compile(r"\b([A-Z][a-z]{2,9}\s+\d{1,2},\s+20\d{2})\b"),
    ]
    for line in lines:
        if not line:
            continue
        labeled_store = re.search(r"^(?:store|merchant|shop|branch)\s*[:#-]?\s*(.{3,80})$", line, re.I)
        if labeled_store and not is_receipt_metadata_line(labeled_store.group(1)):
            store = labeled_store.group(1).strip()
        if not store and len(lines) <= 40 and re.search(r"\b(?:market|foods?|grocery|supermarket)\b", line, re.I) and not is_receipt_metadata_line(line):
            store = line[:80]
        for pattern in date_patterns:
            match = pattern.search(line)
            if match:
                receipt_date = match.group(1)
                break
        if store and receipt_date:
            break
    return {"store_name": store, "receipt_date": receipt_date}


def current_ocr_confidence():
    return float(OCR_STATUS.get("confidence", 0.78))


def normalize_receipt_text(value):
    text = str(value or "").lower()
    text = text.replace(")", "i").replace("!", "i").replace("|", "i")
    text = re.sub(r"[^a-z0-9.,$£€ ]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def normalize_receipt_product_noise(value):
    tokens = []

    for token in receipt_words(value):
        if token in OCR_NOISE_TOKENS:
            continue

        replacement = OCR_TOKEN_REPLACEMENTS.get(token, token)

        if replacement:
            tokens.append(replacement)

    return " ".join(tokens)


def receipt_words(value):
    return re.findall(r"[a-z]{2,}", normalize_receipt_text(value))


def strip_prices_and_measures(value):
    text = normalize_receipt_text(value)
    text = PRICE_PATTERN.sub(" ", text)
    text = PACKAGE_MEASURE_PATTERN.sub(" ", text)
    text = re.sub(r"\b\d+\b", " ", text)
    return normalize_receipt_product_noise(re.sub(r"\s+", " ", text).strip())


def is_receipt_metadata_line(line):
    clean_line = normalize_receipt_text(line)

    if not clean_line:
        return True

    if any(pattern.search(clean_line) for pattern in RECEIPT_METADATA_PATTERNS):
        return True

    words = receipt_words(clean_line)
    if words and set(words).issubset(RECEIPT_METADATA_TOKENS):
        return True

    if len(words) == 1 and words[0] in RECEIPT_METADATA_TOKENS:
        return True

    if len(re.findall(r"\d", clean_line)) >= 7 and not strip_prices_and_measures(clean_line):
        return True

    if not words and re.search(r"\d", clean_line):
        return True

    return False


def is_non_food_household_line(line):
    product_text = strip_prices_and_measures(line)
    if not product_text:
        return False

    if any(phrase in product_text for phrase in NON_FOOD_PHRASES):
        return True

    words = set(receipt_words(product_text))
    return bool(words & NON_FOOD_TOKENS)


def is_ignored_receipt_line(line):
    if is_receipt_metadata_line(line):
        return True

    if not strip_prices_and_measures(line):
        return True

    return is_non_food_household_line(line)


def extract_quantity(line):
    clean_line = normalize_receipt_text(line)

    leading_match = LEADING_QUANTITY_PATTERN.search(clean_line)
    if leading_match:
        return max(1.0, float(leading_match.group(1)))

    inline_match = INLINE_QUANTITY_PATTERN.search(clean_line)
    if inline_match:
        quantity_text = inline_match.group(1) or inline_match.group(2)
        return max(1.0, float(quantity_text))

    without_price = PRICE_PATTERN.sub(" ", clean_line)
    without_measures = PACKAGE_MEASURE_PATTERN.sub(" ", without_price)
    trailing_match = TRAILING_INTEGER_PATTERN.search(without_measures)

    if trailing_match:
        return max(1.0, float(trailing_match.group(1)))

    return 1.0


def alias_tokens(alias):
    return receipt_words(alias)


def distinctive_alias_tokens(alias):
    return [
        token
        for token in alias_tokens(alias)
        if token not in GENERIC_ALIAS_TOKENS and len(token) >= 3
    ]


def deterministic_alias_score(product_text, alias):
    line_text = strip_prices_and_measures(product_text)
    alias_text = strip_prices_and_measures(alias)

    if not line_text or not alias_text:
        return 0.0

    line_words = set(receipt_words(line_text))
    alias_word_list = alias_tokens(alias_text)
    distinctive_tokens = distinctive_alias_tokens(alias_text)

    if line_text == alias_text:
        return 1.0 + len(alias_word_list) * 0.02

    if re.search(rf"\b{re.escape(alias_text)}\b", line_text):
        return 0.94 + len(alias_word_list) * 0.02

    if distinctive_tokens and all(token in line_words for token in distinctive_tokens):
        return 0.88 + len(distinctive_tokens) * 0.02

    if len(alias_word_list) == 1 and distinctive_tokens and distinctive_tokens[0] in line_words:
        return 0.82

    phrase_ratio = SequenceMatcher(None, line_text, alias_text).ratio()
    if len(alias_word_list) > 1 and phrase_ratio >= 0.88:
        return 0.74 + phrase_ratio * 0.1

    if len(alias_word_list) == 1 and distinctive_tokens:
        alias_token = distinctive_tokens[0]
        for line_token in line_words:
            if line_token[0] != alias_token[0]:
                continue
            if abs(len(line_token) - len(alias_token)) > 2:
                continue
            ratio = SequenceMatcher(None, line_token, alias_token).ratio()
            if ratio >= 0.9:
                return 0.72 + ratio * 0.08

    return 0.0


def find_deterministic_food_match(line):
    product_text = strip_prices_and_measures(line)
    best_match = None

    for standard_name, data in FOOD_GRAPH.items():
        aliases = sorted(set([standard_name, *data.get("aliases", [])]), key=len, reverse=True)

        for alias in aliases:
            score = deterministic_alias_score(product_text, alias)
            if score <= 0:
                continue

            candidate = {
                "name": standard_name,
                "confidence": min(0.99, score),
                "method": "exact_food_match" if strip_prices_and_measures(alias) == product_text else "rule_alias",
                "alias": alias,
                "alias_length": len(alias_tokens(alias)),
            }

            if best_match is None:
                best_match = candidate
                continue

            if (candidate["confidence"], candidate["alias_length"]) > (best_match["confidence"], best_match["alias_length"]):
                best_match = candidate

    if best_match and best_match["confidence"] >= 0.72:
        return best_match

    return None


def load_food_classifier():
    global ML_CLASSIFIER, ML_CLASSIFIER_STATUS, ML_MODEL_BUNDLE

    if ML_CLASSIFIER is not None:
        return ML_CLASSIFIER

    if not os.path.isfile(ML_MODEL_PATH):
        ML_CLASSIFIER_STATUS = {
            "enabled": False,
            "status": "missing_model",
            "model_path": ML_MODEL_PATH,
            "message": "Train the model with python/train_food_model.py to enable ML item classification.",
        }
        return None

    try:
        with open(ML_MODEL_PATH, "rb") as model_file:
            loaded_model = pickle.load(model_file)

        if isinstance(loaded_model, dict) and "item_classifier" in loaded_model:
            ML_MODEL_BUNDLE = loaded_model
            ML_CLASSIFIER = loaded_model["item_classifier"]
            model_version = loaded_model.get("model_version", {})
        else:
            ML_MODEL_BUNDLE = {"item_classifier": loaded_model}
            ML_CLASSIFIER = loaded_model
            model_version = {}

        ML_CLASSIFIER_STATUS = {
            "enabled": True,
            "status": "loaded",
            "model_path": ML_MODEL_PATH,
            "version": model_version.get("version", "legacy"),
            "trained_at": model_version.get("trained_at", ""),
            "dataset_rows": model_version.get("dataset_rows", 0),
            "message": "ML food classifier loaded successfully.",
        }
        return ML_CLASSIFIER
    except Exception as exception:
        ML_CLASSIFIER_STATUS = {
            "enabled": False,
            "status": "load_failed",
            "model_path": ML_MODEL_PATH,
            "message": str(exception),
        }
        return None


def predict_food_item(line):
    classifier = load_food_classifier()

    if classifier is None:
        return None

    try:
        if hasattr(classifier, "predict_one"):
            label, confidence = classifier.predict_one(line)
        else:
            label = str(classifier.predict([line])[0])
            confidence = 0.0

            if hasattr(classifier, "predict_proba"):
                probabilities = classifier.predict_proba([line])[0]
                confidence = float(max(probabilities))
            elif hasattr(classifier, "decision_function"):
                scores = classifier.decision_function([line])
                if hasattr(scores, "ravel"):
                    flat_scores = scores.ravel()
                    confidence = 1 / (1 + math.exp(-float(max(flat_scores))))

        if label in FOOD_GRAPH and confidence >= ML_CONFIDENCE_THRESHOLD and ml_prediction_has_evidence(line, label):
            return {
                "name": label,
                "confidence": round(confidence, 2),
                "method": "ml_classifier",
            }
    except Exception as exception:
        ML_CLASSIFIER_STATUS.update({
            "enabled": False,
            "status": "prediction_failed",
            "message": str(exception),
        })

    return None


def ml_prediction_has_evidence(line, label):
    if is_ignored_receipt_line(line):
        return False

    line_tokens = [
        token
        for token in receipt_words(strip_prices_and_measures(line))
        if (
            token not in GENERIC_ALIAS_TOKENS
            and token not in RECEIPT_METADATA_TOKENS
            and token not in ML_WEAK_EVIDENCE_TOKENS
        )
    ]

    if not line_tokens:
        return False

    aliases = FOOD_GRAPH.get(label, {}).get("aliases", [])
    label_tokens = set()

    for alias in [label, *aliases]:
        label_tokens.update(
            token
            for token in distinctive_alias_tokens(alias)
            if token not in ML_WEAK_EVIDENCE_TOKENS
        )

    if not label_tokens:
        return False

    if set(line_tokens) & label_tokens:
        return True

    for line_token in line_tokens:
        for alias_token in label_tokens:
            if line_token[0] != alias_token[0]:
                continue

            if len(line_token) >= 5 and len(alias_token) >= 5:
                if line_token.startswith(alias_token) or alias_token.startswith(line_token):
                    return True

            if abs(len(line_token) - len(alias_token)) <= 2:
                if SequenceMatcher(None, line_token, alias_token).ratio() >= 0.9:
                    return True

    return False


def predict_food_category(line):
    if is_ignored_receipt_line(line):
        return None

    product_tokens = [
        token
        for token in receipt_words(strip_prices_and_measures(line))
        if token not in RECEIPT_METADATA_TOKENS and token not in GENERIC_ALIAS_TOKENS
    ]

    if len(product_tokens) < 2 or len("".join(product_tokens)) < 4:
        return None

    load_food_classifier()
    category_classifier = ML_MODEL_BUNDLE.get("category_classifier")

    if category_classifier is None or not hasattr(category_classifier, "predict_one"):
        return None

    try:
        category, confidence = category_classifier.predict_one(line)

        if category and confidence >= ML_CATEGORY_SUGGESTION_THRESHOLD:
            return {
                "line": line,
                "predicted_category": category,
                "confidence": round(confidence, 2),
                "confidence_label": confidence_label(confidence),
                "method": "ml_category_suggestion",
                "suggestion_type": "suggested_category_only",
                "message": "Suggested category only; no food item was accepted for scoring.",
            }
    except Exception as exception:
        ML_CLASSIFIER_STATUS.update({
            "status": "category_prediction_failed",
            "message": str(exception),
        })

    return None


def alternative_budget_details(replacements):
    return [
        {
            "name": replacement,
            "budget": BUDGET_ALTERNATIVES.get(replacement, "moderate cost"),
            "note": "Budget-friendly swap" if BUDGET_ALTERNATIVES.get(replacement) == "low cost" else "Healthier swap",
        }
        for replacement in replacements
    ]


def simple_aliases(name):
    aliases = {name}
    if name.endswith("y"):
        aliases.add(name[:-1] + "ies")
    elif not name.endswith("s"):
        aliases.add(name + "s")
    return sorted(aliases)


def list_value(value):
    if isinstance(value, list):
        return [str(item).strip().lower() for item in value if str(item).strip()]
    if isinstance(value, str):
        return [item.strip().lower() for item in re.split(r"[\n,]+", value) if item.strip()]
    return []


def load_catalog_overrides():
    catalog_path = os.path.join(ROOT_DIR, "data", "food_catalog.json")

    if not os.path.isfile(catalog_path):
        return

    try:
        with open(catalog_path, "r", encoding="utf-8") as catalog_file:
            catalog = json.load(catalog_file)
    except Exception:
        return

    if not isinstance(catalog, list):
        return

    for food in catalog:
        if not isinstance(food, dict):
            continue

        name = str(food.get("name", "")).strip().lower()
        if not name:
            continue

        existing = FOOD_GRAPH.get(name, {})
        aliases = list_value(food.get("aliases")) or existing.get("aliases") or simple_aliases(name)
        alternatives = list_value(food.get("alternatives"))

        FOOD_GRAPH[name] = {
            "aliases": sorted(set([*aliases, *simple_aliases(name)])),
            "category": str(food.get("category", existing.get("category", "other"))).strip().lower() or "other",
            "sugar_g": float(food.get("sugar_g", existing.get("sugar_g", 0)) or 0),
            "saturated_fat_g": float(food.get("saturated_fat_g", existing.get("saturated_fat_g", 0)) or 0),
            "sodium_mg": float(food.get("sodium_mg", existing.get("sodium_mg", 0)) or 0),
            "fiber_g": float(food.get("fiber_g", existing.get("fiber_g", 0)) or 0),
            "risk": str(food.get("risk", existing.get("risk", "low risk"))).strip() or "low risk",
            "recommendation": str(food.get("recommendation", existing.get("recommendation", "Keep portions balanced."))).strip()
            or "Keep portions balanced.",
        }

        if alternatives:
            SHOPPING_ALTERNATIVES[name] = alternatives


load_catalog_overrides()


def extract_text(input_path):
    global OCR_STATUS
    _, extension = os.path.splitext(input_path.lower())
    attempts = []
    candidates = []

    if extension == ".txt":
        OCR_STATUS = {
            "engine": "text_file",
            "status": "text_input",
            "message": "Text receipt was read directly.",
            "confidence": 1.0,
            "confidence_label": "High",
        }
        with open(input_path, "r", encoding="utf-8", errors="ignore") as receipt:
            return receipt.read()

    try:
        import pytesseract
        from PIL import Image, ImageOps

        image = Image.open(input_path)
        grayscale = ImageOps.grayscale(image)
        extracted = pytesseract.image_to_string(grayscale)
        confidence = 0.78

        try:
            ocr_data = pytesseract.image_to_data(grayscale, output_type=pytesseract.Output.DICT)
            raw_confidences = [
                float(value)
                for value in ocr_data.get("conf", [])
                if str(value).strip() not in {"", "-1"}
            ]
            if raw_confidences:
                confidence = max(0.0, min(1.0, sum(raw_confidences) / len(raw_confidences) / 100))
        except Exception:
            confidence = 0.78 if extracted.strip() else 0.35

        confidence = max(0.0, min(1.0, confidence))
        attempts.append({
            "engine": "pytesseract",
            "status": "success" if extracted.strip() else "empty_output",
            "confidence": round(confidence, 2),
            "text_length": len(extracted.strip()),
        })
        if extracted.strip():
            candidates.append({
                "engine": "pytesseract",
                "text": extracted,
                "confidence": confidence,
            })
    except Exception as exception:
        attempts.append({
            "engine": "pytesseract",
            "status": "not_available",
            "message": str(exception),
        })

    try:
        import easyocr

        reader = easyocr.Reader(["en"], gpu=False, verbose=False)
        raw_lines = reader.readtext(input_path, detail=1, paragraph=False)
        extracted_lines = []
        confidences = []

        for entry in raw_lines:
            if isinstance(entry, (list, tuple)) and len(entry) >= 2:
                extracted_lines.append(str(entry[1]))
                if len(entry) >= 3:
                    try:
                        confidences.append(float(entry[2]))
                    except (TypeError, ValueError):
                        pass

        extracted = "\n".join(line for line in extracted_lines if line.strip())
        confidence = sum(confidences) / len(confidences) if confidences else (0.76 if extracted.strip() else 0.35)
        confidence = max(0.0, min(1.0, confidence))
        attempts.append({
            "engine": "easyocr",
            "status": "success" if extracted.strip() else "empty_output",
            "confidence": round(confidence, 2),
            "text_length": len(extracted.strip()),
        })

        if extracted.strip():
            candidates.append({
                "engine": "easyocr",
                "text": extracted,
                "confidence": confidence,
            })
    except Exception as exception:
        attempts.append({
            "engine": "easyocr",
            "status": "not_available",
            "message": str(exception),
        })

    if candidates:
        selected = max(candidates, key=lambda candidate: (candidate["confidence"], len(candidate["text"].strip())))
        OCR_STATUS = {
            "engine": selected["engine"],
            "status": "success",
            "message": "Image OCR was processed with pytesseract and EasyOCR when available; the best usable result was selected.",
            "confidence": round(selected["confidence"], 2),
            "confidence_label": confidence_label(selected["confidence"]),
            "attempts": attempts,
        }
        return selected["text"]

    OCR_STATUS = {
        "engine": "demo_fallback",
        "status": "demo_text",
        "message": "Image OCR did not return usable text, so demo fallback text was used. Install Tesseract or EasyOCR models for real image receipts.",
        "confidence": 0.35,
        "confidence_label": "Low",
        "attempts": attempts,
    }

    return """
    milk 2
    bread 2
    soda 3
    chips 2
    apples 6
    """


def alias_matches(line, alias):
    return re.search(rf"\b{re.escape(alias)}\b", line) is not None


def normalize_items(text):
    found = defaultdict(float)
    evidence = {}
    detection = {}
    confidence_scores = defaultdict(list)
    unmatched_lines = []
    unmatched_predictions = []
    lines = [line.strip().lower() for line in text.splitlines() if line.strip()]

    for line in lines:
        if is_ignored_receipt_line(line):
            continue

        quantity = extract_quantity(line)
        deterministic_match = find_deterministic_food_match(line)

        if deterministic_match is not None:
            matched_name = deterministic_match["name"]
            found[matched_name] += quantity
            evidence.setdefault(matched_name, []).append(line)
            detection.setdefault(matched_name, deterministic_match["method"])
            confidence_scores[matched_name].append(current_ocr_confidence() * deterministic_match["confidence"])
            continue

        ml_match = predict_food_item(line)

        if ml_match is not None:
            matched_name = ml_match["name"]
            found[matched_name] += quantity
            evidence.setdefault(matched_name, []).append(line)
            detection.setdefault(matched_name, ml_match["method"])
            confidence_scores[matched_name].append(current_ocr_confidence() * ml_match["confidence"])
            continue

        unmatched_lines.append(line)
        category_prediction = predict_food_category(line)

        if category_prediction is not None:
            unmatched_predictions.append(category_prediction)

    return [
        {
            "name": name,
            "quantity": round(quantity, 2),
            "category": FOOD_GRAPH[name]["category"],
            "risk": FOOD_GRAPH[name]["risk"],
            "confidence": round(max(confidence_scores.get(name, [current_ocr_confidence() * 0.92])), 2),
            "confidence_label": confidence_label(max(confidence_scores.get(name, [current_ocr_confidence() * 0.92]))),
            "detection_method": detection.get(name, "rule_alias"),
            "raw_line": "; ".join(evidence.get(name, [])),
        }
        for name, quantity in found.items()
    ], unmatched_lines, unmatched_predictions


def calculate_nutrition(items, family_size):
    totals = {
        "sugar_g": 0.0,
        "saturated_fat_g": 0.0,
        "sodium_mg": 0.0,
        "fiber_g": 0.0,
    }
    categories = set()

    for item in items:
        data = FOOD_GRAPH[item["name"]]
        quantity = item["quantity"]
        categories.add(data["category"])

        for key in totals:
            totals[key] += data[key] * quantity

    per_person = {
        key: round(value / max(family_size, 1), 2)
        for key, value in totals.items()
    }
    per_person["nutrient_diversity"] = len(categories)
    return totals, per_person


def calculate_category_distribution(items):
    distribution = defaultdict(float)

    for item in items:
        distribution[item["category"]] += item["quantity"]

    return {
        category: round(quantity, 2)
        for category, quantity in sorted(distribution.items())
    }


def parse_health_notes(notes):
    text = (notes or "").lower()
    flags = []

    def add_flag(key, label, proof):
        if key not in {flag["key"] for flag in flags}:
            flags.append({"key": key, "label": label, "proof": proof})

    if any(word in text for word in ["pregnant", "pregnancy", "2 month", "2month", "month pregnant"]):
        add_flag("pregnancy", "Pregnancy context", "Health note mentions pregnancy or pregnancy months.")
    if any(phrase in text for phrase in ["one person", "1 person", "only one", "single person"]):
        add_flag("single_person", "Single-person receipt", "Health note says the receipt is for one person.")
    if any(word in text for word in ["child", "kid", "baby", "toddler"]):
        add_flag("child_context", "Child-focused receipt", "Health note mentions a child or baby.")
    if any(phrase in text for phrase in ["low salt", "low-salt", "less salt", "salt restriction"]):
        add_flag("low_salt", "Low-salt context", "Health note mentions a low-salt or salt-restricted diet.")
    if any(word in text for word in ["diabetic", "diabetes", "sugar patient"]):
        add_flag("diabetes_note", "Diabetes context", "Health note mentions diabetes or sugar restriction.")
    if any(phrase in text for phrase in ["gym", "muscle", "protein", "workout"]):
        add_flag("fitness", "Fitness context", "Health note mentions gym, protein, muscle, or workout goals.")
    if any(word in text for word in ["medicine", "medication", "doctor", "restriction"]):
        add_flag("medical_restriction", "Medical restriction note", "Health note mentions medicine, doctor advice, or restrictions.")

    return flags


def lower_is_better_score(value, target, high_risk):
    if value <= target:
        return 100.0
    if value >= high_risk:
        return 0.0
    return ((high_risk - value) / (high_risk - target)) * 100


def higher_is_better_score(value, target):
    return min(100.0, (value / target) * 100)


def calculate_score(per_person, age_group, conditions, context_flags=None):
    conditions = set(conditions)
    context_keys = {flag["key"] for flag in (context_flags or [])}
    adjustments = []

    weights = {
        "sugar": 35.0,
        "fat": 20.0,
        "sodium": 25.0,
        "fiber": 10.0,
        "diversity": 10.0,
    }

    if "diabetes" in conditions:
        weights["sugar"] += 10.0
        adjustments.append("Diabetes condition increases sugar sensitivity by 10 weight points.")
    if "diabetes_note" in context_keys:
        weights["sugar"] += 6.0
        adjustments.append("Health note mentions diabetes, so sugar sensitivity is increased by 6 weight points.")
    if "hypertension" in conditions:
        weights["sodium"] += 10.0
        adjustments.append("Hypertension condition increases sodium sensitivity by 10 weight points.")
    if "low_salt" in context_keys:
        weights["sodium"] += 6.0
        adjustments.append("Low-salt note increases sodium sensitivity by 6 weight points.")
    if "cholesterol" in conditions:
        weights["fat"] += 8.0
        adjustments.append("Cholesterol condition increases saturated-fat sensitivity by 8 weight points.")
    if age_group == "elderly":
        weights["sodium"] += 5.0
        weights["fat"] += 4.0
        adjustments.append("Elderly age group adds sodium and saturated-fat sensitivity.")
    if age_group == "children":
        weights["sugar"] += 5.0
        adjustments.append("Children age group adds sugar sensitivity.")
    if "child_context" in context_keys:
        weights["sugar"] += 4.0
        weights["fiber"] += 3.0
        adjustments.append("Child context increases sugar and fiber sensitivity.")
    if "pregnancy" in context_keys:
        weights["sugar"] += 3.0
        weights["sodium"] += 3.0
        weights["diversity"] += 4.0
        adjustments.append("Pregnancy context increases sugar, sodium, and diversity sensitivity.")

    breakdown = {
        "sugar": round(lower_is_better_score(per_person["sugar_g"], 25, 70), 1),
        "fat": round(lower_is_better_score(per_person["saturated_fat_g"], 10, 25), 1),
        "sodium": round(lower_is_better_score(per_person["sodium_mg"], 700, 2000), 1),
        "fiber": round(higher_is_better_score(per_person["fiber_g"], 10), 1),
        "diversity": round(higher_is_better_score(per_person["nutrient_diversity"], 6), 1),
    }

    total_weight = sum(weights.values())
    weighted_score = sum(breakdown[key] * weights[key] for key in breakdown) / total_weight
    score = max(0, min(100, round(weighted_score, 1)))

    if score >= 80:
        label = "Strong"
    elif score >= 65:
        label = "Moderate"
    elif score >= 45:
        label = "Needs attention"
    else:
        label = "High risk"

    return {
        "score": score,
        "label": label,
        "breakdown": breakdown,
        "weights": {key: round(value, 1) for key, value in weights.items()},
        "weight_adjustments": adjustments or ["No condition-specific scoring adjustments were applied."],
    }


def detect_anomalies(items):
    anomalies = []

    for item in items:
        baseline = ANOMALY_BASELINES.get(item["name"])
        if not baseline:
            continue

        std = baseline["std"] or 1
        z_score = (item["quantity"] - baseline["mean"]) / std

        if abs(z_score) >= 2:
            anomalies.append({
                "item": item["name"],
                "value": item["quantity"],
                "mean": baseline["mean"],
                "std_deviation": std,
                "z_score": round(z_score, 2),
                "message": f"Quantity is unusual compared with the demo baseline. Z-score: {round(z_score, 2)}",
            })

    return anomalies


def format_item_evidence(item):
    raw_line = item.get("raw_line") or "no raw receipt line stored"
    return (
        f"{item['name']} detected with quantity {item['quantity']} "
        f"({item['category']}, risk: {item['risk']}; receipt line: {raw_line})"
    )


def item_alternatives(items):
    alternatives = []

    for item in items:
        name = item["name"]
        replacements = SHOPPING_ALTERNATIVES.get(name)

        if not replacements:
            continue

        alternatives.append({
            "item": name,
            "risk": item["risk"],
            "quantity": item["quantity"],
            "alternatives": replacements,
            "budget_options": alternative_budget_details(replacements),
            "proof": format_item_evidence(item),
        })

    return alternatives


def top_item_names(items, candidates):
    names = {item["name"] for item in items}
    return [name for name in candidates if name in names]


def explain_score(score, items, per_person):
    breakdown = score.get("breakdown", {})
    weakest = sorted(breakdown.items(), key=lambda entry: entry[1])[:3]
    weak_labels = ", ".join(f"{name} ({value})" for name, value in weakest)
    sugar_items = top_item_names(items, ["soda", "juice", "cookies", "chocolate", "yogurt", "cereal"])
    sodium_items = top_item_names(items, ["chips", "noodles", "sausages", "sauce", "cheese"])
    fiber_items = top_item_names(items, ["vegetables", "apple", "banana", "orange", "oats", "beans"])
    reasons = []

    if per_person["sugar_g"] > 25:
        source = ", ".join(sugar_items) if sugar_items else "high-sugar items"
        reasons.append(f"Sugar is high at {per_person['sugar_g']} g per person, mainly linked to {source}.")
    if per_person["sodium_mg"] > 700:
        source = ", ".join(sodium_items) if sodium_items else "packaged salty items"
        reasons.append(f"Sodium is elevated at {per_person['sodium_mg']} mg per person, linked to {source}.")
    if per_person["fiber_g"] < 8:
        present = ", ".join(fiber_items) if fiber_items else "very few high-fiber foods"
        reasons.append(f"Fiber is low at {per_person['fiber_g']} g per person; current fiber sources are {present}.")
    if per_person["nutrient_diversity"] < 6:
        reasons.append(f"Food diversity is {per_person['nutrient_diversity']} groups, below the target of 6 groups.")

    if not reasons:
        reasons.append("The receipt is mostly within the demo targets; keep monitoring repeated packaged snacks.")

    return {
        "summary": f"Your score is {score['score']} ({score['label']}). The weakest components are {weak_labels}.",
        "reasons": reasons,
        "weakest_components": [
            {"component": name, "score": value}
            for name, value in weakest
        ],
    }


def build_priority_alerts(items, per_person, score, anomalies, context_flags):
    alerts = []
    context_keys = {flag["key"] for flag in context_flags}

    def add(priority, title, detail, proof):
        alerts.append({
            "priority": priority,
            "title": title,
            "detail": detail,
            "proof": proof,
        })

    if per_person["sugar_g"] > 25:
        add(
            "Fix first",
            "Reduce sugary drinks and sweet snacks",
            "Sugar is the most urgent score driver for this receipt.",
            f"Sugar is {per_person['sugar_g']} g per person and target is 25 g or less.",
        )
    if per_person["sodium_mg"] > 700 or "low_salt" in context_keys:
        add(
            "Fix first" if per_person["sodium_mg"] > 700 else "Watch",
            "Choose lower-sodium packaged foods",
            "Low-salt or sodium-sensitive context makes salty items more important.",
            f"Sodium is {per_person['sodium_mg']} mg per person.",
        )
    if anomalies:
        add(
            "Watch",
            "Review unusual quantities",
            "One or more items are far above the demo quantity baseline.",
            "; ".join(anomaly["message"] for anomaly in anomalies[:2]),
        )
    if per_person["fiber_g"] < 8:
        add(
            "Watch",
            "Add fiber sources",
            "More vegetables, fruit, oats, beans, or whole grains would improve the score.",
            f"Fiber is {per_person['fiber_g']} g per person and target is at least 8 g.",
        )
    if score["score"] >= 75 and not alerts:
        add(
            "Good habit",
            "Keep the current pattern",
            "This receipt is close to the demo model targets.",
            f"Score is {score['score']} with no urgent nutrient alerts.",
        )

    if not alerts:
        add(
            "Good habit",
            "Maintain variety",
            "No urgent alert was triggered, but future receipts should keep food groups diverse.",
            f"Nutrient diversity is {per_person['nutrient_diversity']} group(s).",
        )

    priority_order = {"Fix first": 0, "Watch": 1, "Good habit": 2}
    return sorted(alerts, key=lambda alert: priority_order.get(alert["priority"], 9))


def generate_recommendations(items, per_person, conditions, context_flags):
    recommendation_cards = []
    names = {item["name"] for item in items}
    item_map = {item["name"]: item for item in items}
    categories_by_name = {item["name"]: item.get("category", "") for item in items}
    risks_by_name = {item["name"]: item.get("risk", "") for item in items}
    conditions = set(conditions)
    context_keys = {flag["key"] for flag in context_flags}

    def names_with_category(category):
        return [
            name
            for name, item_category in categories_by_name.items()
            if item_category == category
        ]

    def names_with_risk_text(term):
        return [
            name
            for name, risk_text in risks_by_name.items()
            if term in str(risk_text).lower()
        ]

    def add_recommendation(advice, trigger, proof_points, item_names=None, alternatives=None):
        evidence_items = [
            format_item_evidence(item_map[name])
            for name in (item_names or [])
            if name in item_map
        ]
        recommendation_cards.append({
            "advice": advice,
            "trigger": trigger,
            "proof_points": proof_points,
            "evidence_items": evidence_items,
            "alternatives": alternatives or [],
            "budget_alternatives": [
                {
                    "replace": alternative.get("replace", ""),
                    "with": alternative_budget_details(alternative.get("with", [])),
                }
                for alternative in (alternatives or [])
                if isinstance(alternative, dict)
            ],
        })

    if per_person["sugar_g"] > 25:
        sugar_items = [
            name for name in ["soda", "juice", "cookies", "chocolate", "yogurt", "cereal"]
            if name in names
        ]
        add_recommendation(
            "Sugar per person is high. Reduce soda, sweet snacks, and sweetened dairy.",
            "Nutrient threshold",
            [
                f"Sugar is {per_person['sugar_g']} g per person.",
                "Target is 25 g or less per person for this scoring model.",
                "The sugar component lowers the final health score when this value rises.",
            ],
            sugar_items,
            [
                {"replace": name, "with": SHOPPING_ALTERNATIVES[name]}
                for name in sugar_items
                if name in SHOPPING_ALTERNATIVES
            ],
        )
    if per_person["sodium_mg"] > 700:
        sodium_items = sorted(set(
            name for name in [*names_with_risk_text("sodium"), "chips", "noodles", "sausages", "sauce", "cheese"]
            if name in names
        ))
        add_recommendation(
            "Sodium is elevated. Limit chips and processed packaged foods.",
            "Nutrient threshold",
            [
                f"Sodium is {per_person['sodium_mg']} mg per person.",
                "Target is 700 mg or less per person for this scoring model.",
                "High-sodium packaged items increase hypertension-related risk.",
            ],
            sodium_items,
            [
                {"replace": name, "with": SHOPPING_ALTERNATIVES[name]}
                for name in sodium_items
                if name in SHOPPING_ALTERNATIVES
            ],
        )
    if per_person["fiber_g"] < 8:
        fiber_items = [
            name for name in ["vegetables", "apple", "banana", "orange", "oats", "beans", *names_with_category("vegetable")]
            if name in names
        ]
        add_recommendation(
            "Fiber is low. Add vegetables, fruits, oats, beans, or whole grains.",
            "Nutrient threshold",
            [
                f"Fiber is {per_person['fiber_g']} g per person.",
                "Target is at least 8 g per person for a safer receipt pattern.",
                "Low fiber reduces the fiber component of the health score.",
            ],
            sorted(set(fiber_items)),
        )
    if "diabetes" in conditions and "soda" in names:
        add_recommendation(
            "For diabetes risk, soda is the first item to reduce or replace.",
            "Condition and item match",
            [
                "Diabetes risk was selected in the family conditions.",
                "Soda was detected in the normalized receipt items.",
                "Soda is mapped as a high-sugar item in the nutrition graph.",
            ],
            ["soda"],
            [{"replace": "soda", "with": SHOPPING_ALTERNATIVES["soda"]}],
        )
    if "hypertension" in conditions and "chips" in names:
        add_recommendation(
            "For hypertension, choose lower-sodium snacks instead of chips.",
            "Condition and item match",
            [
                "Hypertension was selected in the family conditions.",
                "Chips were detected in the normalized receipt items.",
                "Chips are mapped as a high-sodium snack in the nutrition graph.",
            ],
            ["chips"],
            [{"replace": "chips", "with": SHOPPING_ALTERNATIVES["chips"]}],
        )
    if not names_with_category("vegetable"):
        add_recommendation(
            "Add at least one vegetable item to improve nutrient diversity.",
            "Missing food group",
            [
                "No vegetable item was detected in this receipt.",
                f"Nutrient diversity is {per_person['nutrient_diversity']} food group(s).",
                "The model rewards more food-category diversity.",
            ],
        )
    if "noodles" in names:
        add_recommendation(
            "Instant noodles are high in sodium. Reduce frequency or pair with vegetables.",
            "Item risk rule",
            [
                "Instant noodles were detected in the receipt.",
                "The knowledge graph maps noodles to high sodium instant food.",
                "Pairing with vegetables improves diversity but does not remove the sodium risk.",
            ],
            ["noodles"],
            [{"replace": "noodles", "with": SHOPPING_ALTERNATIVES["noodles"]}],
        )
    processed_meat_items = sorted(set([*names_with_category("processed meat"), *([ "sausages" ] if "sausages" in names else [])]))
    if processed_meat_items:
        add_recommendation(
            "Processed meats increase sodium and saturated fat exposure. Prefer fresh protein.",
            "Item risk rule",
            [
                "Processed meat was detected in the receipt.",
                "The knowledge graph maps processed meat to sodium and saturated fat risk.",
                "Processed meat also contributes saturated fat exposure.",
            ],
            processed_meat_items,
            [
                {"replace": name, "with": SHOPPING_ALTERNATIVES.get(name, SHOPPING_ALTERNATIVES["sausages"])}
                for name in processed_meat_items
            ],
        )
    if {"cookies", "chocolate"} & names:
        sweet_items = [name for name in ["cookies", "chocolate"] if name in names]
        add_recommendation(
            "Sweet snacks are present. Set a weekly limit and replace some with fruit or nuts.",
            "Item risk rule",
            [
                "Sweet snack items were detected in the receipt.",
                "Cookies and chocolate are mapped as high-sugar or high-sugar/high-fat foods.",
                "Replacing some portions with fruit or nuts improves fiber and nutrient quality.",
            ],
            sweet_items,
            [
                {"replace": name, "with": SHOPPING_ALTERNATIVES[name]}
                for name in sweet_items
                if name in SHOPPING_ALTERNATIVES
            ],
        )

    if "pregnancy" in context_keys and ({"soda", "chips", "noodles", "sausages"} & names):
        risky_items = [name for name in ["soda", "chips", "noodles", "sausages"] if name in names]
        add_recommendation(
            "Pregnancy context is noted. Prioritize nutrient-dense foods and reduce high-sugar or high-sodium packaged items.",
            "User context proof",
            [
                "The receipt context note mentions pregnancy.",
                "High-sugar or high-sodium packaged items were detected.",
                "This is nutrition guidance only, not medical diagnosis.",
            ],
            risky_items,
        )

    if "single_person" in context_keys:
        add_recommendation(
            "The note says this receipt is for one person. Keep family members set to 1 for accurate per-person scoring.",
            "User context proof",
            [
                "The receipt context note says only one person.",
                "Per-person nutrition is calculated by dividing receipt totals by family size.",
                "Wrong family size can make sugar, sodium, and fat risk look too low or too high.",
            ],
        )

    if "low_salt" in context_keys and (per_person["sodium_mg"] > 500 or {"chips", "noodles", "sausages", "sauce"} & names or names_with_risk_text("sodium")):
        sodium_items = sorted(set(
            name for name in [*names_with_risk_text("sodium"), "chips", "noodles", "sausages", "sauce"]
            if name in names
        ))
        add_recommendation(
            "Low-salt context is noted. Choose lower-sodium alternatives for salty packaged foods.",
            "User context proof",
            [
                "The receipt context note mentions a low-salt diet.",
                f"Sodium is {per_person['sodium_mg']} mg per person.",
                "Packaged snacks, sauces, instant foods, and processed meats are checked as sodium sources.",
            ],
            sodium_items,
        )

    if "diabetes_note" in context_keys and (per_person["sugar_g"] > 20 or {"soda", "juice", "cookies", "chocolate"} & names):
        sugar_items = [name for name in ["soda", "juice", "cookies", "chocolate"] if name in names]
        add_recommendation(
            "Diabetes context is noted. Reduce sugary drinks and sweet snacks first.",
            "User context proof",
            [
                "The receipt context note mentions diabetes or sugar restriction.",
                f"Sugar is {per_person['sugar_g']} g per person.",
                "Sugary drinks and sweet snacks are prioritized because they quickly raise sugar exposure.",
            ],
            sugar_items,
        )

    if "fitness" in context_keys and not ({"chicken", "fish", "beans", "nuts"} & names):
        add_recommendation(
            "Fitness context is noted. Add a protein source such as chicken, fish, beans, or nuts.",
            "User context proof",
            [
                "The receipt context note mentions gym, workout, protein, or muscle goals.",
                "No strong protein-focused item was detected in the normalized receipt items.",
                "Protein quality supports fitness-focused shopping goals.",
            ],
        )

    if not recommendation_cards:
        add_recommendation(
            "This receipt has a balanced pattern. Keep variety high and watch packaged snacks.",
            "Balanced pattern",
            [
                f"Sugar is {per_person['sugar_g']} g, sodium is {per_person['sodium_mg']} mg, and fiber is {per_person['fiber_g']} g per person.",
                "No rule-based high-risk recommendation was triggered.",
                "Continue monitoring packaged snacks and food diversity across future receipts.",
            ],
        )

    return recommendation_cards


def summarize_risks(items, per_person, anomalies):
    high_risk_items = [
        item["name"]
        for item in items
        if "high" in item["risk"] or "processed" in item["risk"] or "sugar" in item["risk"]
    ]

    return {
        "high_risk_item_count": len(high_risk_items),
        "high_risk_items": high_risk_items,
        "sugar_alert": per_person["sugar_g"] > 25,
        "sodium_alert": per_person["sodium_mg"] > 700,
        "fiber_alert": per_person["fiber_g"] < 8,
        "anomaly_count": len(anomalies),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--family-size", type=int, default=1)
    parser.add_argument("--age-group", default="adult")
    parser.add_argument("--conditions", default="")
    parser.add_argument("--health-notes", default="")
    args = parser.parse_args()

    conditions = [
        condition.strip()
        for condition in args.conditions.split(",")
        if condition.strip() and condition.strip() != "none"
    ]

    extracted_text = extract_text(args.input)
    load_food_classifier()
    items, unmatched_lines, unmatched_predictions = normalize_items(extracted_text)
    totals, per_person = calculate_nutrition(items, args.family_size)
    context_flags = parse_health_notes(args.health_notes)
    score = calculate_score(per_person, args.age_group, conditions, context_flags)
    anomalies = detect_anomalies(items)
    recommendation_proofs = generate_recommendations(items, per_person, conditions, context_flags)
    recommendations = [recommendation["advice"] for recommendation in recommendation_proofs]
    category_distribution = calculate_category_distribution(items)
    risk_summary = summarize_risks(items, per_person, anomalies)
    alternatives = item_alternatives(items)
    score_explanation = explain_score(score, items, per_person)
    priority_alerts = build_priority_alerts(items, per_person, score, anomalies, context_flags)

    result = {
        "family": {
            "family_size": args.family_size,
            "age_group": args.age_group,
            "conditions": conditions,
        },
        "extracted_text": extracted_text.strip(),
        "receipt_metadata": extract_receipt_metadata(extracted_text),
        "ocr_status": OCR_STATUS,
        "health_note_analysis": {
            "raw_notes": args.health_notes,
            "flags": context_flags,
        },
        "items": items,
        "unmatched_lines": unmatched_lines,
        "unmatched_line_predictions": unmatched_predictions,
        "category_distribution": category_distribution,
        "totals_nutrition": {
            key: round(value, 2)
            for key, value in totals.items()
        },
        "per_person_nutrition": per_person,
        "health_score": score,
        "score_explanation": score_explanation,
        "priority_alerts": priority_alerts,
        "trend": {
            "status": "not_enough_history",
            "message": "Upload more receipts to calculate weekly and monthly trends.",
        },
        "anomalies": anomalies,
        "risk_summary": risk_summary,
        "ml_classification": ML_CLASSIFIER_STATUS,
        "recommendations": recommendations,
        "recommendation_proofs": recommendation_proofs,
        "shopping_alternatives": alternatives,
        "models": {
            "item_classification": "Character n-gram Naive Bayes ML classifier is used only for high-confidence unmatched receipt lines when a trained model is available.",
            "category_fallback": "Lower-confidence ML output is shown only as a suggested category and is not scored as a food item.",
            "scoring": "Weighted component model using sugar, saturated fat, sodium, fiber, and diversity.",
            "normalization": "Receipt nutrient totals are divided by family size.",
            "anomaly_detection": "Z = (X - mean) / standard deviation; absolute values >= 2 are flagged.",
            "recommendation_engine": "Rule-based explainable advice generated from nutrient thresholds, conditions, and item categories.",
        },
        "pipeline_layers": [
            "OCR extraction",
            "NLP normalization",
            "ML food item classification",
            "Nutrition knowledge graph",
            "Weighted health scoring",
            "Time-series trend analysis",
            "Z-score anomaly detection",
            "Explainable recommendation engine",
        ],
    }

    print(json.dumps(result))


if __name__ == "__main__":
    main()
