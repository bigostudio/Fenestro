<?php

/*
|--------------------------------------------------------------------------
| CNS Dashboard configuration (Order Management → CNS Dashboard)
|--------------------------------------------------------------------------
|
| Single place to change status columns, their dropdown options and the
| WhatsApp draft texts. The backend validates against this file and the
| dashboard builds its columns from it (sent with /admin/order-management/data).
|
| - Order of 'statuses' = column order on the CNS Dashboard and order form.
| - 'default' must be one of 'options'.
| - 'done' values show green (no further action); everything else shows red.
| - Removing an option that is already saved on an order makes that order fall
|   back to 'default' when displayed, so change options with care.
*/

return [

    'statuses' => [
        // ── The 8 statuses from the CNS workbook (MyFile.xlsx, CNS DASHBOARD V1.0/V2.0) ──
        // Options/defaults match the workbook's dropdown lists. Exception: the
        // workbook's EXCEPTIONS INFORMED list is "NOT APPLICABLE, INFORMED" with
        // a default of YET TO SHARE; YET TO SHARE stays selectable as in the
        // approved prototype.
        // PROJ STATUS COMM (requested 2026-10-21): the 12 requested options.
        // YET TO SHARE is the default for every new project and always red
        // (YET TO START was removed from the list); any other
        // selected status is green for 4 days from the moment it was selected
        // (comm_colour below), then red — the value itself never changes.
        // PROJECT COMPLETED completes the project (archived once fully paid).
        // PROJECT ABORT closes the project and archives it straight away,
        // whatever is still due (comm_reset.archive_values below).
        // 'legacy' values are still accepted and kept on orders that already
        // have them (never offered for new choices). Legacy NO and YET TO
        // START are shown as "YET TO SHARE" (all mean "not shared yet"; see
        // legacy_labels) — the stored value is kept until a status is chosen.
        'comm' => [
            'label'   => 'PROJ STATUS COMM',
            'options' => [
                'QSHARE', 'ADV RCVD', 'PART PAY RCVD', 'FULL PAY RCVD', 'MATRL ARIVD',
                'PROD STRTD', 'INST STRTD', 'HAND OVER', 'FEEDBACK REQ', 'INSTAL DELAY',
                'YET TO SHARE', 'PROJECT COMPLETED', 'PROJECT ABORT',
            ],
            'legacy'        => ['NO', 'YES', 'YET TO START'],
            'legacy_labels' => ['NO' => 'YET TO SHARE', 'YET TO START' => 'YET TO SHARE'],
            'default'       => 'YET TO SHARE',
            'done'    => [
                'YES', 'PROJECT COMPLETED', 'PROJECT ABORT',
                'QSHARE', 'ADV RCVD', 'PART PAY RCVD', 'FULL PAY RCVD', 'MATRL ARIVD',
                'PROD STRTD', 'INST STRTD', 'HAND OVER', 'FEEDBACK REQ', 'INSTAL DELAY',
            ],
        ],
        'profile' => [
            'label'   => 'PROFILE AVAILABLE',
            'options' => ['YET TO ORDER', 'AWAITING DELIVERY', 'AVAILABLE'],
            'default' => 'YET TO ORDER',
            'done'    => ['AVAILABLE'],
        ],
        'sizeqc' => [
            'label'   => 'SIZE & DESIGN QC',
            'options' => ['YES', 'NO'],
            'default' => 'NO',
            'done'    => ['YES'],
        ],
        'glass' => [
            'label'   => 'GLASS AVAILABLE',
            'options' => ['YET TO ORDER', 'AWAITING DELIVERY', 'AVAILABLE'],
            'default' => 'YET TO ORDER',
            'done'    => ['AVAILABLE'],
        ],
        'prodHardware' => [
            'label'   => 'PROD HARDWARE AVL',
            'options' => ['YET TO ORDER', 'AWAITING DELIVERY', 'AVAILABLE'],
            'default' => 'YET TO ORDER',
            'done'    => ['AVAILABLE'],
        ],
        'installHardware' => [
            'label'   => 'INSTAL HARDWARE PCKD',
            'options' => ['YET TO ORDER', 'AWAITING DELIVERY', 'PACKING PENDING', 'PACKED'],
            'default' => 'YET TO ORDER',
            'done'    => ['PACKED'],
        ],
        'docs' => [
            'label'   => 'PRODUCTION DOC SHARED',
            'options' => ['YET TO SHARE', 'SHARED'],
            'default' => 'YET TO SHARE',
            'done'    => ['SHARED'],
        ],
        'exceptions' => [
            'label'   => 'EXCEPTIONS INFORMED',
            'options' => ['YET TO SHARE', 'NOT APPLICABLE', 'INFORMED'],
            'default' => 'YET TO SHARE',
            'done'    => ['INFORMED', 'NOT APPLICABLE'],
        ],

        // ── 9th column (not in the workbook) — removed from the dashboard on
        // request (2026-10-21). Values already saved on orders are kept.
        'production' => [
            'enabled' => false,
            'label'   => 'PRODUCTION STATUS',
            'options' => ['YET TO START', 'PRODUCTION STARTED', 'PRODUCTION COMPLETED'],
            'default' => 'YET TO START',
            'done'    => ['PRODUCTION COMPLETED'],
        ],
        // ── Provisional columns — NOT approved, so switched off ──
        // 'enabled' => false hides a column everywhere; values already saved on
        // orders are kept. Do not enable without approval of options and messages.
        'dispatch' => [
            'enabled' => false,
            'label'   => 'DISPATCH STATUS',
            'options' => ['NOT READY', 'READY FOR DISPATCH', 'DISPATCHED'],
            'default' => 'NOT READY',
            'done'    => ['DISPATCHED'],
        ],
        'installation' => [
            'enabled' => false,
            'label'   => 'INSTALLATION STATUS',
            'options' => ['YET TO SCHEDULE', 'INSTALLATION SCHEDULED', 'INSTALLATION COMPLETED'],
            'default' => 'YET TO SCHEDULE',
            'done'    => ['INSTALLATION COMPLETED'],
        ],
    ],

    /*
    | WhatsApp drafts (click-to-chat). Nothing is ever sent automatically: after
    | a status is saved, the admin gets a wa.me link with the text prefilled and
    | sends it themselves.
    |
    | Only statuses listed in 'templates' produce a draft.
    */
    'whatsapp' => [
        // Prefix added to 10-digit Indian mobile numbers.
        'country_code' => env('WHATSAPP_DRAFT_COUNTRY_CODE', '91'),

        // Safety switch for testing: when set, EVERY draft is addressed to this
        // number instead of the customer's. Leave empty in production.
        'test_recipient' => env('WHATSAPP_DRAFT_TEST_RECIPIENT'),

        // ONLY approved messages belong here; every other status/value produces
        // no draft. Wording approved by Prestige Ventures on 2026-10-21.
        // Placeholders (filled from the order; a draft is never built with a
        // missing value — the admin is told what is missing instead):
        //   {customer_name}       customer name
        //   {project_name}        project name
        //   {advance_amount}      first receipt (by date) — the advance
        //   {payment_amount}      latest receipt (by date) — the most recent payment
        //   {installation_date}   order's expected installation date
        //   {google_review_link}  google_review_url below
        // Templates hold the message body only: 'signature' below is appended
        // to every draft (OrderManagement::withSignature), so do not add a
        // sign-off here.
        'templates' => [
            'comm' => [
                'QSHARE'        => "Dear {customer_name},\n\nYour quotation has been shared. We look forward to your confirmation.",
                'ADV RCVD'      => "Dear {customer_name},\n\nYour advance payment of amount {advance_amount} towards uPVC window/doors for project {project_name} has been received.",
                'PART PAY RCVD' => "Dear {customer_name},\n\nPayment of amount {payment_amount} has been received. Please find your updated ledger statement.",
                'FULL PAY RCVD' => "Dear {customer_name},\n\nFull payment received towards your project {project_name}. Please find your final ledger statement.",
                'MATRL ARIVD'   => "Dear {customer_name},\n\nMaterial for your project {project_name} has arrived. Production will begin shortly.",
                'PROD STRTD'    => "Dear {customer_name},\n\nProduction has started for your {project_name} project. Expected installation date: {installation_date}.",
                'INST STRTD'    => "Dear {customer_name},\n\nInstallation of uPVC windows/doors has started for your project {project_name}.",
                'HAND OVER'     => "Dear {customer_name},\n\nInstallation and project {project_name} handover are complete.\n\nThank you for choosing us!",
                'FEEDBACK REQ'  => "Dear {customer_name},\n\nWe value your feedback. Kindly share your experience on: {google_review_link}",
                'INSTAL DELAY'  => "Dear {customer_name},\n\nPlease confirm your installation date at the earliest. Delays affect our planned schedules and financial commitments.",
            ],
            // PRODUCTION STATUS no longer opens a draft (PROD STRTD above is the
            // single production message).
        ],

        // Google review link for FEEDBACK REQ, from CNS_GOOGLE_REVIEW_URL
        // (Prestige Ventures: https://g.page/r/CSU2H9tOGNG0EBE/review). If it
        // is not set, FEEDBACK REQ saves the status but no draft is opened.
        'google_review_url' => env('CNS_GOOGLE_REVIEW_URL'),

        // Company signature closing EVERY WhatsApp message built by the portal
        // (CNS status drafts, statements, payment reminders). Change it here
        // only; the browser-built partner/ops messages use the same text in
        // Fenestro-Client/src/utils/whatsapp.ts.
        'signature' => "Thanks,\nPrestige Ventures – Customer Support\n(A uPVC Windows & Doors Manufacturing Company)",

        // Receivables / Archive → Send Statement: message pre-filled in the
        // customer's WhatsApp chat (wa.me click-to-chat) and offered with the
        // statement PDF in the device's share sheet. The admin attaches/sends
        // it in WhatsApp; nothing is sent by the server. Wording from the
        // 2026-10-22 request. {order_id} is the project ID.
        //   statement_template          money still due
        //   statement_template_paid     fully paid: thank-you, no payment request
        //   statement_template_aborted  PROJECT ABORT: payments, refunds, amount retained, balance ₹0
        'statement_template' => "Dear {customer_name},\n\nGreetings from Prestige Ventures.\n\nPlease find your payment statement for {project_name} (Project ID: {order_id}).\n\nInvoice Amount: {invoice_amount}\nTotal Received: {received_amount}\nOutstanding Balance: {outstanding_amount}\n\nThe statement contains the payment details recorded for your project. Kindly review it and let us know if you have any questions.",
        'statement_template_paid' => "Dear {customer_name},\n\nGreetings from Prestige Ventures.\n\nPlease find your payment statement for {project_name} (Project ID: {order_id}).\n\nInvoice Amount: {invoice_amount}\nTotal Received: {received_amount}\nOutstanding Balance: {outstanding_amount}\n\nThis project is fully paid and the outstanding balance is ₹0. Thank you for completing your payments. The statement contains the complete payment history for your records.",
        'statement_template_aborted' => "Dear {customer_name},\n\nGreetings from Prestige Ventures.\n\nPlease find the payment statement for {project_name} (Project ID: {order_id}). This project has been closed.\n\nInvoice Amount: {invoice_amount}\nTotal Payments: {payments_amount}\nTotal Refunded: {refunded_amount}\nNet Amount Retained: {received_amount}\nBalance After Abort: {outstanding_amount}\n\nThe statement lists every payment and refund we have recorded for this project. Please let us know if you have any questions about it.",

        // Receivables → Reminder: payment reminder pre-filled in the customer's WhatsApp
        // chat (click-to-chat; the admin presses Send). Wording from the 2026-10-29 request.
        // {outstanding_amount} prints as ₹55,000.
        'reminder_template' => "Dear {customer_name},\n\nGreetings!\n\nThis is a gentle reminder to kindly clear the pending balance amount of {outstanding_amount} at the earliest.\n\nThank you for your continued support and cooperation.",

        // Statement messages for Send Statement. The click-to-chat workflow only
        // pre-fills a chat the admin sends by hand, so it is on by default.
        // Set CNS_STATEMENT_DRAFTS=false on the server to switch it off.
        'statement_drafts_enabled' => (bool) env('CNS_STATEMENT_DRAFTS', true),

        // Automatic sending of the statement PDF + message through the official
        // WhatsApp Business Cloud API (App\Services\WhatsAppService, credentials
        // in config/services.php: WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID).
        // Without credentials the admin sends manually (wa.me draft + PDF).
        // WhatsApp only allows free-form messages (document + caption) within
        // 24 hours of the customer's last message; outside that window an
        // approved template is required. Set WHATSAPP_STATEMENT_TEMPLATE to a
        // template with a DOCUMENT header and 5 body variables, in this order:
        // {{1}} customer name, {{2}} project name, {{3}} invoice amount,
        // {{4}} amount received, {{5}} outstanding amount.
        'statement_api' => [
            'template'          => env('WHATSAPP_STATEMENT_TEMPLATE'),
            'template_language' => env('WHATSAPP_STATEMENT_TEMPLATE_LANG', 'en'),
        ],
    ],

    // PROJ STATUS COMM colour rule (replaces the old "reset to NO every 4th
    // day"; the saved status is never changed):
    //   green — a status selected less than `fresh_hours` ago (timestamp set by
    //           the server when the status changes: om_orders.last_comm_change);
    //   red   — YET TO START / YET TO SHARE / NO, or the 4 days have passed.
    // `php artisan cns:reset-communication` (scheduled hourly in
    // routes/console.php) records each expiry on the server (last_comm_reset).
    'comm_colour' => [
        'status'       => 'comm',
        'fresh_hours'  => 96, // exactly 4 days
        'unset_values' => ['YET TO START', 'YET TO SHARE', 'NO'],
    ],

    // Closing statuses (never reset, off the active CNS board, timestamped in
    // completed_at): PROJECT COMPLETED archives the project once it is fully
    // paid; 'archive_values' (PROJECT ABORT) archive it immediately, with any
    // balance still recorded. Nothing is ever deleted. HAND OVER is a normal
    // communication status.
    'comm_reset' => [
        'status'         => 'comm',
        'stop_values'    => ['PROJECT COMPLETED', 'PROJECT ABORT'],
        'archive_values' => ['PROJECT ABORT'],
        'timezone'    => env('CNS_TIMEZONE', 'Asia/Kolkata'),
    ],
    'archive_after_days' => 0,

    // Orders/profiles whose ID starts with this are demo data (`php artisan orders:demo`).
    'demo_prefix' => 'PV-DEMO-',
];
