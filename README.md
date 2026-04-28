# Fake News Detection API

This is a complete Fake News Detection API built with FastAPI and scikit-learn. It classifies news as REAL or FAKE, provides explanations, and checks domain credibility.

## Features

- Text classification using TF-IDF and Logistic Regression
- URL scraping and domain credibility check (including social media)
- Image text extraction via OCR (from files or URLs)
- Explainable predictions with important words
- CORS enabled for frontend integration
- Support for social media links (Facebook, Twitter, Instagram, etc.)

## Setup

### 1. Install Dependencies

```bash
pip install -r requirements.txt
```

### 2. Install Tesseract OCR (for image processing)

- Download from: https://github.com/UB-Mannheim/tesseract/wiki
- Add to PATH or set TESSDATA_PREFIX if needed

### 3. Prepare Dataset

Download the Fake News Dataset from Kaggle: https://www.kaggle.com/datasets/clmentbisaillon/fake-and-real-news-dataset

- It has two files: Fake.csv and True.csv
- Combine them into fake_news.csv with columns: text, label (0 for Fake, 1 for True)

Example script to prepare:

```python
import pandas as pd

fake = pd.read_csv('Fake.csv')
true = pd.read_csv('True.csv')

fake['label'] = 0
true['label'] = 1

df = pd.concat([fake[['text', 'label']], true[['text', 'label']]], ignore_index=True)
df.to_csv('fake_news.csv', index=False)
```

### 4. Train the Model

```bash
python train_model.py
```

This will create `model.pkl`.

### 5. Run the API

```bash
uvicorn app:app --reload
```

API will be available at http://127.0.0.1:8000

Visit http://127.0.0.1:8000/docs for interactive API documentation.

## API Endpoints

### POST /predict
- Input: `{"text": "news content"}` or `{"url": "https://example.com"}` or `{"image_url": "https://example.com/image.jpg"}`
- Output: result, confidence, important_words, domain_status, explanation, **detailed_reasons** (list of specific factors)
- Supports news websites and social media links

### POST /predict-image
- Input: image file
- Output: result, confidence, important_words, extracted_text, explanation, **detailed_reasons**
- Analyzes text extracted from images

### POST /check-domain
- Input: `{"domain": "example.com"}`
- Output: domain_status, reason

## Frontend Integration Example

See `example.html` for a simple JavaScript example.

## Notes

- Model accuracy depends on the dataset
- Results are probabilistic, not absolute
- For production, consider security, rate limiting, and model updates