#!/bin/sh
# Fetch public content of https://summit.openzfs.org into source-snapshot/export
# (REST JSON + every image referenced by pages/widgets/products). Re-runnable.
set -eu
S=https://summit.openzfs.org
HERE=$(cd "$(dirname "$0")" && pwd)
OUT="$HERE/../source-snapshot/export"
mkdir -p "$OUT/media" "$OUT/variations"
cd "$OUT"
rm -f media-missing.txt
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
while read -r u; do
  f="media/$(basename "$u")"
  [ -s "$f" ] && continue
  curl -fsS "$u" -o "$f" || { rm -f "$f"; echo "$u" >> media-missing.txt; echo "MISSING on source: $u" >&2; }
done < media-urls.txt
ls -la media
