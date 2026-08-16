import math
import re
from collections import Counter, defaultdict


class FoodItemClassifier:
    def __init__(self, min_ngram=3, max_ngram=5, alpha=1.0):
        self.min_ngram = min_ngram
        self.max_ngram = max_ngram
        self.alpha = alpha
        self.labels = []
        self.class_counts = Counter()
        self.feature_counts = defaultdict(Counter)
        self.feature_totals = Counter()
        self.vocabulary = set()
        self.total_rows = 0

    def clean_text(self, value):
        text = str(value).lower()
        text = re.sub(r"[^a-z0-9 ]+", " ", text)
        return re.sub(r"\s+", " ", text).strip()

    def features(self, value):
        text = f" {self.clean_text(value)} "
        counts = Counter()

        for size in range(self.min_ngram, self.max_ngram + 1):
            for index in range(0, max(0, len(text) - size + 1)):
                counts[text[index:index + size]] += 1

        for token in text.split():
            counts[f"word:{token}"] += 2

        return counts

    def fit(self, rows):
        self.class_counts.clear()
        self.feature_counts.clear()
        self.feature_totals.clear()
        self.vocabulary.clear()
        self.total_rows = 0

        for text, label in rows:
            clean_label = self.clean_text(label)

            if not text or not clean_label:
                continue

            self.class_counts[clean_label] += 1
            self.total_rows += 1
            features = self.features(text)

            for feature, count in features.items():
                self.feature_counts[clean_label][feature] += count
                self.feature_totals[clean_label] += count
                self.vocabulary.add(feature)

        self.labels = sorted(self.class_counts)
        return self

    def _label_score(self, label, features):
        vocabulary_size = max(1, len(self.vocabulary))
        class_total = max(1, self.feature_totals[label])
        score = math.log((self.class_counts[label] + self.alpha) / (self.total_rows + self.alpha * max(1, len(self.labels))))

        for feature, count in features.items():
            feature_total = self.feature_counts[label][feature]
            probability = (feature_total + self.alpha) / (class_total + self.alpha * vocabulary_size)
            score += count * math.log(probability)

        return score

    def predict_one(self, value):
        if not self.labels:
            return "", 0.0

        features = self.features(value)
        scores = {
            label: self._label_score(label, features)
            for label in self.labels
        }
        best_label = max(scores, key=scores.get)
        max_score = scores[best_label]
        exp_scores = {
            label: math.exp(score - max_score)
            for label, score in scores.items()
        }
        total = sum(exp_scores.values()) or 1.0
        confidence = exp_scores[best_label] / total
        return best_label, confidence

    def predict(self, values):
        return [self.predict_one(value)[0] for value in values]
