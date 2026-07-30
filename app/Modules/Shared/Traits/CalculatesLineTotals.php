<?php

declare(strict_types=1);

namespace App\Modules\Shared\Traits;

use App\Modules\Shared\Support\Decimal;

/**
 * Line totals for order, invoice and quote items.
 *
 * The three used to carry byte-identical copies of this math, which is how a
 * rounding rule drifts apart between document types — an invoice generated
 * from an order has to agree with it to the cent.
 *
 * The mid-way quantisation is deliberate: VAT is charged on the line total as
 * it appears on the document, not on the raw quantity × price product, so the
 * base is rounded first and the VAT derived from that.
 */
trait CalculatesLineTotals
{
    public function recalculate(): self
    {
        $excl = Decimal::money(Decimal::of($this->quantity)->multipliedBy(Decimal::of($this->unit_price)));
        $vat = Decimal::money(Decimal::percentOf($excl, $this->vat_rate));

        $this->total_excl_vat = $excl;
        $this->vat_amount = $vat;
        $this->total_incl_vat = Decimal::money(Decimal::of($excl)->plus(Decimal::of($vat)));

        return $this;
    }
}
