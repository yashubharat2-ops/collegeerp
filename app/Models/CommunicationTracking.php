<?php

namespace App\Models;

/**
 * CommunicationTracking — the Delivery / Read Tracking screen.
 *
 * Deliberately NOT an Eloquent model: tracking creates no table and
 * duplicates no notification. Every state is derived from the timestamps
 * already stored on the existing communication_notifications and
 * communication_logs rows (see CommunicationDeliveryService).
 *
 * The marker class exists only to give the screen its own authorization
 * boundary: CommunicationTrackingPolicy guards `communication_tracking.view`.
 */
class CommunicationTracking {}
