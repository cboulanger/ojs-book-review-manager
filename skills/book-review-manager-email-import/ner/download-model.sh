#!/bin/sh
# Download the GLiNER multilingual PII model (full precision, ~1.2 GB) for eml.js --ner.
# Only the full-precision build works; the int8 build scores names too low.
set -e
target="${OFR_NER_MODEL:-$HOME/.cache/ofr-email-import/gliner_multi_pii-v1.onnx}"
mkdir -p "$(dirname "$target")"
if [ -s "$target" ]; then echo "already there: $target"; exit 0; fi
curl -fL --progress-bar -o "$target.part" \
  "https://huggingface.co/onnx-community/gliner_multi_pii-v1/resolve/main/onnx/model.onnx"
mv "$target.part" "$target"
echo "-> $target"
