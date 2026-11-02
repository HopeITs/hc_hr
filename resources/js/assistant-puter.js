/**
 * Puter.js for the AI assistant (App\Livewire\Assistant\Chat).
 *
 * When no AI provider on the server can answer, the server hands the question
 * to the browser: each round it gives a chat-completions request, this asks
 * Puter (under the employee's own Puter account) and the model's message goes
 * back to the server, which runs the tools and decides what comes next.
 *
 * Puter's script is a third party's, so it is only loaded the first time a
 * question actually needs it, never on page load.
 */

let loading = null;

function load(src) {
    if (window.puter) return Promise.resolve(window.puter);

    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => (window.puter ? resolve(window.puter) : reject(new Error('puter-missing')));
        script.onerror = () => {
            loading = null;
            reject(new Error('puter-load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}

/** `signin`, `funds` or `failed`: what the server tells the employee. */
function reason(error) {
    const text = (() => {
        try {
            return JSON.stringify(error) + String(error?.message ?? '');
        } catch {
            return String(error);
        }
    })();

    if (/popup_blocked|auth_window_closed|unsupported_origin|sign.?in/i.test(text)) return 'signin';
    if (/insufficient_funds|subscription_required/i.test(text)) return 'funds';

    return 'failed';
}

/** Text only, whether the content comes as a string or as blocks. */
function text(content) {
    if (typeof content === 'string') return content;
    if (Array.isArray(content)) {
        return content.filter((part) => part?.type === 'text').map((part) => part.text ?? '').join('');
    }

    return '';
}

window.hcAssistantPuter = {
    reason,

    async isSignedIn(src) {
        const puter = await load(src);

        return Boolean(await puter.auth.isSignedIn());
    },

    /** Opens Puter's sign-in popup: call it from a click, or the browser blocks it. */
    async signIn(src) {
        const puter = await load(src);
        await puter.auth.signIn();
    },

    /** One round: the request from the server in, the model's message out. */
    async chat(src, request) {
        const puter = await load(src);
        const options = { model: request.model };
        if (request.tools?.length) options.tools = request.tools;

        const response = await puter.ai.chat(request.messages, false, options);
        const message = response?.message ?? {};

        return {
            content: text(message.content),
            tool_calls: (message.tool_calls ?? []).map((call) => ({
                id: call.id,
                type: 'function',
                function: {
                    name: call.function?.name ?? '',
                    arguments:
                        typeof call.function?.arguments === 'string'
                            ? call.function.arguments
                            : JSON.stringify(call.function?.arguments ?? {}),
                },
            })),
        };
    },
};
