<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI assistant
    |--------------------------------------------------------------------------
    |
    | Every employee may ask the assistant about the organisation's policies
    | and about their own record (leave balances, requests, contract, pay and
    | the files shared with them). Answers come from whichever AI provider
    | below answers first (ModelRouter): Google Gemini, Groq, OpenRouter,
    | Cerebras or Mistral.
    |
    | Free tiers may let the provider use what is sent to improve its
    | products, and its reviewers may read it. That includes the employee's
    | own record and, while `share_salary` is on, their pay. Moving to a paid
    | key changes nothing here but the key itself.
    |
    | The assistant is off until at least one provider has a key. A provider
    | without a key is skipped.
    |
    */

    'enabled' => (bool) env('ASSISTANT_ENABLED', true),

    // Providers asked in this order; the first that answers is used for the
    // whole reply. Fastest first: Groq and Cerebras answer a round in about a
    // second, while the Gemini free tier takes several and OpenRouter's free
    // models queue, so a question with two tool rounds feels instant on the
    // first two and slow on the rest. Gemini also refuses requests from
    // Syria ("User location is not supported"), so a host there needs at
    // least one of the others.
    'providers_order' => array_values(array_filter(array_map('trim', explode(',', (string) env('ASSISTANT_PROVIDERS', 'groq,cerebras,gemini,openrouter,mistral'))))),

    // Seconds one provider gets to answer one chat round before the next is
    // tried. Short on purpose: a provider that has not answered in 20s is
    // overloaded, and the next one usually answers in a few.
    'chat_timeout' => (int) env('ASSISTANT_CHAT_TIMEOUT', 20),

    // A compact block of the employee's own record (profile, contract dates,
    // leave balances, pending requests) goes into the system instruction of
    // every question, so the common ones are answered without a tool round.
    // Off sends nothing but what the tools return when called.
    'snapshot' => (bool) env('ASSISTANT_SNAPSHOT', true),

    // Seconds a failing provider is skipped by the following questions:
    // busy or unreachable (429, 5xx, timeout), and refused for this host or
    // key (unsupported location, 401/403, unknown model).
    'cooldown' => [
        'temporary' => (int) env('ASSISTANT_COOLDOWN_TEMPORARY', 120),
        'unavailable' => (int) env('ASSISTANT_COOLDOWN_UNAVAILABLE', 1800),
    ],

    // OpenAI-compatible providers (chat completions with tool calling). Each
    // model can be changed in the .env; `fallback_models` (comma separated)
    // are tried on the same provider when the first model is busy.
    'providers' => [
        'groq' => [
            'name' => 'Groq',
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'key' => env('GROQ_API_KEY'),
            'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
            'fallback_models' => array_filter(explode(',', (string) env('GROQ_FALLBACK_MODELS', ''))),
            'timeout' => (int) env('GROQ_TIMEOUT', 30),
            'headers' => [],
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            'key' => env('OPENROUTER_API_KEY'),
            // A `:free` model; it must support tool calling.
            'model' => env('OPENROUTER_MODEL', 'meta-llama/llama-3.3-70b-instruct:free'),
            'fallback_models' => array_filter(explode(',', (string) env('OPENROUTER_FALLBACK_MODELS', ''))),
            'timeout' => (int) env('OPENROUTER_TIMEOUT', 30),
            // OpenRouter's attribution headers (optional).
            'headers' => [
                'HTTP-Referer' => env('APP_URL', 'http://localhost'),
                'X-Title' => env('APP_NAME', 'HC-HR'),
            ],
        ],
        'cerebras' => [
            'name' => 'Cerebras',
            'base_url' => env('CEREBRAS_BASE_URL', 'https://api.cerebras.ai/v1'),
            'key' => env('CEREBRAS_API_KEY'),
            'model' => env('CEREBRAS_MODEL', 'gpt-oss-120b'),
            'fallback_models' => array_filter(explode(',', (string) env('CEREBRAS_FALLBACK_MODELS', ''))),
            'timeout' => (int) env('CEREBRAS_TIMEOUT', 30),
            'headers' => [],
        ],
        'mistral' => [
            'name' => 'Mistral',
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
            'key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-small-latest'),
            'fallback_models' => array_filter(explode(',', (string) env('MISTRAL_FALLBACK_MODELS', ''))),
            'timeout' => (int) env('MISTRAL_TIMEOUT', 30),
            'headers' => [],
        ],
    ],

    // Gemini also writes out the policy PDFs (policies:sync), which only it does.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        // Tried in order when the model above is overloaded or unreachable.
        'fallback_models' => array_filter(explode(',', (string) env('GEMINI_FALLBACK_MODELS', 'gemini-3.5-flash-lite'))),
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

    // Upper bound on the policy text sent with one question: the passages
    // that best match it. Every free tier counts tokens per minute, and the
    // whole set (~160k characters) made each question slow and over quota.
    'policy_char_budget' => (int) env('ASSISTANT_POLICY_CHARS', 12000),

    'policies' => [
        // The Drive folder holding the policies. Read with
        // services.google.drive_api_key, so it must be shared by link.
        'drive_folder_id' => env('POLICIES_DRIVE_FOLDER_ID'),
    ],

];
