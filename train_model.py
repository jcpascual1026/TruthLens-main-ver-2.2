import pandas as pd
from sklearn.model_selection import train_test_split
from sklearn.feature_extraction.text import TfidfVectorizer
from sklearn.linear_model import LogisticRegression
from sklearn.pipeline import Pipeline
import joblib
import re
import nltk
from nltk.corpus import stopwords

# Download stopwords if not already
nltk.download('stopwords')
stop_words = set(stopwords.words('english'))

def preprocess(text):
    """
    Preprocess the text: lowercase, remove punctuation, remove stopwords.
    """
    text = text.lower()
    text = re.sub(r'[^\w\s]', '', text)
    text = ' '.join([word for word in text.split() if word not in stop_words])
    return text

# Load dataset (assume fake_news.csv with columns: text, label (0=FAKE, 1=REAL))
df = pd.read_csv('fake_news.csv')
df['text'] = df['text'].apply(preprocess)

X = df['text']
y = df['label']

# Split data
X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2, random_state=42)

# Create pipeline: TF-IDF + Logistic Regression
pipeline = Pipeline([
    ('tfidf', TfidfVectorizer(max_features=5000, ngram_range=(1, 2))),  # Include bigrams for better features
    ('clf', LogisticRegression(random_state=42))
])

# Train the model
pipeline.fit(X_train, y_train)

# Evaluate on test set (optional, for logging)
accuracy = pipeline.score(X_test, y_test)
print(f"Model accuracy on test set: {accuracy:.2f}")

# Save the trained model
joblib.dump(pipeline, 'model.pkl')
print("Model saved as model.pkl")