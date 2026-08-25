<?php

declare(strict_types=1);

/**
 * Runs deptrac over the two depfiles at the repository root and fails the
 * suite on any violation they do not already excuse:
 *
 *   deptrac.layers.php   direction inside a module — Domain knows nothing,
 *                        Application knows only Domain, Infrastructure and
 *                        Presentation know the inner two but not each other
 *   deptrac.modules.php  module isolation — a module publishes contracts and
 *                        values (Application\Contracts, Application\DTOs,
 *                        Domain\Contracts, Domain\Enums, Domain\Events,
 *                        Domain\ValueObjects) and never its model; Domain\Models,
 *                        Domain\Services and the repositories are closed
 *
 * Both configs carry a baseline of the violations that already existed when
 * the tool was introduced (deptrac.*.baseline.yaml, itemised in
 * docs/app/APLIKACIA.md chapter 18). The baselines are a ratchet, not a
 * licence, and deptrac keeps them honest in both directions: a *new* violation
 * fails here, and a baseline line it can no longer match is reported as an
 * error — so fixing a listed violation forces its line out of the file and the
 * list can only shrink. Regenerating a baseline to silence a fresh violation is
 * the one move that defeats the whole check; it belongs in a commit that says so.
 *
 *   composer deptrac            # both configs, outside the suite
 *   composer deptrac:baseline   # regenerate — read the paragraph above first
 *
 * Pest's own arch() rules cover part of the same ground (ModuleBoundariesTest,
 * EditionBoundaryTest) and are kept: they are cheaper, and they express things
 * deptrac has no vocabulary for — an edition boundary is about which names may
 * be *mentioned*, not about which layer may depend on which.
 */

use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Symfony\Component\Process\Process;

// Plain paths rather than base_path(): the Architecture suite runs without
// booting the application, and the helpers need a container.
$repoRoot = dirname(__DIR__, 2);

$analyse = static function (string $depfile) use ($repoRoot): void {
    $binary = $repoRoot.'/vendor/bin/deptrac';

    expect(file_exists($binary))->toBeTrue(
        'vendor/bin/deptrac is missing — deptrac/deptrac is a dev dependency, so run `composer install`.',
    );

    $process = new Process(
        [PHP_BINARY, $binary, 'analyse', '--config-file='.$depfile, '--no-progress', '--no-ansi'],
        $repoRoot,
        timeout: 600,
    );
    $process->run();

    expect($process->getExitCode())->toBe(
        0,
        "deptrac reported violations for {$depfile}:\n\n".$process->getOutput().$process->getErrorOutput(),
    );
};

it('keeps the layer direction inside every module', function () use ($analyse): void {
    $analyse('deptrac.layers.php');
});

it('keeps every module out of the internals of the others', function () use ($analyse): void {
    $analyse('deptrac.modules.php');
});

it('has not let either baseline grow', function () use ($repoRoot): void {
    // The ratchet's other half. deptrac already refuses to match a baseline
    // line that no longer applies, so the list cannot rot — but nothing stops
    // `composer deptrac:baseline` from writing a *bigger* file, and 66 quietly
    // becoming 79 is invisible in a diff nobody reads line by line. So the
    // count lives in the repository as a number: regenerate a baseline and this
    // fails until someone edits that number by hand, in a diff that says what
    // it is. The deptrac-ratchet job in CI then refuses a number, or an entry,
    // that origin/main did not already have.
    $recorded = json_decode(
        (string) file_get_contents($repoRoot.'/deptrac.baseline.counts.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    expect($recorded)->toBeArray();

    // Keys starting with "_" are prose for whoever opens the file.
    $recorded = array_filter(
        $recorded,
        static fn (string $key): bool => ! str_starts_with($key, '_'),
        ARRAY_FILTER_USE_KEY,
    );

    $onDisk = array_map(
        static fn (string $path): string => basename($path),
        glob($repoRoot.'/deptrac.*.baseline.yaml') ?: [],
    );
    sort($onDisk);

    $listed = array_keys($recorded);
    sort($listed);

    expect($listed)->toBe(
        $onDisk,
        'deptrac.baseline.counts.json and the baseline files on disk disagree about which baselines exist — '.
        'an unlisted baseline is an unguarded one.',
    );

    foreach ($recorded as $file => $cap) {
        // One line per excused dependency: "      - App\Modules\...".
        $entries = preg_match_all('/^ {6}- /m', (string) file_get_contents($repoRoot.'/'.$file));

        expect($entries)->toBe(
            $cap,
            "{$file} excuses {$entries} violations, deptrac.baseline.counts.json says {$cap}. ".
            ($entries > $cap
                ? 'Something added to the baseline. Fix the violation instead — and if the addition is genuinely intended, say so in the commit, because CI compares this against origin/main too.'
                : 'Violations were fixed: lower the number to lock the gain in.'),
        );
    }
});

it('covers every module on disk, and lets none of them into another module\'s internals', function () use ($repoRoot): void {
    // The depfile reads app/Modules off the filesystem instead of hardcoding
    // it, which is what makes it survive both a new module and the OSS build
    // (scripts/build-oss.sh deletes the premium module directories outright).
    // Loading the config here — rather than shelling out — checks the rules
    // themselves, not just the code they are applied to: a typo that leaves a
    // module unlayered, or a ruleset that quietly hands out access to someone
    // else's internals, produces a green analyse run and a useless guard.
    $modules = array_map(
        static fn (string $path): string => basename($path),
        glob($repoRoot.'/app/Modules/*', GLOB_ONLYDIR) ?: [],
    );
    sort($modules);

    $config = new DeptracConfig;
    (require $repoRoot.'/deptrac.modules.php')($config);
    $definition = $config->toArray();

    $layers = array_column($definition['layers'], 'name');

    foreach ($modules as $module) {
        expect($layers)->toContain("Public_{$module}");

        // Shared is the shared kernel — public in full, so it has no
        // internals layer to look for.
        if ($module !== 'Shared') {
            expect($layers)->toContain("Internal_{$module}");
        }
    }

    // The account model is the first named exception (see the depfile): it is
    // the tenant root rather than a module's aggregate, it sits in its own
    // layer, and Auth, Saas and Team may reach it. Its own hosts' internals
    // are not "foreign" to it — it is their code.
    $accountModelHosts = ['Auth', 'Saas'];

    foreach ($definition['ruleset'] as $layer => $accessible) {
        $owner = preg_replace('/^(Public|Internal|Models)_/', '', (string) $layer);
        $ownInternals = $layer === 'AccountModel'
            ? array_map(static fn (string $host): string => "Internal_{$host}", $accountModelHosts)
            : ["Internal_{$owner}"];

        $foreignInternals = array_values(array_filter(
            $accessible,
            static fn (string $target): bool => str_starts_with($target, 'Internal_')
                && ! in_array($target, $ownInternals, true),
        ));

        expect($foreignInternals)->toBe(
            [],
            "{$layer} is allowed to reach into another module's internals: ".implode(', ', $foreignInternals),
        );
    }

    // The exception must stay as narrow as it is argued to be. Widening it is
    // a decision, not a tweak, so it fails here rather than passing quietly —
    // the whole of ACCOUNT_MODEL_DEBT_PLAN.md was spent getting the list this
    // short (110 classes naming the model, down to twelve).
    $mayReachAccountModel = array_keys(array_filter(
        $definition['ruleset'],
        static fn (array $accessible): bool => in_array('AccountModel', $accessible, true),
    ));
    sort($mayReachAccountModel);

    // Spelled out here, not read back from the depfile — the point is to pin
    // the decision, so the list lives in two places on purpose. Narrowed to
    // the modules that exist, because the OSS build has neither Saas nor Team
    // and would otherwise fail on their absence rather than on a widening.
    $expected = ['AccountModel']; // deptrac's own implicit self-access
    foreach (['Auth', 'Saas', 'Team'] as $allowed) {
        if (in_array($allowed, $modules, true)) {
            $expected[] = "Internal_{$allowed}";
            $expected[] = "Models_{$allowed}";
            $expected[] = "Public_{$allowed}";
        }
    }
    sort($expected);

    expect($mayReachAccountModel)->toBe(
        $expected,
        'Only Auth and Saas (which host the class) and Team (which owns team membership, '.
        'and membership *is* rows in that table) may name the account model. '.
        'Everything else asks a narrow contract — ProvidesSupplierProfile, '.
        'ProvidesPlanEntitlements, CarriesTeamRole, AccountLocator and friends.',
    );

    // The second named exception: a foreign key between two modules. Only a
    // Domain\Models class may name another module's — an Action or a
    // Controller reaching for a foreign model is exactly what this depfile is
    // for and stays a violation. Widening this is a decision, so it fails
    // here rather than passing quietly.
    foreach ($modules as $module) {
        if ($module === 'Shared') {
            continue;
        }

        $mayReachModels = array_keys(array_filter(
            $definition['ruleset'],
            static fn (array $accessible): bool => in_array("Models_{$module}", $accessible, true),
        ));

        $foreign = array_values(array_filter(
            $mayReachModels,
            static fn (string $layer): bool => ! in_array($layer, [
                "Models_{$module}", "Internal_{$module}", "Public_{$module}", 'AccountModel',
            ], true),
        ));

        expect(array_values(array_filter(
            $foreign,
            static fn (string $layer): bool => ! str_starts_with($layer, 'Models_'),
        )))->toBe(
            [],
            "Models_{$module} is reachable from a layer that is not another module's models: ".implode(', ', $foreign),
        );
    }
});
