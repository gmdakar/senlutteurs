#!/usr/bin/env bash
cd ~/senelutte/web/modules/custom/sl_rag_vector
source .venv/bin/activate

uvicorn search_api:app --host 0.0.0.0 --port 8080
