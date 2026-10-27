#!/bin/sh
# Rebuild the three PDFs (needs XeLaTeX with fontspec, DejaVu and Noto Sans Arabic fonts, and makeglossaries):
#   sh docs/build.sh
set -e
cd "$(dirname "$0")"
for d in user-manual:user-manual admin-manual:admin-manual installation:installation-guide; do
  dir=${d%%:*}; f=${d##*:}
  ( cd "$dir"
    xelatex -interaction=nonstopmode -halt-on-error "$f.tex" >/dev/null
    makeglossaries "$f" >/dev/null 2>&1 || true
    xelatex -interaction=nonstopmode -halt-on-error "$f.tex" >/dev/null
    xelatex -interaction=nonstopmode -halt-on-error "$f.tex" >/dev/null
    echo "$dir/$f.pdf: $(pdfinfo "$f.pdf" | grep Pages)" )
done
