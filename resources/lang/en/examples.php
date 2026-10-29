<?php

/*
 * Typed examples for empty fields (resources/js/typewriter.js) and the quiet
 * hints under them (<x-forms.hint>).
 *
 * Examples are invented: common first names and family names on their own,
 * placeholder phone numbers and example.com addresses. Never a real person's
 * data. A field that holds one language (first_name_ar) reads the list of
 * that language: __('examples.first_name', [], 'ar').
 */

return [

    'leave_reason' => [
        'Family visit in Homs',
        'My brother\'s wedding',
        'Medical appointment in Damascus',
        'Moving to a new house',
        'University exam',
    ],

    'first_name' => ['Rami', 'Lina', 'George', 'Mirna', 'Fadi'],
    'last_name' => ['Khoury', 'Haddad', 'Abboud', 'Nasr'],
    'father_name' => ['Youssef', 'Elias', 'Samir', 'Nabil'],
    'mother_name' => ['Mary', 'Suad', 'Nawal', 'Hanan'],

    'phone' => ['0933000111', '0944000222', '0955000333'],
    'personal_email' => ['rami.k@example.com', 'lina.haddad@example.org'],

    'news' => [
        'title' => [
            'Study Zone opens a new class in Aleppo',
            'Staff day on 15 November',
            'Christmas activities for the children in Homs',
        ],
        'description' => [
            'All staff are invited to the yearly staff day. The bus leaves the centre at 8:00.',
            'Registration for the new LEAP group is open until the end of the month.',
        ],
    ],

    'global_search' => [
        'Leave requests',
        'Contracts ending this month',
        'Aleppo',
        'New employee',
        'Org chart',
    ],

    'hints' => [
        'leave_reason' => 'A few words are enough. Your manager and HR see this.',
        'name' => 'As written on the ID card.',
        'phone' => '10 digits, starting with 09.',
        'news_title' => 'Short and clear: it is the headline on the news card.',
        'news_description' => 'Say what, when and where in two or three sentences.',
    ],

];
