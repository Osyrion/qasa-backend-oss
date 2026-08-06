#!/usr/bin/env bash
#
# Runs the official EN 16931 Schematron (ConnectingEurope/eInvoicing-EN16931)
# over a UBL document and prints every failed assertion.
#
#   bash tools/validate-en16931.sh path/to/invoice.xml
#
# Java is required and deliberately not in the application image: the
# compiled Schematron is XSLT 2.0, which PHP's libxslt cannot run (it is
# XSLT 1.0 only — php-xsl does not help), so validation needs Saxon. Adding
# a JRE to the runtime image for a check that only ever runs in development
# is not a trade worth making, so the jars are fetched on demand into
# storage/ instead.
set -euo pipefail

DOC="${1:?usage: validate-en16931.sh <ubl-document.xml>}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
XSLT="$ROOT/tests/Fixtures/en16931/EN16931-UBL-validation.xslt"
LIB="$ROOT/storage/app/en16931-validator"

command -v java >/dev/null || { echo "java not found — install a JRE to run the Schematron"; exit 2; }

mkdir -p "$LIB"

fetch() { # url filename
    [ -f "$LIB/$2" ] || curl -sSLf -o "$LIB/$2" "$1"
}

BASE=https://repo1.maven.org/maven2
fetch "$BASE/net/sf/saxon/Saxon-HE/12.5/Saxon-HE-12.5.jar"                          saxon.jar
fetch "$BASE/org/xmlresolver/xmlresolver/5.2.2/xmlresolver-5.2.2.jar"               xmlresolver.jar
fetch "$BASE/org/apache/httpcomponents/client5/httpclient5/5.2.1/httpclient5-5.2.1.jar" httpclient5.jar
fetch "$BASE/org/apache/httpcomponents/core5/httpcore5/5.2/httpcore5-5.2.jar"       httpcore5.jar
fetch "$BASE/org/apache/httpcomponents/core5/httpcore5-h2/5.2/httpcore5-h2-5.2.jar" httpcore5-h2.jar
fetch "$BASE/org/slf4j/slf4j-api/2.0.9/slf4j-api-2.0.9.jar"                         slf4j-api.jar

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT

java -cp "$LIB/saxon.jar:$LIB/xmlresolver.jar:$LIB/httpclient5.jar:$LIB/httpcore5.jar:$LIB/httpcore5-h2.jar:$LIB/slf4j-api.jar" \
    net.sf.saxon.Transform -s:"$DOC" -xsl:"$XSLT" -o:"$REPORT"

FIRED=$(grep -c '<svrl:fired-rule' "$REPORT" || true)
FAILED=$(grep -c '<svrl:failed-assert' "$REPORT" || true)

echo "rules fired: $FIRED"
echo "failed assertions: $FAILED"

if [ "$FAILED" -gt 0 ]; then
    grep -A 2 '<svrl:failed-assert' "$REPORT" | sed 's/<[^>]*>//g' | tr -s ' \n' ' \n' | grep -v '^\s*$'
    exit 1
fi

# A stylesheet that matched nothing would also report zero failures.
[ "$FIRED" -gt 0 ] || { echo "no rules fired — the document did not match the Schematron at all"; exit 1; }

echo "OK — conforms to EN 16931 (Schematron $(grep -o 'Schematron version [0-9.]*' "$XSLT" | head -1))"
