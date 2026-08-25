<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Contracts\AiFeatureDescriptor;
use Tests\TestCase;

/**
 * Every capability that sends account data to an LLM must be described in
 * the art. 50 transparency notice (Regulation (EU) 2024/1689) — see
 * docs/legal/AI_ACT.md. The notice is only as honest as its weakest entry:
 * a new AI-backed endpoint that nobody remembers to declare turns the notice
 * into a false statement about what leaves the account, which is worse than
 * having no endpoint at all.
 *
 * So this derives the list of LLM consumers from the source tree — anything
 * that touches AiAssistantServiceInterface or the LLM extractor — and
 * requires each one to map to a descriptor that is actually registered
 * ('ai.features') in this edition.
 *
 * Needs the app booted for the container tag, hence uses(TestCase::class),
 * same reasoning as RoutePermissionCoverageTest.
 */
uses(TestCase::class);

/**
 * Classes that talk to a model on a user's behalf, mapped to the
 * AiFeatureDisclosure key that describes them.
 *
 * Premium-module consumers live in premiumAiFeatureConsumers()
 * (tests/Pest.edition.php) — this file survives the OSS build, where those
 * classes do not exist and would trip the stale-entry guard below. Same
 * mechanism as RoutePermissionCoverageTest's premium allowlists.
 *
 * @return array<string, string>
 */
function aiFeatureConsumers(): array
{
    return [
        ...(function_exists('premiumAiFeatureConsumers') ? premiumAiFeatureConsumers() : []),

        // The job that runs extraction reaches the LLM only through this
        // factory, so the factory is the one place that decides a document
        // leaves the account.
        'App\Modules\Invoicing\Application\Services\FieldExtractorFactory' => 'invoice_extraction',
    ];
}

/**
 * Plumbing rather than a capability: the shared completion path itself, the
 * contract in front of it, the extractor/driver classes that perform the
 * call, and the providers that wire them up. None of these decide that an
 * account's data is sent anywhere — the consumers above do.
 *
 * @return list<string>
 */
function aiPlumbingClasses(): array
{
    return [
        'App\Modules\Invoicing\Application\Contracts\AiAssistantServiceInterface',
        'App\Modules\Invoicing\Domain\Contracts\InvoiceFieldExtractor',
        'App\Modules\Invoicing\Domain\Contracts\LlmProviderDriver',
        'App\Modules\Invoicing\Domain\Exceptions\LlmExtractionException',
        'App\Modules\Shared\Application\Contracts\AiFeatureDescriptor',
        'App\Modules\Invoicing\Application\Services\AiAssistantService',
        'App\Modules\Invoicing\Application\Services\ConfiguredAiModelResolver',
        'App\Modules\Invoicing\Application\Services\LlmProviderRegistry',
        'App\Modules\Invoicing\Infrastructure\Ocr\CompositeExtractor',
        'App\Modules\Invoicing\Application\Contracts\LlmFieldExtractorFactoryInterface',
        'App\Modules\Invoicing\Infrastructure\Ocr\LlmFieldExtractor',
        'App\Modules\Invoicing\Infrastructure\Ocr\LlmFieldExtractorFactory',
        'App\Modules\Invoicing\Infrastructure\Providers\InvoicingServiceProvider',
    ];
}

/**
 * Source files that reference the LLM completion path or the LLM extractor,
 * as fully-qualified class names.
 *
 * @return list<string>
 */
function aiConsumerClasses(): array
{
    $root = dirname(__DIR__, 2).'/app/Modules';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    /** @var SplFileInfo $file */
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, 'AiAssistantServiceInterface') && ! str_contains($contents, 'LlmFieldExtractor')) {
            continue;
        }

        if (preg_match('/namespace\s+([^;]+);/', $contents, $namespace) !== 1) {
            continue;
        }

        $found[] = trim($namespace[1]).'\\'.$file->getBasename('.php');
    }

    sort($found);

    return $found;
}

/**
 * @return list<string>
 */
function registeredAiFeatureKeys(): array
{
    $owner = createUser();

    $keys = [];

    /** @var AiFeatureDescriptor $descriptor */
    foreach (app()->tagged('ai.features') as $descriptor) {
        $keys[] = $descriptor->disclosureFor($owner)->key;
    }

    return $keys;
}

it('describes every LLM consumer in the AI transparency notice', function (): void {
    $consumers = aiFeatureConsumers();
    $plumbing = aiPlumbingClasses();
    $undeclared = [];

    foreach (aiConsumerClasses() as $class) {
        if (in_array($class, $plumbing, true) || array_key_exists($class, $consumers)) {
            continue;
        }

        $undeclared[] = $class;
    }

    expect($undeclared)->toBe(
        [],
        'These classes send account data to an LLM but no AiFeatureDescriptor covers them — the '.
        'art. 50 notice would not mention them. Add a descriptor (and map it in aiFeatureConsumers()), '.
        'or record it as plumbing: '.implode(', ', $undeclared),
    );
});

it('registers a descriptor for every declared consumer', function (): void {
    $registered = registeredAiFeatureKeys();
    $missing = [];

    foreach (aiFeatureConsumers() as $class => $key) {
        if (! in_array($key, $registered, true)) {
            $missing[] = "{$class} => {$key}";
        }
    }

    expect($missing)->toBe(
        [],
        'These consumers map to a feature key no registered descriptor produces (tag ai.features): '
        .implode(', ', $missing),
    );
});

it('does not leave a stale entry in the AI consumer map', function (): void {
    $existing = aiConsumerClasses();
    $stale = [];

    foreach (array_keys(aiFeatureConsumers()) as $class) {
        if (! in_array($class, $existing, true)) {
            $stale[] = $class;
        }
    }

    expect($stale)->toBe([], 'Stale aiFeatureConsumers() entries (class renamed/removed): '.implode(', ', $stale));
});
