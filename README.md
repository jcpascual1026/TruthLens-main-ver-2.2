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

## Frontend Integration

The `analyze.html` page integrates with the FastAPI backend via `process.php`. It displays:

- Confidence score with visual ring
- Result badges (Real/Fake/Uncertain)
- Breakdown bars for Source Credibility, Language Neutrality, Factual Consistency
- **Detailed reasons list** explaining why the result was reached

To use the website:

1. Start the API: `uvicorn app:app --reload`
2. Start a PHP server in the project directory:
   ```bash
   php -S localhost:8080
   ```
3. Open `http://localhost:8080/analyze.html` in a browser
4. Enter URL, text, or upload image
5. View results with explanations

**Note**: Opening `analyze.html` directly in the browser won't work because it needs the PHP backend. Use a local server.

The website provides an intuitive interface for users to understand the AI's decision-making process.

## Notes

- Model accuracy depends on the dataset
- Results are probabilistic, not absolute
- For production, consider security, rate limiting, and model updates