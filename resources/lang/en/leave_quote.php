<?php

// Messages for App\Support\Leave\LeaveQuote (rule set leave-calc/1).
// `codes` are the stable error/warning codes; `explain` are the lines of the
// calculation breakdown. Numbers arrive as raw values (hours may be decimal);
// the view formats them.
return [

    'codes' => [
        'no_countable_days' => 'Every selected date is a weekend or an official holiday, so there is nothing to request.',
        'schedule_unknown' => 'Your contract does not give a working-day length, so this leave cannot be priced in days. Request it in hours, or ask HR to check your contract hours.',
        'exceeds_balance' => 'This request is more than your remaining balance.',
        'exceeds_type_max' => 'This request is longer than the maximum allowed for this leave type.',
        'outside_working_hours' => 'The time must be within the center\'s working hours.',
        'holiday_inside' => 'Official holidays inside the period are not counted.',
        'december_restricted' => 'Leave is not allowed in December unless you mark it as exceptional. An exceptional request needs approval from your manager and HR.',
        'no_leave_entitlement' => 'Your contract has no leave balance. Your direct manager approves the absence and HR is informed. Nothing is deducted.',
        'below_minimum' => 'Hourly leave must be at least 15 minutes.',
        'probation_excess' => 'During probation, leave beyond 3 days is deducted from pay.',
        'pending_reserved' => 'Your other pending requests already reserve part of this balance.',
        'balance_unknown_weekend' => 'Your weekend could not be determined, so Saturday and Sunday were used. Ask HR to check your center details.',
        'invalid_window' => 'The end must be after the start.',
    ],

    // Why a date was skipped (excluded_dates[].reason).
    'reasons' => [
        'weekend' => 'Weekend',
        'holiday' => 'Official holiday',
    ],

    'explain' => [
        'weekend_center' => 'Weekend days follow your center.',
        'weekend_program' => 'Weekend days follow your program\'s centers in your governorate.',
        'weekend_default' => 'Weekend days use the default (Saturday and Sunday).',
        'days' => ':days working days × :hours_per_day h = :hours h.',
        'calendar_days' => ':days calendar days, weekends and holidays included, × :hours_per_day h = :hours h.',
        'skipped_weekends' => 'Weekend days not counted: :count.',
        'skipped_holidays' => 'Official holidays not counted: :count.',
        'hours_exact' => 'Counted exactly: :hours h.',
        'hours_promoted' => 'More than :threshold hours counts as one full working day (:hours_per_day h).',
        'part_time' => 'Part-time (:weekly_hours h a week): deducted in hours only.',
        'hours_only' => 'Your schedule is set in weekly hours only (:weekly_hours h a week), so leave is requested in hours.',
        'december_exceptional' => 'Marked as an exceptional December leave: it needs approval from your manager and HR.',
        'no_entitlement' => 'Service agreements and draft contracts have no leave balance. Nothing is deducted.',
        'pending' => 'Requests pending review reserve :hours h.',
        'projected' => 'Available :available h − pending :pending h − this request :requested h = :projected h left.',
    ],

    // The live impact summary beside the dates (A3 step 2).
    'preview' => [
        'title' => 'Effect on your balance',
        'estimate' => 'Estimate: these figures are proposed. They become final once the request is decided.',
        'hint' => 'Choose a leave type and complete the dates to see how this request affects your balance.',
        'available' => 'Available now',
        'pending' => 'Reserved by your other pending requests',
        'requested' => 'This request (proposed)',
        'projected' => 'Left after this request (proposed)',
        'short' => 'Short by :amount',
        'working_days' => 'Working days counted',
        'calendar_days' => 'Calendar days counted',
        'in_hours' => 'In hours',
        'excluded' => 'Not counted',
        'more_excluded' => 'and :count more',
        'warnings' => 'Please note',
        'errors' => 'This request cannot be sent yet',
        'how' => 'How this is calculated',
    ],

    // December exception (D-7), shown only when the period touches December.
    'exceptional' => [
        'label' => 'This is an exceptional December leave',
        'hint' => 'Leave in December is allowed only as an exception. Tick this to ask for one. It needs approval from your manager and HR.',
    ],

    // Decision-time recheck of an annual balance.
    'decision' => [
        'exceeds_balance' => 'This request cannot be accepted now: the annual balance has :available left and the request needs :requested. Reject it, or ask the employee to shorten it.',
    ],

];
