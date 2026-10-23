# SL RAG Vector — Ingestion locale + Pinecone + API recherche

## Vue d'ensemble

1. **Drupal** exporte les fiches → `all_athletes.jsonl`
2. **Ce script (sur ton PC)** vectorise en local (gratuit) et envoie à Pinecone
3. **API `search_api.py`** (local ou Railway) : vectorise la question + cherche dans Pinecone
4. **Drupal** appelle l'API → récupère les fiches → envoie à Gemini

## Prérequis

- Python 3.10+
- Compte Pinecone free : https://app.pinecone.io
- Fichier JSONL exporté par Drupal

## Installation (sur ton PC)

```bash
cd sl_rag_vector
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env
# Éditer .env : coller PINECONE_API_KEY
```

## 1. Exporter depuis Drupal

```bash
ddev drush sl-rag:export-entities --output=/tmp/all_athletes.jsonl
# Copier le fichier dans ce dossier
```

## 2. Ingestion (une fois, gratuit, local)

```bash
# Test avec 20 fiches
python ingest.py --file all_athletes.jsonl --limit 20

# Tout ingérer
python ingest.py --file all_athletes.jsonl

# Recréer l'index from scratch
python ingest.py --file all_athletes.jsonl --recreate
```

## 3. API de recherche

### En local

```bash
uvicorn search_api:app --host 0.0.0.0 --port 8080
```

Test :

```bash
curl -X POST http://localhost:8080/search \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer change-me-secret" \
  -d '{"query":"Ndiol walo Domou dangou palmarès","top_k":5}'
```

### Sur Railway (gratuit)

1. Créer un projet Railway
2. Connecter ce dossier / déployer
3. Variables d'environnement = contenu de `.env`
4. Start command : `uvicorn search_api:app --host 0.0.0.0 --port $PORT`

## 4. Brancher Drupal

Dans Drupal, configurer l'URL de l'API + le secret (voir module `sl_rag`).

Puis :

```bash
ddev drush sl-rag:ask "Qui a le meilleur palmarès entre Ndiol walo et Domou dangou ?"
```

## Modèle d'embeddings

Par défaut : `paraphrase-multilingual-MiniLM-L12-v2` (bon en français, 384 dimensions).

**Important** : le même modèle doit être utilisé à l'ingestion ET dans `search_api.py`.
