<?php

declare(strict_types=1);

namespace Onelo\Store;

/**
 * One active plan of the App (#909); amounts in minor units (1900 = 19.00).
 * `plan` is the plan key — the same key as OneloUser::$plan and feature plan rules.
 */
final class OneloPlan
{
    public function __construct(
        public readonly ?string $plan,
        public readonly string $name,
        public readonly ?string $shortLabel,
        public readonly string $mode,
        public readonly string $currency,
        public readonly int $priceAmount,
        public readonly ?int $annualPriceAmount,
        public readonly ?int $trialPeriodDays,
        public readonly bool $isActive,
        public readonly int $sortOrder,
        /** 'exclusive': $priceAmount is a net price and VAT is added on top. */
        public readonly ?string $taxBehavior = null,
        /** What a consumer is shown and charged: the price with VAT at the standard
         *  rate of $displayTaxCountry when it is net, the price itself when it
         *  already includes VAT. Show this one to buyers. */
        public readonly ?int $displayPriceAmount = null,
        public readonly ?int $displayAnnualPriceAmount = null,
        public readonly ?string $displayTaxCountry = null,
    ) {
    }

    /** @param array<string, mixed> $p */
    public static function fromArray(array $p): self
    {
        return new self(
            isset($p['plan']) ? (string) $p['plan'] : null,
            (string) $p['name'],
            isset($p['short_label']) ? (string) $p['short_label'] : null,
            (string) $p['mode'],
            (string) $p['currency'],
            (int) $p['price_amount'],
            isset($p['annual_price_amount']) ? (int) $p['annual_price_amount'] : null,
            isset($p['trial_period_days']) ? (int) $p['trial_period_days'] : null,
            (bool) ($p['is_active'] ?? false),
            (int) $p['sort_order'],
            isset($p['tax_behavior']) ? (string) $p['tax_behavior'] : null,
            isset($p['display_price_amount']) ? (int) $p['display_price_amount'] : null,
            isset($p['display_annual_price_amount']) ? (int) $p['display_annual_price_amount'] : null,
            isset($p['display_tax_country']) ? (string) $p['display_tax_country'] : null,
        );
    }
}
