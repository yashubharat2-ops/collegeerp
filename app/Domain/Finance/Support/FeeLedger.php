<?php

namespace App\Domain\Finance\Support;

use App\Models\FeeConcession;
use App\Models\FeeStructureItem;

/**
 * FeeLedger — the single place the Finance / Fees module turns transaction rows
 * into money figures.
 *
 * Nothing here touches the database: it is pure arithmetic over amounts that
 * have already been selected (scoped to the active college), so the fee
 * collection cap, the concession cap, the refund cap, the due calculation and
 * every report all share one definition of "outstanding".
 *
 * The formula is deliberately the one the module documents everywhere:
 *
 *   outstanding = assigned − applicable concessions − valid payments + valid refunds
 *
 * All values are decimal money. They are carried as floats for arithmetic only:
 * every input comes from a decimal(12,2) column and every output is rounded back
 * to 2 decimals, so no binary floating point residue ever reaches storage.
 */
final class FeeLedger
{
    public const STATUS_PAID = 'paid';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_DUE = 'due';

    public const STATUSES = [
        self::STATUS_PAID,
        self::STATUS_PARTIAL,
        self::STATUS_DUE,
    ];

    /**
     * Half a paisa: absorbs the rounding noise of summing decimal columns as
     * floats. It is far below any real currency unit, so it can never let a
     * genuine overpayment or over-refund through.
     */
    public const TOLERANCE = 0.005;

    public static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }

    /**
     * The assigned fee: the total of a fee structure's ACTIVE components.
     *
     * @param  iterable<FeeStructureItem>  $items
     */
    public static function assignedFromItems(iterable $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            if ($item->status !== FeeStructureItem::STATUS_ACTIVE) {
                continue;
            }

            $total += (float) $item->amount;
        }

        return self::money($total);
    }

    /**
     * The money value of one concession, always computed server-side.
     *
     * A percentage is applied to the assigned amount and rounded to 2 decimals,
     * and can never exceed the assigned amount itself.
     */
    public static function concessionAmount(string $type, mixed $value, float $assigned): float
    {
        $assigned = self::money($assigned);

        if ($type === FeeConcession::TYPE_PERCENTAGE) {
            $amount = $assigned * ((float) $value / 100);

            return self::money(min($amount, $assigned));
        }

        return self::money(min((float) $value, $assigned));
    }

    /**
     * Total concessions that reduce the payable amount.
     *
     * The sum is capped at the assigned amount, so an over-generous set of
     * concessions can never push a balance below zero.
     *
     * @param  iterable<FeeConcession|array{amount: mixed, status: string}>  $rows
     */
    public static function effectiveConcessions(float $assigned, iterable $rows): float
    {
        $total = 0.0;

        foreach ($rows as $row) {
            $status = is_array($row) ? $row['status'] : $row->status;

            if (in_array($status, FeeConcession::INVALID_STATUSES, true)) {
                continue;
            }

            $total += (float) (is_array($row) ? $row['amount'] : $row->amount);
        }

        return self::money(min($total, self::money($assigned)));
    }

    /**
     * Outstanding payable amount. Never negative: an over-collection cannot
     * create a negative due (it is rejected before it is stored).
     */
    public static function outstanding(float $assigned, float $concessions, float $paid, float $refunded): float
    {
        return self::money(max(
            0.0,
            self::money($assigned) - self::money($concessions) - self::money($paid) + self::money($refunded)
        ));
    }

    /** Net collected = valid payments minus valid refunds (never negative). */
    public static function netCollected(float $paid, float $refunded): float
    {
        return self::money(max(0.0, self::money($paid) - self::money($refunded)));
    }

    /**
     * The ledger status of an assignment: paid / partial / due.
     */
    public static function status(float $assigned, float $concessions, float $paid, float $refunded): string
    {
        $outstanding = self::outstanding($assigned, $concessions, $paid, $refunded);

        if ($outstanding <= self::TOLERANCE) {
            return self::STATUS_PAID;
        }

        $collected = self::netCollected($paid, $refunded);

        return $collected > self::TOLERANCE ? self::STATUS_PARTIAL : self::STATUS_DUE;
    }

    /**
     * A complete summary for one assignment.
     *
     * @return array{assigned: float, concession: float, paid: float, refunded: float, net_collected: float, outstanding: float, status: string}
     */
    public static function summary(
        float $assigned,
        float $concessions,
        float $paid,
        float $refunded,
    ): array {
        $concessions = self::money($concessions);
        $paid = self::money($paid);
        $refunded = self::money($refunded);

        return [
            'assigned' => self::money($assigned),
            'concession' => $concessions,
            'paid' => $paid,
            'refunded' => $refunded,
            'net_collected' => self::netCollected($paid, $refunded),
            'outstanding' => self::outstanding($assigned, $concessions, $paid, $refunded),
            'status' => self::status($assigned, $concessions, $paid, $refunded),
        ];
    }
}
