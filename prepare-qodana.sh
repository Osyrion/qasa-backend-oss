#!/usr/bin/env bash
#
# Qodana bootstrap — runs inside the linter container before analysis
# (wired up via `bootstrap:` in qodana.yaml).
#
# Its only job is to produce _ide_helper.php. Every model carries
# `use Eloquent;` + `@mixin Eloquent` written by barryvdh/laravel-ide-helper,
# but the class those refer to lives *only* in that generated file, which is
# gitignored — so on a clean checkout Qodana resolved nothing and reported
# ~60 "Undefined class 'Eloquent'" errors. Generating it here fixes the cause
# instead of muting the inspection.
#
# `ide-helper:generate` reflects over container bindings and facades; unlike
# `ide-helper:models` it needs no database, only a bootable app.

set -euo pipefail

cd "$(dirname "$0")"

# Must include dev dependencies — ide-helper itself is a require-dev package.
#
# --ignore-platform-reqs: the linter image ships its own PHP and its own set of
# extensions, neither of which has to match composer.json's ^8.5 floor or the
# ext-* list the app requires (soap, intl, gd, imagick, …). Without the flag a
# single missing extension aborts the install, and an analysis with no vendor/ is
# thousands of "undefined class" findings — the exact failure mode this script
# exists to prevent. The real version/extension check is CI's install, not this one.
if [ ! -d vendor ]; then
    echo "prepare-qodana: installing composer dependencies"
    composer install --no-interaction --no-progress --prefer-dist --ignore-platform-reqs
fi

# A fresh checkout has no .env, and the app will not boot without an APP_KEY.
if [ ! -f .env ]; then
    echo "prepare-qodana: seeding .env from .env.example"
    cp .env.example .env
    php artisan key:generate --force --no-interaction
fi

# Non-fatal on purpose: a missing IDE stub is worth 60 false positives, not a
# failed pipeline that reports nothing at all.
if php artisan ide-helper:generate --no-interaction; then
    echo "prepare-qodana: _ide_helper.php generated"
else
    echo "prepare-qodana: WARNING — ide-helper:generate failed; expect 'Undefined class Eloquent' findings" >&2
fi

# .phpstorm.meta.php maps container bindings to concrete classes, so app('foo'),
# resolve(), and Model::factory() resolve to a type instead of mixed. Purely a
# false-positive reducer — same "warn, don't fail" contract as above. It also needs
# no database. Both generated files are excluded from analysis in qodana.yaml.
if php artisan ide-helper:meta --no-interaction; then
    echo "prepare-qodana: .phpstorm.meta.php generated"
else
    echo "prepare-qodana: WARNING — ide-helper:meta failed; expect weaker type inference" >&2
fi
