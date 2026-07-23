<?php

declare(strict_types=1);

namespace Onelo\Features;

/**
 * A single feature's resolved state. Returned by FeaturesClient::feature(). 1:1
 * with Python's `Feature` dataclass and Swift's `Feature` — cross-language docs
 * stay identical.
 *
 * Carries the backend's full upsell metadata so a SERVER-RENDERED app (Blade /
 * Twig templates) can build an "Available in Pro" CTA — not just a binary
 * on/off. All metadata fields are optional; a plain gating check only needs
 * isEnabled()/isVisible().
 *
 * Availability model (kept 1:1 with Swift/Python):
 *   available (usable):    enabled, new, beta
 *   visible, not usable:   coming_soon, greyed, upsell
 *   not visible:           hidden — AND any status this SDK build doesn't know
 *                          (fail-closed, see isVisible()/isKnown()).
 */
final class Feature
{
    /** @var array<int, string> usable statuses */
    private const ENABLED_STATUSES = ['enabled', 'new', 'beta'];
    /** @var array<int, string> user-visible statuses */
    private const VISIBLE_STATUSES = ['enabled', 'new', 'beta', 'coming_soon', 'greyed', 'upsell'];
    /** @var array<int, string> statuses this SDK build understands (visible + hidden) */
    private const KNOWN_STATUSES = ['enabled', 'new', 'beta', 'coming_soon', 'greyed', 'upsell', 'hidden'];

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?string $requiredPlan = null,
        public readonly ?string $requiredPlanLabel = null,
        public readonly bool $upgradeCta = false,
    ) {
    }

    /**
     * Build a Feature from a backend wire state. Tolerates a bare status string
     * (legacy/degenerate shape) and ignores unknown extra keys (forward-compat).
     * Missing status → hidden (fail-closed).
     *
     * @param array<string, mixed>|string|mixed $state
     */
    public static function fromWire(string $name, mixed $state): self
    {
        if (!is_array($state)) {
            return new self(name: $name, status: is_string($state) ? $state : (string) $state);
        }

        return new self(
            name: $name,
            status: isset($state['status']) && is_string($state['status']) ? $state['status'] : 'hidden',
            reason: isset($state['reason']) && is_string($state['reason']) ? $state['reason'] : null,
            requiredPlan: isset($state['required_plan']) && is_string($state['required_plan']) ? $state['required_plan'] : null,
            requiredPlanLabel: isset($state['required_plan_label']) && is_string($state['required_plan_label']) ? $state['required_plan_label'] : null,
            upgradeCta: (bool) ($state['upgrade_cta'] ?? false),
        );
    }

    /** True for enabled / new / beta. The default check for binary gating. */
    public function isEnabled(): bool
    {
        return in_array($this->status, self::ENABLED_STATUSES, true);
    }

    /**
     * True for any user-visible status (enabled/new/beta/coming_soon/greyed/
     * upsell). False for hidden AND for any status this SDK build does not
     * recognise — fail-closed, so a typo or a status added by a newer backend
     * never renders as a blank, visible item. Use for trigger/menu gating.
     */
    public function isVisible(): bool
    {
        return in_array($this->status, self::VISIBLE_STATUSES, true);
    }

    public function isGreyed(): bool
    {
        return $this->status === 'greyed';
    }

    public function isNew(): bool
    {
        return $this->status === 'new';
    }

    public function isBeta(): bool
    {
        return $this->status === 'beta';
    }

    public function isComingSoon(): bool
    {
        return $this->status === 'coming_soon';
    }

    /** Feature is in upsell mode — shown to the user as a paid/locked teaser. */
    public function isUpsell(): bool
    {
        return $this->status === 'upsell';
    }

    /**
     * True if `status` is one this SDK build understands. False means the backend
     * returned a status newer than this SDK (treated as hidden by isVisible()/
     * isEnabled()) — surface it as a hint to upgrade the SDK.
     */
    public function isKnown(): bool
    {
        return in_array($this->status, self::KNOWN_STATUSES, true);
    }

    /**
     * The human plan LABEL to show as an upsell (e.g. "Pro"), or null when the
     * backend attached no label. Render as "Available in {upgradeHint()}".
     *
     * The backend is the authority: it attaches required_plan_label only to a
     * blocked-but-visible feature that a real plan upgrade would unlock, and never
     * to hidden/enabled — so this is naturally null outside an upsell. Label-only
     * by design: for the raw "is there an upgrade" signal use requiredPlan /
     * upgradeCta.
     */
    public function upgradeHint(): ?string
    {
        return ($this->requiredPlanLabel !== null && $this->requiredPlanLabel !== '') ? $this->requiredPlanLabel : null;
    }
}
