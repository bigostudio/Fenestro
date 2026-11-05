<?php

/*
|--------------------------------------------------------------------------
| Fenestro Leads workflow (Leads page)
|--------------------------------------------------------------------------
|
| Status codes are stored in leads.status_code; the label is what users see.
| Exactly these 14, in this order (MSRMT-READY is intentionally not offered).
| Do not rename a code: stored leads and their history would stop matching.
|
| RAG (App\Support\LeadWorkflow::rag), calculated on the server from the stored
| dates, in the business time zone below:
|   LEAD-RCVD / ATT-*           Red (ATT-3-CALL-LATER also closes the lead: "Closed Lead - No response")
|   MSRMT-SCH / MSRMT-INC /     Green: scheduled date more than 2 working days away
|   MSRMT-DLY-RESCLD-ID / -CD   Amber "Get ready": scheduled date within the next 2 working days, or today
|                               Red: from the calendar day after the scheduled date
|   MSRMT-FULL                  Green until 2 working days after the measurement date, then Red
|                               with "Discuss quotation with Customer"
|   MSRMT-ABRT-CL / -PV         archived (neutral)
|
| Working days: Monday–Friday (`weekly_off` = Sunday 0, Saturday 6) minus the company
| holidays in Action Hub → Working calendar (table action_hub_holidays).
*/

return [
    'statuses' => [
        'LEAD-RCVD'           => 'Lead Received',
        'ATT-1-NP'            => 'Attempt 1 - Not Picking',
        'ATT-1-CALL-LATER'    => 'Attempt 1 - Request to Call Later',
        'ATT-2-NP'            => 'Attempt 2 - Not Picking',
        'ATT-2-CALL-LATER'    => 'Attempt 2 - Request to Call Later',
        'ATT-3-NP'            => 'Attempt 3 - Not Picking',
        'ATT-3-CALL-LATER'    => 'Attempt 3 - Request to Call Later',
        'MSRMT-SCH'           => 'Scheduled for Measurement',
        'MSRMT-FULL'          => 'Measurement Taken - Full',
        'MSRMT-INC'           => 'Measurement Taken - Incomplete',
        'MSRMT-DLY-RESCLD-ID' => 'Measurement Delayed & Rescheduled - Internal Delay',
        'MSRMT-DLY-RESCLD-CD' => 'Measurement Delayed & Rescheduled - Client Delay',
        'MSRMT-ABRT-CL'       => 'Abort - Client',
        'MSRMT-ABRT-PV'       => 'Abort - Prestige Ventures',
    ],

    // Lead ID: PREFIX-YYMM-NNN, its own monthly serial (business time zone), never reused.
    'id_prefix' => 'PV-LD',

    // Dates are entered, shown and compared in this zone (same as Action Hub).
    'timezone' => env('LEADS_TIMEZONE', env('ACTION_HUB_TIMEZONE', 'Asia/Kolkata')),

    // Never working days for the lead rules (0 = Sunday … 6 = Saturday). Holidays come from
    // the Action Hub working calendar. (Action Hub itself treats only Sunday as off.)
    'weekly_off' => [0, 6],

    // Amber "Get ready" when the scheduled date is within this many working days.
    'get_ready_working_days' => 2,

    // MSRMT-FULL stays green for this many working days after the measurement date.
    'quotation_working_days' => 2,

    'closed_no_response' => 'Closed Lead - No response',
];
