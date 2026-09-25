<?php

/*
|--------------------------------------------------------------------------
| Communication Management (Phase 1)
|--------------------------------------------------------------------------
|
| Platform-wide, institution-agnostic settings for the internal
| Communication module (notices / announcements, circulars and in-app
| notifications). Nothing here is specific to a college: every tenant shares
| the same limits, and every record stays scoped to its college.
|
| Phase 1 is internal only. Phase 2 adds reusable SMS / e-mail TEMPLATES,
| communication LOGS, delivery / read tracking and read-only reports — still
| without any external gateway: no SMS / e-mail / WhatsApp provider or
| messaging API is configured here.
|
*/

return [

    'attachments' => [
        // Hard upload ceiling in kilobytes for notice / circular attachments.
        'max_kb' => (int) env('COMMUNICATION_ATTACHMENT_MAX_KB', 5120),

        // Accepted file extensions (validated against the detected MIME type
        // by Laravel's `mimes` rule). Executable / scriptable types are always
        // rejected by CommunicationAttachmentService, whatever is listed here.
        'mimes' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'jpg', 'jpeg', 'png'],
    ],

    // Rows per page on the Notices, Circulars and Notifications listings.
    'per_page' => 15,

    // How many recent records each Communication Dashboard panel shows.
    'dashboard_recent_limit' => 5,

    // How many recent communication activity rows the Phase 2 Communication
    // Reports screen lists per panel.
    'reports_recent_limit' => 10,

    // Cap on recipient options rendered per recipient type in the
    // notification form (keeps the form light for large colleges).
    'recipient_option_limit' => 500,

];
