#!/usr/bin/env bash
#
# Runs an official e-invoice Schematron over a UBL document and prints every
# failed assertion.
#
#   bash tools/validate-einvoice.sh path/to/invoice.xml            # both rulesets
#   bash tools/validate-einvoice.sh path/to/invoice.xml en16931    # EN 16931 only
#   bash tools/validate-einvoice.sh path/to/invoice.xml peppol     # Peppol BIS only
#
# Two rulesets, because passing one does not imply the other:
#
#   en16931  ConnectingEurope/eInvoicing-EN16931 — the European norm.
#   peppol   OpenPEPPOL PEPPOL-EN16931-UBL — additional rules layered on top,
#            some of which turn an EN 16931 recommendation into a requirement.
#            This is what a Peppol access point ("digitálny poštár") actually
#            enforces, so a document that clears EN 16931 alone can still be
#            rejected at the door.
#
# Was validate-en16931.sh until the Peppol ruleset arrived; renamed rather than
# copied, since everything below the ruleset choice — jar fetching, running
# Saxon, reading SVRL — is identical for both.
#
# Java is required: a compiled Schematron is XSLT 2.0, which PHP's libxslt
# cannot run (it is XSLT 1.0 only — php-xsl does not help), so validation needs
# Saxon. The JRE is in the application image, and since 2026-08-13 the same
# rulebooks also run at send time (SchematronValidator) — which is why the
# stylesheets moved out of tests/ and into resources/schematron/.
#
# The jars are fetched on demand into storage/ rather than baked into the
# image. `php artisan qasa:peppol:install-validator` calls this script's
# --install mode to do the same thing on a deployment.
set -euo pipefail

# --install fetches the jars and stops. The runtime validator needs them in
# place and must not download anything mid-request; this keeps the pinned list
# below as the single place either path reads it from.
INSTALL_ONLY=0
if [ "${1:-}" = "--install" ]; then
    INSTALL_ONLY=1
    DOC=""
else
    DOC="${1:?usage: validate-einvoice.sh <ubl-document.xml> [en16931|peppol|all]}"
fi

RULESET="${2:-all}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
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

CP="$LIB/saxon.jar:$LIB/xmlresolver.jar:$LIB/httpclient5.jar:$LIB/httpcore5.jar:$LIB/httpcore5-h2.jar:$LIB/slf4j-api.jar"

if [ "$INSTALL_ONLY" = "1" ]; then
    echo "Saxon and the Schematron rulebooks are in place ($LIB)"
    exit 0
fi

REPORT="$(mktemp)"
trap 'rm -f "$REPORT"' EXIT

# Reports per ruleset rather than one combined total: "failed assertions: 3"
# without saying which rulebook produced them would send the reader to the
# wrong specification.
run_ruleset() { # label xslt-path
    local label="$1" xslt="$2"

    [ -f "$xslt" ] || {
        echo "$label: missing $xslt — run tools/build-peppol-schematron.sh"
        return 2
    }

    java -cp "$CP" net.sf.saxon.Transform -s:"$DOC" -xsl:"$xslt" -o:"$REPORT"

    local fired failed
    fired=$(grep -c '<svrl:fired-rule' "$REPORT" || true)
    failed=$(grep -c '<svrl:failed-assert' "$REPORT" || true)

    echo "[$label] rules fired: $fired"
    echo "[$label] failed assertions: $failed"

    if [ "$failed" -gt 0 ]; then
        grep -A 2 '<svrl:failed-assert' "$REPORT" | sed 's/<[^>]*>//g' | tr -s ' \n' ' \n' | grep -v '^\s*$'
        return 1
    fi

    # A stylesheet that matched nothing would also report zero failures.
    [ "$fired" -gt 0 ] || { echo "[$label] no rules fired — the document did not match the Schematron at all"; return 1; }

    return 0
}

STATUS=0

case "$RULESET" in
    en16931|all)
        run_ruleset en16931 "$ROOT/resources/schematron/EN16931-UBL-validation.xslt" || STATUS=$?
        ;;&
    peppol|all)
        run_ruleset peppol "$ROOT/resources/schematron/PEPPOL-EN16931-UBL.xslt" || STATUS=$?
        ;;&
    en16931|peppol|all) ;;
    *)
        echo "unknown ruleset '$RULESET' — expected en16931, peppol or all"
        exit 2
        ;;
esac

[ "$STATUS" -eq 0 ] && echo "OK — conforms to every ruleset checked"

exit "$STATUS"
