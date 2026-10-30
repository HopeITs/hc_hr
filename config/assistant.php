<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI assistant
    |--------------------------------------------------------------------------
    |
    | Every employee may ask the assistant about the organisation's policies
    | and about their own record (leave balances, requests, contract, pay and
    | the files shared with them). Answers come from Google Gemini.
    |
    | Gemini's free tier lets Google use what is sent to improve its products,
    | and its reviewers may read it. That includes the employee's own record
    | and, while `share_salary` is on, their pay. Moving to a paid key changes
    | nothing here but the key itself.
    |
    | The assistant is off until GEMINI_API_KEY is set.
    |
    */

    'enabled' => (bool) env('ASSISTANT_ENABLED', true),

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 60),
        // Writing out a whole policy PDF takes minutes, not seconds.
        'transcribe_timeout' => (int) env('GEMINI_TRANSCRIBE_TIMEOUT', 600),
    ],

    // The employee's own pay figure may be read back to them. Off removes the
    // tool entirely, so the figure is never decrypted for the assistant.
    'share_salary' => (bool) env('ASSISTANT_SHARE_SALARY', true),

    // Questions one user may ask per hour.
    'hourly_limit' => (int) env('ASSISTANT_HOURLY_LIMIT', 30),

    // Earlier turns of the conversation sent along with each question.
    'history_turns' => 10,

    // Upper bound on the policy text sent with one question. When the
    // policies are longer, the ones that best match the question go first.
    'policy_char_budget' => (int) env('ASSISTANT_POLICY_CHARS', 150000),

    'policies' => [
        // The Drive folder holding the policies. Read with
        // services.google.drive_api_key, so it must be shared by link.
        'drive_folder_id' => env('POLICIES_DRIVE_FOLDER_ID'),
    ],

];
