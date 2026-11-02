<?php

// A new contract the assistant drafted (ContractDraft); created only once the user confirms it.
return [
    'title' => 'New contract',
    'created' => 'The contract for :name was created.',
    'open' => 'Open the contract',
    'day-range' => ':from to :to',
    'pay' => ':amount :currency per :per',
    'fields' => [
        'employee' => 'Employee',
        'position' => 'Position',
        'city' => 'City',
        'contract_type' => 'Contract type',
        'from' => 'From',
        'to' => 'To',
        'schedule' => 'Working time',
        'weekly_hours' => 'Hours per week',
        'pay' => 'Pay',
    ],
    'errors' => [
        'employee' => 'Choose an employee you may give a contract to.',
        'days' => 'Choose the working days (first and last day of the week).',
    ],
];
