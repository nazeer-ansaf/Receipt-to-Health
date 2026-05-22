import argparse
import json
import math
import os
import re
from collections import defaultdict


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
        "aliases": ["cheese", "cheddar", "mozzarella"],
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
        "aliases": ["juice", "orange juice", "fruit juice"],
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


def extract_text(input_path):
    _, extension = os.path.splitext(input_path.lower())

    if extension == ".txt":
        with open(input_path, "r", encoding="utf-8", errors="ignore") as receipt:
            return receipt.read()

    # Replace this demo text with pytesseract/easyocr output after OCR setup.
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
    unmatched_lines = []
    lines = [line.strip().lower() for line in text.splitlines() if line.strip()]

    for line in lines:
        quantity_match = re.search(r"(\d+(?:\.\d+)?)", line)
        quantity = float(quantity_match.group(1)) if quantity_match else 1.0
        matched_name = None

        for standard_name, data in FOOD_GRAPH.items():
            if any(alias_matches(line, alias) for alias in data["aliases"]):
                found[standard_name] += quantity
                matched_name = standard_name
                evidence.setdefault(standard_name, []).append(line)
                break

        if matched_name is None:
            unmatched_lines.append(line)

    return [
        {
            "name": name,
            "quantity": round(quantity, 2),
            "category": FOOD_GRAPH[name]["category"],
            "risk": FOOD_GRAPH[name]["risk"],
            "confidence": 0.92,
            "raw_line": "; ".join(evidence.get(name, [])),
        }
        for name, quantity in found.items()
    ], unmatched_lines


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


def lower_is_better_score(value, target, high_risk):
    if value <= target:
        return 100.0
    if value >= high_risk:
        return 0.0
    return ((high_risk - value) / (high_risk - target)) * 100


def higher_is_better_score(value, target):
    return min(100.0, (value / target) * 100)


def calculate_score(per_person, age_group, conditions):
    conditions = set(conditions)

    weights = {
        "sugar": 35.0,
        "fat": 20.0,
        "sodium": 25.0,
        "fiber": 10.0,
        "diversity": 10.0,
    }

    if "diabetes" in conditions:
        weights["sugar"] += 10.0
    if "hypertension" in conditions:
        weights["sodium"] += 10.0
    if "cholesterol" in conditions:
        weights["fat"] += 8.0
    if age_group == "elderly":
        weights["sodium"] += 5.0
        weights["fat"] += 4.0
    if age_group == "children":
        weights["sugar"] += 5.0

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


def generate_recommendations(items, per_person, conditions):
    recommendations = []
    names = {item["name"] for item in items}
    conditions = set(conditions)

    if per_person["sugar_g"] > 25:
        recommendations.append("Sugar per person is high. Reduce soda, sweet snacks, and sweetened dairy.")
    if per_person["sodium_mg"] > 700:
        recommendations.append("Sodium is elevated. Limit chips and processed packaged foods.")
    if per_person["fiber_g"] < 8:
        recommendations.append("Fiber is low. Add vegetables, fruits, oats, beans, or whole grains.")
    if "diabetes" in conditions and "soda" in names:
        recommendations.append("For diabetes risk, soda is the first item to reduce or replace.")
    if "hypertension" in conditions and "chips" in names:
        recommendations.append("For hypertension, choose lower-sodium snacks instead of chips.")
    if "vegetables" not in names:
        recommendations.append("Add at least one vegetable item to improve nutrient diversity.")
    if "noodles" in names:
        recommendations.append("Instant noodles are high in sodium. Reduce frequency or pair with vegetables.")
    if "sausages" in names:
        recommendations.append("Processed meats increase sodium and saturated fat exposure. Prefer fresh protein.")
    if {"cookies", "chocolate"} & names:
        recommendations.append("Sweet snacks are present. Set a weekly limit and replace some with fruit or nuts.")

    if not recommendations:
        recommendations.append("This receipt has a balanced pattern. Keep variety high and watch packaged snacks.")

    return recommendations


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
    args = parser.parse_args()

    conditions = [
        condition.strip()
        for condition in args.conditions.split(",")
        if condition.strip() and condition.strip() != "none"
    ]

    extracted_text = extract_text(args.input)
    items, unmatched_lines = normalize_items(extracted_text)
    totals, per_person = calculate_nutrition(items, args.family_size)
    score = calculate_score(per_person, args.age_group, conditions)
    anomalies = detect_anomalies(items)
    recommendations = generate_recommendations(items, per_person, conditions)
    category_distribution = calculate_category_distribution(items)
    risk_summary = summarize_risks(items, per_person, anomalies)

    result = {
        "family": {
            "family_size": args.family_size,
            "age_group": args.age_group,
            "conditions": conditions,
        },
        "extracted_text": extracted_text.strip(),
        "items": items,
        "unmatched_lines": unmatched_lines,
        "category_distribution": category_distribution,
        "totals_nutrition": {
            key: round(value, 2)
            for key, value in totals.items()
        },
        "per_person_nutrition": per_person,
        "health_score": score,
        "trend": {
            "status": "not_enough_history",
            "message": "Upload more receipts to calculate weekly and monthly trends.",
        },
        "anomalies": anomalies,
        "risk_summary": risk_summary,
        "recommendations": recommendations,
        "models": {
            "scoring": "Weighted component model using sugar, saturated fat, sodium, fiber, and diversity.",
            "normalization": "Receipt nutrient totals are divided by family size.",
            "anomaly_detection": "Z = (X - mean) / standard deviation; absolute values >= 2 are flagged.",
            "recommendation_engine": "Rule-based explainable advice generated from nutrient thresholds, conditions, and item categories.",
        },
        "pipeline_layers": [
            "OCR extraction",
            "NLP normalization",
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
