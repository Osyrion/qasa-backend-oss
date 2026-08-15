#!/usr/bin/env bash
#
# Compiles the official Peppol BIS Billing 3.0 Schematron into the XSLT that
# tools/validate-einvoice.sh runs, and writes it to tests/Fixtures/peppol/.
#
#   bash tools/build-peppol-schematron.sh [tag]     # default: the pinned tag below
#
# Why this exists at all: ConnectingEurope publishes a *compiled* stylesheet
# for EN 16931, so that artefact could simply be downloaded. OpenPEPPOL ships
# only the Schematron source, so the compile step is ours — the standard
# three-stage ISO pipeline (include → expand abstract patterns → emit SVRL),
# run through the same Saxon the validator already needs.
#
# The compiled output is committed, deliberately:
#
#   - the test must run offline and in CI, like the EN 16931 one;
#   - it must run against the version we pinned, not against whatever is on
#     master the day someone happens to run the suite. A validation rulebook
#     that silently changes underneath a green suite is worse than none.
#
# Regenerate by bumping PEPPOL_TAG, running this, and reading the diff.
#
# We deliberately do not use the repackaged phive-rules-peppol from Maven
# Central: it is a third party that lags behind OpenPEPPOL's releases, and the
# rulebook is exactly the thing that has to be current.
set -euo pipefail

PEPPOL_TAG="${1:-v3.0.20}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/storage/app/en16931-validator"
OUT_DIR="$ROOT/resources/schematron"
OUT="$OUT_DIR/PEPPOL-EN16931-UBL.xslt"

command -v java >/dev/null || { echo "java not found — install a JRE to compile the Schematron"; exit 2; }

mkdir -p "$LIB" "$OUT_DIR"

fetch() { # url destination
    [ -f "$2" ] || curl -sSLf -o "$2" "$1"
}

BASE=https://repo1.maven.org/maven2
fetch "$BASE/net/sf/saxon/Saxon-HE/12.5/Saxon-HE-12.5.jar"                          "$LIB/saxon.jar"
fetch "$BASE/org/xmlresolver/xmlresolver/5.2.2/xmlresolver-5.2.2.jar"               "$LIB/xmlresolver.jar"
fetch "$BASE/org/apache/httpcomponents/client5/httpclient5/5.2.1/httpclient5-5.2.1.jar" "$LIB/httpclient5.jar"
fetch "$BASE/org/apache/httpcomponents/core5/httpcore5/5.2/httpcore5-5.2.jar"       "$LIB/httpcore5.jar"
fetch "$BASE/org/apache/httpcomponents/core5/httpcore5-h2/5.2/httpcore5-h2-5.2.jar" "$LIB/httpcore5-h2.jar"
fetch "$BASE/org/slf4j/slf4j-api/2.0.9/slf4j-api-2.0.9.jar"                         "$LIB/slf4j-api.jar"

CP="$LIB/saxon.jar:$LIB/xmlresolver.jar:$LIB/httpclient5.jar:$LIB/httpcore5.jar:$LIB/httpcore5-h2.jar:$LIB/slf4j-api.jar"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "==> Fetching PEPPOL-EN16931-UBL.sch at $PEPPOL_TAG"
curl -sSLf -o "$WORK/rules.sch" \
    "https://raw.githubusercontent.com/OpenPEPPOL/peppol-bis-invoice-3/$PEPPOL_TAG/rules/sch/PEPPOL-EN16931-UBL.sch"

# The skeleton is the reference ISO Schematron implementation; the Saxon
# variant is required because iso_svrl_for_xslt2.xsl imports it by name.
echo "==> Fetching the ISO Schematron skeleton"
SKEL=https://raw.githubusercontent.com/Schematron/schematron/master/trunk/schematron/code
for f in iso_dsdl_include.xsl iso_abstract_expand.xsl iso_svrl_for_xslt2.xsl iso_schematron_skeleton_for_saxon.xsl; do
    curl -sSLf -o "$WORK/$f" "$SKEL/$f"
done

transform() { # input xsl output
    java -cp "$CP" net.sf.saxon.Transform -s:"$WORK/$1" -xsl:"$WORK/$2" -o:"$WORK/$3"
}

echo "==> Compiling"
transform rules.sch  iso_dsdl_include.xsl   step1.sch   # resolve <include>/<extends>
transform step1.sch  iso_abstract_expand.xsl step2.sch  # expand abstract patterns
transform step2.sch  iso_svrl_for_xslt2.xsl  compiled.xslt

# A compile that produced a stylesheet matching nothing would still "succeed"
# and would then make every future validation trivially pass.
grep -q 'PEPPOL-EN16931' "$WORK/compiled.xslt" || {
    echo "compiled stylesheet carries no Peppol rule ids — refusing to write it"
    exit 1
}

cp "$WORK/compiled.xslt" "$OUT"

echo "==> Wrote $(basename "$OUT") ($(wc -c < "$OUT") bytes) from OpenPEPPOL $PEPPOL_TAG"
