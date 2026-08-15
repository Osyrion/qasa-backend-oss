<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\DTOs;

/**
 * One AI-assisted capability, described the way Regulation (EU) 2024/1689
 * (AI Act) art. 50 ods. 1 and recital 132 ask for it: what the system does,
 * what leaves the account to a model provider, what comes back, and how the
 * user turns it off. See docs/legal/AI_ACT.md — this DTO *is* the machine-
 * readable half of that register, served by GET /api/v1/ai/transparency, so
 * the document and the running system cannot drift apart.
 *
 * Every field is already translated (the descriptors resolve their own
 * __('ai.*') keys per request locale), because the caller is a user-facing
 * API response, not a log line.
 */
final readonly class AiFeatureDisclosure
{
    /**
     * @param  string  $key  Stable identifier — 'invoice_extraction', 'reminder_draft', …
     * @param  'suggestion'|'generated_text'  $output  What the model returns: prefilled fields
     *                                                 a human confirms, or text a human edits and
     *                                                 sends. Nothing in this list decides anything
     *                                                 on its own (art. 14 human oversight, GDPR
     *                                                 art. 22).
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $purpose,
        public string $data_sent,
        public string $output,
        public string $opt_out,
        public bool $enabled,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'purpose' => $this->purpose,
            'data_sent' => $this->data_sent,
            'output' => $this->output,
            'opt_out' => $this->opt_out,
            'enabled' => $this->enabled,
            // Constant on purpose: a feature whose output is applied without
            // a human confirming it does not belong in this register — it
            // belongs in a fresh AI Act assessment first (see
            // docs/legal/AI_ACT.md §5).
            'human_review_required' => true,
            'automated_decision_making' => false,
        ];
    }
}
