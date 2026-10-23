# Mise à jour hybride (nom + vecteur)

## Pourquoi
La recherche purement vectorielle rate souvent les noms propres (Ndiol Walo, Domou Dangou…).

## Ce qui change
- `ingest.py` génère aussi `names_index.json`
- `search_api.py` match d'abord les noms dans la question, puis complète avec Pinecone

## Installation (sans tout ré-vectoriser)

Dans `sl_rag_vector` (venv activé) :

```bash
# 1. Copier les 2 fichiers mis à jour (ingest.py + search_api.py)

# 2. Générer l'index des noms SEULEMENT (rapide, ~1s)
python ingest.py --file all_athletes.jsonl --names-only

# 3. Redémarrer l'API
# Ctrl+C sur uvicorn puis :
uvicorn search_api:app --host 0.0.0.0 --port 8080
```

Au démarrage tu dois voir :
```
names_index chargé : XXXX clés
```

## Test

```bash
curl -X POST http://localhost:8080/search \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer senlutteurs.com" \
  -d '{"query":"Ndiol walo Domou dangou","top_k":5}'
```

Tu dois voir Ndiol Walo et Domou Dangou en premier (`match_type: name`).
