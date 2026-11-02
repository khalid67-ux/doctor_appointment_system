<?php
$chatbotPrefix = rtrim((string)($asset_prefix ?? ''), '/');

$chatbotLogo = ($chatbotPrefix !== '' ? $chatbotPrefix . '/' : '')
    . 'assets/images/logo.png';

$chatbotApi = ($chatbotPrefix !== '' ? $chatbotPrefix . '/' : '')
    . 'chatbot-api.php';
?>

<!-- MediBook Floating Chatbot -->
<style>
#mb-chatbot {
    --mb-blue: #2563eb;
    --mb-navy: #14213d;
    --mb-light: #eff6ff;
    font-family: inherit;
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 9999;
}

#mb-chat-toggle {
    width: 60px;
    height: 60px;
    border: 0;
    border-radius: 50%;
    background: var(--mb-blue);
    color: #fff;
    box-shadow: 0 8px 25px rgba(37,99,235,.3);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 27px;
    transition: transform .2s, background .2s;
}

#mb-chat-toggle:hover {
    background: #1d4ed8;
    transform: translateY(-3px);
}

#mb-chat-panel {
    display: none;
    position: absolute;
    right: 0;
    bottom: 76px;
    width: 360px;
    max-width: calc(100vw - 32px);
    height: 490px;
    max-height: calc(100dvh - 120px);
    background: #fff;
    border: 1px solid #dbeafe;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 15px 50px rgba(15,23,42,.18);
    flex-direction: column;
}

#mb-chat-panel.mb-chat-open {
    display: flex;
    animation: mbChatAppear .2s ease-out;
}

@keyframes mbChatAppear {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

.mb-chat-header {
    padding: 16px;
    background: var(--mb-blue);
    color: #fff;
    display: flex;
    align-items: center;
    gap: 11px;
}

.mb-chat-avatar {
    width: 42px;
    height: 42px;
    background: #fff;
    color: var(--mb-blue);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.mb-chat-logo {
    width: 40px;
    height: 40px;
    object-fit: contain;
    display: block;
}

.mb-chat-header-info {
    flex: 1;
    min-width: 0;
}

.mb-chat-header-info strong {
    display: block;
    font-size: 16px;
}

.mb-chat-header-info small {
    font-size: 12px;
    opacity: .9;
}

#mb-chat-close {
    background: transparent;
    border: 0;
    color: #fff;
    font-size: 25px;
    cursor: pointer;
    line-height: 1;
}

#mb-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px 13px;
    background: #f8fafc;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.mb-chat-message {
    max-width: 88%;
    padding: 10px 13px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.mb-chat-bot {
    align-self: flex-start;
    background: #fff;
    color: var(--mb-navy);
    border: 1px solid #e2e8f0;
    border-bottom-left-radius: 4px;
}

.mb-chat-user {
    align-self: flex-end;
    background: var(--mb-blue);
    color: #fff;
    border-bottom-right-radius: 4px;
}

.mb-chat-suggestions {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    padding: 0 12px 12px;
    background: #f8fafc;
}

.mb-chat-suggestions button {
    border: 1px solid #bfdbfe;
    background: #fff;
    color: #1d4ed8;
    border-radius: 20px;
    padding: 6px 10px;
    font-size: 11px;
    cursor: pointer;
}

.mb-chat-suggestions button:hover {
    background: #eff6ff;
}

#mb-chat-form {
    display: flex;
    gap: 8px;
    align-items: center;
    padding: 12px;
    background: #fff;
    border-top: 1px solid #e2e8f0;
}

#mb-chat-input {
    flex: 1;
    min-width: 0;
    border: 1px solid #cbd5e1;
    border-radius: 22px;
    padding: 11px 14px;
    font-size: 13px;
    outline: none;
}

#mb-chat-input:focus {
    border-color: var(--mb-blue);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}

#mb-chat-send {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 50%;
    background: var(--mb-blue);
    color: #fff;
    cursor: pointer;
    font-size: 17px;
    flex-shrink: 0;
}

#mb-chat-send:disabled {
    opacity: .6;
    cursor: wait;
}

.mb-chat-note {
    padding: 0 12px 9px;
    text-align: center;
    background: #fff;
    color: #64748b;
    font-size: 10px;
}

@media (max-width: 480px) {
    #mb-chatbot {
        right: 14px;
        bottom: 14px;
    }

    #mb-chat-panel {
        position: fixed;
        right: 12px;
        bottom: 84px;
        width: calc(100vw - 24px);
        max-width: none;
        height: min(500px, calc(100dvh - 110px));
    }

    #mb-chat-toggle {
        width: 56px;
        height: 56px;
    }
}
</style>

<div id="mb-chatbot">
    <section id="mb-chat-panel" aria-label="MediBook chatbot"
             aria-hidden="true">

        <div class="mb-chat-header">
            <div class="mb-chat-avatar">
                <img src="<?= h($chatbotLogo) ?>"
                     alt="MediBook Logo"
                     class="mb-chat-logo">
            </div>

            <div class="mb-chat-header-info">
                <strong>MediBook Assistant</strong>
                <small><i class="bi bi-circle-fill"></i> Here to help you</small>
            </div>

            <button id="mb-chat-close" type="button"
                    aria-label="Close chatbot">&times;</button>
        </div>

        <div id="mb-chat-messages" aria-live="polite">
            <div class="mb-chat-message mb-chat-bot">Hello! 👋 Welcome to MediBook.

How can I help you today? Ask me about doctors, clinics, specializations, or appointments.</div>
        </div>

        <div class="mb-chat-suggestions">
            <button type="button" data-question="Show doctors">Find doctors</button>
            <button type="button" data-question="Show specializations">Specializations</button>
            <button type="button" data-question="How do I book an appointment?">Book appointment</button>
            <button type="button" data-question="Show clinics">Clinics</button>
        </div>

        <form id="mb-chat-form">
            <input id="mb-chat-input" type="text"
                   placeholder="Ask me anything..."
                   maxlength="500" autocomplete="off"
                   aria-label="Type your question" required>

            <button id="mb-chat-send" type="submit"
                    aria-label="Send message">
                <i class="bi bi-send-fill"></i>
            </button>
        </form>

        <div class="mb-chat-note">
            MediBook Assistant · Automated responses
        </div>
    </section>

    <button id="mb-chat-toggle" type="button"
            aria-label="Open MediBook chatbot"
            aria-expanded="false">
        <i class="bi bi-chat-dots-fill"></i>
    </button>
</div>

<script>
(function () {
    const toggle = document.getElementById('mb-chat-toggle');
    const panel = document.getElementById('mb-chat-panel');
    const close = document.getElementById('mb-chat-close');
    const form = document.getElementById('mb-chat-form');
    const input = document.getElementById('mb-chat-input');
    const messages = document.getElementById('mb-chat-messages');
    const send = document.getElementById('mb-chat-send');

    const chatbotApiUrl = <?= json_encode(
        $chatbotApi,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;

    const suggestions = document.querySelectorAll(
        '#mb-chatbot [data-question]'
    );

    function openChat() {
        panel.classList.add('mb-chat-open');
        panel.setAttribute('aria-hidden', 'false');
        toggle.setAttribute('aria-expanded', 'true');
        input.focus();
    }

    function closeChat() {
        panel.classList.remove('mb-chat-open');
        panel.setAttribute('aria-hidden', 'true');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<i class="bi bi-chat-dots-fill"></i>';
    }

    toggle.addEventListener('click', function () {
        if (panel.classList.contains('mb-chat-open')) {
            closeChat();
        } else {
            openChat();
        }
    });

    close.addEventListener('click', closeChat);

    function addMessage(text, type) {
        const bubble = document.createElement('div');
        bubble.className = 'mb-chat-message mb-chat-' + type;
        bubble.textContent = text;

        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;

        return bubble;
    }

    async function sendMessage(question) {
        question = question.trim();

        if (!question || send.disabled) return;

        openChat();
        addMessage(question, 'user');
        input.value = '';
        send.disabled = true;

        const waiting = addMessage('Please wait...', 'bot');

        try {
            const response = await fetch(chatbotApiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ message: question })
            });

            const data = await response.json();

            waiting.textContent = data.reply ||
                'Sorry, I could not find an answer.';

            if (!response.ok && !data.reply) {
                waiting.textContent =
                    'Sorry, the chatbot is temporarily unavailable.';
            }

        } catch (error) {
            waiting.textContent =
                'Could not connect to the chatbot. Please check that Apache and MongoDB are running, then try again.';

        } finally {
            send.disabled = false;
            input.focus();
            messages.scrollTop = messages.scrollHeight;
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        sendMessage(input.value);
    });

    suggestions.forEach(function (button) {
        button.addEventListener('click', function () {
            sendMessage(button.dataset.question);
        });
    });

})();
</script>