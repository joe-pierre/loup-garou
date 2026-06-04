@props([
    'channel'  => 'general',
    'readonly' => false,
    'gameId',
])

@php
    $isWolf     = $channel === 'werewolves';
    $accentColor = $isWolf ? '#f87171' : '#c9a84c';
    $echoChannel = $isWolf
        ? "window.Echo.private('game.{$gameId}.werewolves')"
        : "window.Echo.channel('game.{$gameId}')";
    $eventName  = $isWolf ? '.werewolf.chat.message' : '.chat.message.sent';
    $maxLength  = 200;
@endphp

<div
    x-data="{
        messages:   [],
        input:      '',
        sending:    false,
        maxLength:  {{ $maxLength }},

        get charCount()   { return this.input.length; },
        get charWarning() { return this.charCount >= 180; },
        get charDanger()  { return this.charCount >= 195; },
        get canSend()     { return this.input.trim().length > 0 && this.charCount <= this.maxLength && !this.sending; },

        init() {
            {{ $echoChannel }}
                .listen('{{ $eventName }}', (data) => {
                    if (@js($isWolf ? true : false) || data.channel === 'general') {
                        this.messages.push(data);
                        this.scrollToBottom();
                    }
                });
        },

        scrollToBottom() {
            this.$nextTick(() => {
                const el = this.$refs.chatLog;
                if (el) el.scrollTop = el.scrollHeight;
            });
        },

        async sendMessage() {
            if (!this.canSend) return;
            const msg = this.input.trim();
            this.input   = '';
            this.sending = true;
            try {
                const res = await fetch('/game/{{ $gameId }}/chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/json',
                        'Accept':           'application/json',
                        'X-CSRF-TOKEN':     document.querySelector('meta[name=csrf-token]').content,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ message: msg, channel: '{{ $channel }}' }),
                });
                if (!res.ok) this.input = msg;
            } catch {
                this.input = msg;
            } finally {
                this.sending = false;
            }
        },
    }"
    x-init="init()"
    class="flex flex-col h-full"
>
    {{-- Zone messages --}}
    <div
        x-ref="chatLog"
        class="flex-1 overflow-y-auto px-3 py-2 space-y-1.5"
        style="min-height:0;"
        role="log"
        aria-live="polite"
        aria-label="Messages {{ $isWolf ? 'des loups' : 'généraux' }}"
    >
        <template x-for="(msg, i) in messages" :key="i">
            <p class="text-sm leading-snug">
                <span
                    class="font-medieval font-semibold mr-1"
                    :style="'color:{{ $accentColor }}'"
                    x-text="msg.pseudo + ' :'"
                ></span>
                <span style="color:#e8e0d0;" x-text="msg.message"></span>
            </p>
        </template>

        <p
            x-show="messages.length === 0"
            class="text-xs text-center py-4"
            style="color:rgba(232,224,208,0.3);"
        >Aucun message pour l'instant.</p>
    </div>

    {{-- Input ou message lecture seule --}}
    @if(!$readonly)
    <div class="flex-shrink-0 p-2 border-t" style="border-color:rgba(255,255,255,0.06);">
        <div class="flex gap-2 items-end">
            <div class="flex-1">
                <textarea
                    x-model="input"
                    @keydown.enter.prevent="sendMessage()"
                    :maxlength="maxLength"
                    rows="1"
                    placeholder="Votre message…"
                    class="w-full resize-none rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[#c9a84c]"
                    style="background-color:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08);
                           color:#e8e0d0; min-height:2.25rem; max-height:6rem;"
                    aria-label="Saisir un message"
                    :disabled="sending"
                ></textarea>
                <div
                    x-show="charCount >= 150"
                    class="text-xs mt-0.5 text-right tabular-nums"
                    :style="charDanger ? 'color:#ef4444' : (charWarning ? 'color:#f97316' : 'color:rgba(232,224,208,0.4)')"
                    x-text="charCount + '/{{ $maxLength }}'"
                ></div>
            </div>
            <button
                @click="sendMessage()"
                :disabled="!canSend"
                class="flex-shrink-0 px-3 py-2 rounded-lg font-medieval text-xs font-semibold
                       disabled:opacity-30 transition-opacity hover:opacity-80 focus:ring-2 focus:ring-[#c9a84c] focus:outline-none"
                style="background-color:{{ $accentColor }}; color:#0a0f1e;"
                aria-label="Envoyer le message"
            >
                <span x-show="!sending">Envoyer</span>
                <span x-show="sending">…</span>
            </button>
        </div>
    </div>
    @else
    <div
        class="flex-shrink-0 px-3 py-2.5 border-t text-sm text-center italic"
        style="border-color:rgba(255,255,255,0.06); color:rgba(232,224,208,0.3);"
        role="note"
    >
        @if($isWolf)
            🐺 Lecture seule — tu es mort.
        @else
            💀 Lecture seule — tu observes en silence.
        @endif
    </div>
    @endif
</div>
