<?php

namespace App\Models;

/**
 * FeeReceipt — a printable projection of one successful FeePayment.
 *
 * Deliberately NOT an Eloquent model: there is no `receipts` table and no
 * receipt rows are ever created. The receipt number that appears on the printed
 * document IS the payment's own server-generated `payment_number`, so there is
 * no second numbering series and no second financial fact to keep in sync.
 *
 * The wrapper exists so the read-only document has its own authorization
 * boundary: FeeReceiptPolicy is registered against this class, keeping
 * `receipts.view` / `receipts.print` (and the never-show-a-cancelled-payment
 * rule) separate from the Fee Collection policy that governs who may record and
 * reverse money.
 */
final class FeeReceipt
{
    public function __construct(public readonly FeePayment $payment)
    {
    }

    public static function fromPayment(FeePayment $payment): self
    {
        return new self($payment);
    }

    /** The underlying payment id, so routes keep using the familiar parameter. */
    public function getKey(): mixed
    {
        return $this->payment->getKey();
    }

    public function getRouteKey(): mixed
    {
        return $this->payment->getRouteKey();
    }

    /** Receipt number: the payment's own server-generated number. */
    public function number(): string
    {
        return (string) $this->payment->payment_number;
    }
}
