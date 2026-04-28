import json
import os
from fastapi import FastAPI, File, UploadFile, HTTPException
from fastapi.middleware.cors import CORSMiddleware
import joblib
import requests
from bs4 import BeautifulSoup
import pytesseract
from PIL import Image
import io
import re
from urllib.parse import urlparse
import nltk
from nltk.corpus import stopwords

# Download NLTK data
nltk.download('stopwords')
stop_words = set(stopwords.words('english'))

# Load domain credibility lists from JSON config
config_path = os.path.join(os.path.dirname(__file__), 'trusted_domains.json')
domain_config = {'trusted': [], 'suspicious': []}
if os.path.exists(config_path):
    with open(config_path, 'r', encoding='utf-8') as f:
        domain_config = json.load(f)

trusted_domains = domain_config.get('trusted', [])
suspicious_domains = domain_config.get('suspicious', [])

def check_domain(domain):
    """
    Check if domain is trusted, suspicious, or unknown.
    """
    domain = domain.lower().replace('www.', '')
    social_domains = ['facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'tiktok.com', 'linkedin.com', 'youtube.com']
    if domain in trusted_domains:
        return 'trusted', 'This domain is from a well-known and trusted news source.'
    elif domain in suspicious_domains:
        return 'suspicious', 'This domain is known for publishing unreliable or fake news.'
    elif domain in social_domains:
        return 'social_media', 'This is a social media platform. Content credibility depends on the source and poster.'
    else:
        return 'unknown', 'This domain is not in our trusted or suspicious lists. Exercise caution.'

# Clickbait and sensational words
clickbait_words = ['shocking', 'viral', 'secret', 'exposed', 'unbelievable', 'breaking', 'urgent', 'must see', 'you wont believe', 'scandal', 'conspiracy']

def generate_detailed_reasons(result, confidence, important_words, domain_status, text):
    """
    Generate a list of detailed reasons for the classification.
    """
    reasons = []

    # Confidence reason
    if confidence >= 0.8:
        reasons.append("High confidence in the classification based on text patterns.")
    elif confidence >= 0.6:
        reasons.append("Moderate confidence; the result is reasonably certain.")
    else:
        reasons.append("Low confidence; this content may not be clear news or the model is uncertain.")

    # Domain reason
    if domain_status == 'trusted':
        reasons.append("The source domain is from a trusted news organization.")
    elif domain_status == 'suspicious':
        reasons.append("The source domain is known for unreliable or fake content.")
    elif domain_status == 'social_media':
        reasons.append("This is from a social media platform; credibility depends on the original poster.")
    else:
        reasons.append("The domain is unknown; exercise caution with unfamiliar sources.")

    # Word-based reasons
    clickbait_found = [word for word in important_words if word in clickbait_words]
    if clickbait_found:
        reasons.append(f"Contains sensational or clickbait words: {', '.join(clickbait_found)}.")

    # Content type check
    news_keywords = ['report', 'according', 'said', 'announced', 'government', 'president', 'minister', 'official', 'source']
    news_count = sum(1 for word in text.split() if word.lower() in news_keywords)
    if news_count < 2:
        reasons.append("The content lacks typical news reporting keywords, suggesting it may not be factual news.")

    # Result-specific
    if result == 'FAKE':
        reasons.append("Overall patterns indicate potential misinformation or biased content.")
    elif result == 'REAL':
        reasons.append("The content follows patterns of reliable, factual reporting.")
    else:
        reasons.append("Uncertainty suggests the content doesn't strongly match either category.")

    return reasons

def preprocess(text):
    """
    Preprocess the text: lowercase, remove punctuation, remove stopwords.
    """
    text = text.lower()
    text = re.sub(r'[^\w\s]', '', text)
    text = ' '.join([word for word in text.split() if word not in stop_words])
    return text

def scrape_text(url):
    """
    Scrape text from URL.
    """
    try:
        response = requests.get(url, timeout=10, headers={'User-Agent': 'Mozilla/5.0'})
        response.raise_for_status()
        soup = BeautifulSoup(response.text, 'html.parser')
        # Extract text from paragraphs
        paragraphs = soup.find_all('p')
        text = ' '.join([p.get_text() for p in paragraphs if p.get_text().strip()])
        return text[:3000]  # Limit to 3000 characters
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Could not scrape the URL: {str(e)}")

def extract_text_from_image_url(image_url):
    """
    Download image from URL and extract text using OCR.
    """
    try:
        response = requests.get(image_url, timeout=10)
        response.raise_for_status()
        img = Image.open(io.BytesIO(response.content))
        text = pytesseract.image_to_string(img)
        return text[:3000]  # Limit
    except Exception as e:
        raise HTTPException(status_code=400, detail=f"Could not extract text from image URL: {str(e)}")

def scrape_social_text(url):
    """
    Special scraping for social media URLs.
    For now, use general scraping, but can be extended.
    """
    # For Facebook, Twitter, etc., this is basic. In production, use APIs or Selenium.
    return scrape_text(url)

# Load the trained model
try:
    model = joblib.load('model.pkl')
    vectorizer = model.named_steps['tfidf']
    clf = model.named_steps['clf']
except FileNotFoundError:
    raise Exception("Model file 'model.pkl' not found. Please run train_model.py first.")

app = FastAPI(title="Fake News Detection API", description="API for detecting fake news with explainability.")

# Enable CORS
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

@app.post("/predict")
def predict(data: dict):
    """
    Predict if text, URL, or image URL content is REAL or FAKE.
    Supports news websites and social media links (Facebook, Twitter, Instagram, etc.).
    Input: {"text": "news content"} or {"url": "https://example.com"} or {"image_url": "https://example.com/image.jpg"}
    """
    text = data.get('text')
    url = data.get('url')
    image_url = data.get('image_url')
    domain_status = None
    domain_reason = None

    if not text and not url and not image_url:
        raise HTTPException(status_code=400, detail="Provide 'text', 'url', or 'image_url'")

    if url:
        domain = urlparse(url).netloc.lower().replace('www.', '')
        domain_status, domain_reason = check_domain(domain)
        if domain in ['facebook.com', 'twitter.com', 'x.com', 'instagram.com']:
            text = scrape_social_text(url)
        else:
            text = scrape_text(url)
    elif image_url:
        text = extract_text_from_image_url(image_url)
        domain = urlparse(image_url).netloc.lower().replace('www.', '')
        domain_status, domain_reason = check_domain(domain)

    if not text or not text.strip():
        raise HTTPException(status_code=400, detail="No text to analyze")

    # Preprocess
    text_proc = preprocess(text)

    # Predict
    prob = model.predict_proba([text_proc])[0]
    pred = model.predict([text_proc])[0]
    confidence = max(prob)
    result = 'REAL' if pred == 1 else 'FAKE'
    if confidence < 0.6:
        result = 'UNCERTAIN'

    # Get important words (top 10 features with highest coefficients)
    feature_names = vectorizer.get_feature_names_out()
    coef = clf.coef_[0]
    top_indices = coef.argsort()[-10:][::-1]  # Top positive coefficients
    important_words = [feature_names[i] for i in top_indices]

    # Explanation
    explanation = f"The model classified this as {result} with {confidence:.2f} confidence."
    if domain_status:
        explanation += f" Domain status: {domain_status} - {domain_reason}."
    if result == 'FAKE':
        explanation += " Indicators include potential clickbait language and source credibility."
    elif result == 'REAL':
        explanation += " The content appears balanced and from a reliable source."

    # Detailed reasons
    detailed_reasons = generate_detailed_reasons(result, confidence, important_words, domain_status, text)

    return {
        "result": result,
        "confidence": round(confidence, 2),
        "important_words": important_words,
        "domain_status": domain_status,
        "explanation": explanation,
        "detailed_reasons": detailed_reasons
    }

@app.post("/predict-image")
def predict_image(file: UploadFile = File(...)):
    """
    Predict from image containing text.
    Input: image file
    """
    if not file.content_type.startswith('image/'):
        raise HTTPException(status_code=400, detail="File must be an image")

    image_bytes = file.file.read()
    text = extract_text_from_image(image_bytes)

    if not text.strip():
        raise HTTPException(status_code=400, detail="No text extracted from image")

    # Same prediction logic
    text_proc = preprocess(text)
    prob = model.predict_proba([text_proc])[0]
    pred = model.predict([text_proc])[0]
    confidence = max(prob)
    result = 'REAL' if pred == 1 else 'FAKE'
    if confidence < 0.6:
        result = 'UNCERTAIN'

    feature_names = vectorizer.get_feature_names_out()
    coef = clf.coef_[0]
    top_indices = coef.argsort()[-10:][::-1]
    important_words = [feature_names[i] for i in top_indices]

    explanation = f"Extracted text classified as {result} with {confidence:.2f} confidence."

    # Detailed reasons
    detailed_reasons = generate_detailed_reasons(result, confidence, important_words, None, text)

    return {
        "result": result,
        "confidence": round(confidence, 2),
        "important_words": important_words,
        "extracted_text": text[:500],  # Preview
        "explanation": explanation,
        "detailed_reasons": detailed_reasons
    }

@app.post("/check-domain")
def check_domain_endpoint(data: dict):
    """
    Check domain credibility.
    Input: {"domain": "example.com"} or {"domain": "https://example.com"}
    """
    domain_or_url = data.get('domain')
    if not domain_or_url:
        raise HTTPException(status_code=400, detail="Provide 'domain'")

    domain = urlparse(domain_or_url).netloc or domain_or_url
    domain = domain.lower().replace('www.', '')
    status, reason = check_domain(domain)

    return {
        "domain_status": status,
        "reason": reason
    }