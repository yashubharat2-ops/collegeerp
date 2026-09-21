<?php

namespace App\Models;

/**
 * FeeDue — the read-only Due / Outstanding Fees screen.
 *
 * Deliberately NOT an Eloquent model: there is no dues table and no balance row
 * is ever stored. Every figure is derived live from the assignment snapshot plus
 * the transaction rows:
 *
 *   assigned − applicable concessions − valid payments + valid refunds = outstanding
 *
 * The marker class exists so the screen has its own authorization boundary:
 * FeeDuePolicy is registered against it and guards `fee_dues.view`. Because
 * nothing is persisted, no user can ever edit an outstanding balance.
 */
final class FeeDue
{
}
