<?php

/*
|--------------------------------------------------------------------------
| Action Hub (Workspace → Action Hub)
|--------------------------------------------------------------------------
|
| Appointments and follow-ups (snags, service visits, measurements…) with
| automatic status rules:
|   On Track  → Get Ready  from the first of the last `get_ready_working_days`
|               working days before the appointment's local date;
|   → Delayed  once the scheduled date AND time have passed while still pending.
| Completed and Abort are never changed automatically. A new date (Reschedule)
| recalculates the status. See App\Support\ActionHub.
|
| Working days: every day except `weekly_off` (Sunday) and the holidays in
| the admin-managed list (Action Hub → Working calendar, table
| action_hub_holidays). Saturday is a working day unless it is a holiday.
| No government holidays are assumed: add the official list for `location`.
*/

return [
    // Business location the holiday list applies to (shown with the calendar).
    // Prestige Ventures' registered address is Channapatna, Karnataka.
    'location' => env('ACTION_HUB_LOCATION', 'Channapatna, Karnataka, India'),

    // Every appointment date/time is entered, shown and compared in this zone.
    'timezone' => env('ACTION_HUB_TIMEZONE', 'Asia/Kolkata'),

    // Days of the week that are never working days (0 = Sunday … 6 = Saturday).
    'weekly_off' => [0],

    // "Get Ready" starts this many working days before the appointment date.
    'get_ready_working_days' => 2,

    // Dropdown options — spelling exactly as requested (2026-10-31).
    // These are the values stored in action_hub_actions.action_type and used by
    // validation and filters. Do not rename them, or existing records stop matching.
    'actions' => ['Snag', 'Service', 'Task', 'Contact Lead', 'Measurements Scheduled', 'Installation', 'Other'],

    // Text shown for a stored value (page, filters, reports). The renames were
    // requested 2026-11-01. Actions not listed are shown as stored. A save that
    // sends a label instead of the value is accepted and stored as the value.
    'action_labels' => [
        'Measurements Scheduled' => 'Scheduled Measurements',
        'Installation' => 'Scheduled Installation',
    ],
    // "Assigned To": exactly these seven names (spelling, capitals and order requested 2026-11-01).
    // Only these are accepted when an action is created or saved, and only these are offered in the
    // dropdowns and the filter. An action still saved with another name must be given one of these
    // on its next Submit.
    'assignees' => ['Ashu', 'Aamir', 'Arif', 'Faisal', 'FMW', 'Hadi', 'Akram'],

    // Service tickets + WhatsApp messages (requested 2026-11-01). Only these stored action
    // values get a ticket, once, when the action is saved (App\Support\ActionHubWhatsapp).
    // Ticket: PREFIX-YYMM-NNN with ONE serial shared by all four prefixes per month
    // (Asia/Kolkata), e.g. PV-SG-2611-001, then PV-SV-2611-002, PV-MSRMT-2611-003.
    // Sending uses the WhatsApp Cloud API settings in config/services.php
    // (WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_SENDING_ENABLED).
    'whatsapp' => [
        'tickets' => [
            'Snag' => ['prefix' => 'PV-SG', 'service' => 'Snag'],
            'Service' => ['prefix' => 'PV-SV', 'service' => 'Service'],
            'Measurements Scheduled' => ['prefix' => 'PV-MSRMT', 'service' => 'Measurement'],
            'Installation' => ['prefix' => 'PV-INSTL', 'service' => 'Installation'],
        ],

        // Action Hub phone numbers are 10-digit Indian mobiles; sent as 91XXXXXXXXXX.
        'country_code' => '91',

        // Feedback link in the "Service Completed & Handover Done" message only (given 2026-11-01).
        'feedback_link' => env('ACTION_HUB_FEEDBACK_URL', 'https://g.page/r/CSU2H9tOGNG0EBE/review'),

        // WhatsApp delivers a business-started message only as an APPROVED Meta TEMPLATE,
        // unless the customer wrote to the business number in the last 24 hours. So a message
        // can be sent only when its `meta` (env) names the approved template: its body must be
        // this text with the `params` as {{1}}, {{2}}, … in that order. Set
        // WHATSAPP_AH_ALLOW_SESSION_TEXT=true to also send messages WITHOUT an approved template
        // as plain text; WhatsApp then delivers them only inside that 24-hour window (its
        // refusal is shown on the page otherwise).
        'allow_session_text' => (bool) env('WHATSAPP_AH_ALLOW_SESSION_TEXT', false),

        // The messages, wording as requested 2026-11-01. The admin chooses one in Send WhatsApp;
        // nothing is sent automatically. Placeholders: {customer_name} {service_type} {ticket_no}
        // {date} {time} (current appointment, business time zone) {feedback_link} {message}.
        // The first nine are for ticket actions (Snag, Service, Scheduled Measurements, Scheduled
        // Installation); 'general' is for Task, Contact Lead and Other (no ticket).
        'templates' => [
            'request_received' => [
                'label' => 'Request Received',
                'text' => "Dear {customer_name},\n\nWe have received your {service_type} service request. Our team will keep you updated on the next steps.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_REQUEST_RECEIVED', env('WHATSAPP_ACTION_HUB_TEMPLATE')),
            ],
            'scheduled' => [
                'label' => 'Service Scheduled',
                'text' => "Dear {customer_name},\n\nYour {service_type} service has been scheduled for {date} at {time}.\n\nWe look forward to assisting you.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'date', 'time', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_SCHEDULED'),
            ],
            'approaching' => [
                'label' => 'Service Date Approaching – Confirmation',
                'text' => "Dear {customer_name},\n\nYour scheduled {service_type} service is approaching. Our team is ready to attend to it on {date} at {time}.\n\nTicket #: {ticket_no}\n\nIn case of any changes, please inform us immediately through WhatsApp at 6360474741.\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'date', 'time', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_APPROACHING'),
            ],
            'completed' => [
                'label' => 'Service Completed & Handover Done',
                'text' => "Dear {customer_name},\n\nThe {service_type} service has been completed and handed over.\n\nTicket #: {ticket_no}\n\nThank you for the opportunity. We request you to share your valuable feedback here: {feedback_link}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no', 'feedback_link'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_COMPLETED'),
            ],
            'delayed_internal' => [
                'label' => 'Service Delayed – Revised Date to Be Communicated',
                'text' => "Dear {customer_name},\n\nDue to unforeseen internal constraints, your {service_type} service has been delayed.\n\nThe revised date and time will be communicated shortly. We regret the inconvenience.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_DELAYED'),
            ],
            'rescheduled_customer' => [
                'label' => 'Rescheduled at Customer’s Request – Revised Date Confirmed',
                'text' => "Dear {customer_name},\n\nAs requested, your {service_type} service has been rescheduled to {date} at {time}.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'date', 'time', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_RESCHEDULED'),
            ],
            'postponed_customer' => [
                'label' => 'Postponed at Customer’s Request – Date to Follow',
                'text' => "Dear {customer_name},\n\nAs requested, your {service_type} service has been postponed.\n\nThe revised date and time will be communicated shortly.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_POSTPONED'),
            ],
            'cancelled_customer' => [
                'label' => 'Cancelled at Customer’s Request',
                'text' => "Dear {customer_name},\n\nAs requested, your {service_type} service has been permanently cancelled.\n\nShould you require our assistance in the future, please call or WhatsApp us at 6360474741.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_CANCELLED_CUSTOMER'),
            ],
            'cancelled_internal' => [
                'label' => 'Cancelled – Internal Decision',
                'text' => "Dear {customer_name},\n\nAs communicated separately, your {service_type} service has been permanently cancelled.\n\nShould you require our assistance in the future, please call or WhatsApp us at 6360474741.\n\nTicket #: {ticket_no}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'service_type', 'ticket_no'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_CANCELLED_INTERNAL'),
            ],
            // Task, Contact Lead and Other: a one-line message the admin types (shown in the
            // preview exactly as sent). No ticket.
            'general' => [
                'label' => 'General Message',
                'text' => "Dear {customer_name},\n\n{message}\n\nRegards,\nPrestige Ventures - Admin",
                'params' => ['customer_name', 'message'],
                'meta' => env('WHATSAPP_AH_TEMPLATE_GENERAL'),
            ],
        ],
        'template_language' => env('WHATSAPP_ACTION_HUB_TEMPLATE_LANG', 'en'),
    ],
    'statuses' => ['On Track', 'Get Ready', 'Delayed', 'Completed', 'Abort', 'Reschedule'],
];
