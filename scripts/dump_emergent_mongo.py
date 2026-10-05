"""Read-only dump of the legacy Emergent MongoDB to local extended-JSON files.

Usage:
    MONGO_URL='mongodb+srv://...' python scripts/dump_emergent_mongo.py <db_name> <out_dir>

Output files contain customer data and password hashes: keep them out of git (backups/ is ignored).
"""
import json
import os
import sys

from bson import json_util
from pymongo import MongoClient

url = os.environ["MONGO_URL"]
db_name, out = sys.argv[1], sys.argv[2]
os.makedirs(out, exist_ok=True)

client = MongoClient(url, serverSelectionTimeoutMS=15000)
db = client[db_name]
summary = {}
for name in sorted(db.list_collection_names()):
    docs = list(db[name].find({}))
    with open(os.path.join(out, f"{name}.json"), "w", encoding="utf-8") as f:
        f.write(json_util.dumps(docs, indent=1, ensure_ascii=False))
    summary[name] = len(docs)
    print(f"{name}: {len(docs)}")

with open(os.path.join(out, "_summary.json"), "w") as f:
    json.dump(summary, f, indent=1)
print("total docs:", sum(summary.values()))
