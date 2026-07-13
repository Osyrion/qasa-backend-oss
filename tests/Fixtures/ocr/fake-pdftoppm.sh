#!/usr/bin/env bash
# Stand-in for poppler-utils' pdftoppm, used by PdfRasterizerTest so the
# suite doesn't depend on poppler-utils being installed. Mimics only what
# PdfRasterizer relies on: writes "<prefix>-1.png"/"<prefix>-2.png" next to
# the given output prefix (the last CLI argument), and logs the received
# arguments to "<prefix>.args.log" so tests can assert flags/paths passed.
last=""
for arg in "$@"; do
    last="$arg"
done

printf '%s\n' "$*" > "${last}.args.log"
printf 'fake-png-page-1' > "${last}-1.png"
printf 'fake-png-page-2' > "${last}-2.png"
