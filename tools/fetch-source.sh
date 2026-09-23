#!/bin/sh
# Fetch public content of https://summit.openzfs.org into source-snapshot/export
# (REST JSON + every image referenced by pages/widgets/products). Re-runnable.
set -eu
S=https://summit.openzfs.org
HERE=$(cd "$(dirname "$0")" && pwd)
OUT="$HERE/../source-snapshot/export"
mkdir -p "$OUT/media" "$OUT/variations"
cd "$OUT"
rm -f media-missing.txt media-dead.txt
curl -fsS "$S/wp-json/wp/v2/pages?per_page=100&context=view" -o pages.json
curl -fsS "$S/wp-json/wp/v2/media?per_page=100" -o media.json
curl -fsS "$S/wp-json/wc/store/v1/products?per_page=100" -o products.json
curl -fsS "$S/wp-json/wc/store/v1/products/categories?per_page=100" -o product-categories.json
curl -fsS "$S/wp-json/wp/v2/rara-portfolio?per_page=100" -o rara-portfolio.json
curl -fsS "$S/wp-json/wp/v2/categories" -o categories.json
curl -fsS "$S/wp-json" -o root.json
# Front page (its body lives in theme widgets, not in the Home page) and the
# product pages (bundle config + extra-option fields are only in the HTML).
curl -fsS "$S/" -o front-page.html
mkdir -p product-pages
for s in $(python3 -c "
import json
for x in json.load(open('products.json')): print(x['slug'])"); do
  curl -fsS "$S/product/$s/" -o "product-pages/$s.html"
done
python3 - <<'PY'
import re,json
out={}
for p in json.load(open('products.json')):
    if p['type']!='easy_product_bundle': continue
    s=open('product-pages/'+p['slug']+'.html').read()
    m=re.search(r'var easyProductBundlesData = (\{.*?\});\s*\n',s,flags=re.S)
    if m: out[p['slug']]=json.loads(m.group(1))
json.dump(out,open('bundles.json','w'),indent=1)
PY
for vid in $(python3 -c "
import json
for x in json.load(open('products.json')):
  for v in x['variations']: print(v['id'])"); do
  curl -fsS "$S/wp-json/wc/store/v1/products/$vid" -o "variations/$vid.json"
done
# Collect every original image URL: REST media, product images, and any
# /wp-content/(uploads|logos)/ URL in page bodies or the rendered front page.
python3 - "$HERE/../source-snapshot" > media-urls.txt <<'PY'
import json,re,sys,glob
snap=sys.argv[1]; urls=set()
for m in json.load(open('media.json')): urls.add(m['source_url'])
for p in json.load(open('products.json')):
    for i in p['images']: urls.add(i['src'])
texts=[p['content']['rendered'] for p in json.load(open('pages.json'))]
texts+= [open(f).read() for f in glob.glob(snap+'/page-*.html')]
for t in texts:
    for u in re.findall(r'https://summit\.openzfs\.org/wp-content/(?:uploads|logos)/[^"\'\s)]+?\.(?:png|jpe?g|webp|gif|svg)',t):
        # skip generated thumbnails (-NNNxNNN) whose original we also have
        urls.add(u)
out=set()
for u in urls:
    base=re.sub(r'-\d+x\d+(\.\w+)$',r'\1',u)
    out.add(base if base in urls else u)
for u in sorted(out): print(u)
PY
# Local file name = basename + "-" + first 8 hex of sha1(URL path) + ext, so
# equal basenames from different paths cannot collide. import-content.php
# (zfs_local_media_name) computes the same name.
python3 - > media-files.tsv <<'PY'
import hashlib, os
from urllib.parse import urlparse
for u in open('media-urls.txt').read().split():
    path = urlparse(u).path
    stem, ext = os.path.splitext(os.path.basename(path))
    print(u + '\t' + stem + '-' + hashlib.sha1(path.encode()).hexdigest()[:8] + ext)
PY
tab=$(printf '\t')
while IFS="$tab" read -r u name; do
  f="media/$name"
  [ -s "$f" ] && continue
  # 404/410 is the source site's own answer ("gone"): record in media-dead.txt.
  # Anything else that fails (network error, timeout, 5xx, other 4xx, empty
  # body) is a failed download: media-missing.txt.
  code=$(curl -sS --max-time 60 -o "$f.part" -w '%{http_code}' "$u" 2>/dev/null) || code=000
  case "$code" in
    200)
      if [ -s "$f.part" ]; then
        mv "$f.part" "$f"
      else
        rm -f "$f.part"; printf '%s\tempty-body\n' "$u" >> media-missing.txt
        echo "MISSING (empty body): $u" >&2
      fi ;;
    404|410)
      rm -f "$f.part"; printf '%s\t%s\n' "$u" "$code" >> media-dead.txt
      echo "DEAD on source (HTTP $code): $u" >&2 ;;
    *)
      rm -f "$f.part"; printf '%s\t%s\n' "$u" "$code" >> media-missing.txt
      echo "MISSING (HTTP $code): $u" >&2 ;;
  esac
done < media-files.tsv
# Drop files no longer referenced (e.g. names from an older layout) -- but
# never from an empty index, which would mean the step above failed.
[ -s media-files.tsv ] || { echo "fetch-source.sh: media-files.tsv is empty; not pruning media/" >&2; exit 1; }
for f in media/*; do
  [ -e "$f" ] || continue
  cut -f2 media-files.tsv | grep -qxF "$(basename "$f")" || rm -f "$f"
done
ls -la media
if [ -s media-dead.txt ]; then
  echo "NOTE: $(wc -l < media-dead.txt) media URL(s) return 404/410 on the source itself (media-dead.txt)." >&2
fi
if [ -s media-missing.txt ]; then
  echo "WARNING: $(wc -l < media-missing.txt) media URL(s) failed to download (media-missing.txt);" >&2
  echo "         import-content.sh will refuse to run unless ZFS_IMPORT_FORCE=1." >&2
fi
