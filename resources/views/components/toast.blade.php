<div
    x-data="{
        toasts: [],
        _buffer: [],  // toasts arrivés avant init Alpine

        init() {
            // Consommer les toasts mis en buffer avant l'init Alpine
            if (window.__toastBuffer) {
                window.__toastBuffer.forEach(detail => this.add(detail));
                window.__toastBuffer = [];
            }
            window.__toastReady = true;
            window.addEventListener('show-toast', (e) => this.add(e.detail));
        },
        add(detail) {
            const id    = Date.now() + Math.random();
            const toast = { id, message: detail.message ?? '', type: detail.type ?? 'info' };
            this.toasts.push(toast);

            // Auto-dismiss après 3.5s
            setTimeout(() => this.remove(id), 3500);
        },
        remove(id) {
            const el = document.getElementById('toast-' + id);
            if (!el) { this.toasts = this.toasts.filter(t => t.id !== id); return; }

            const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            if (!noMotion && typeof gsap !== 'undefined') {
                gsap.to(el, {
                    x: 60, opacity: 0, duration: 0.3, ease: 'power2.in',
                    onComplete: () => { this.toasts = this.toasts.filter(t => t.id !== id); },
                });
                // Fallback si l'animation GSAP ne se termine jamais
                // (ex. élément retiré du DOM par Alpine avant onComplete)
                setTimeout(() => {
                    if (this.toasts.some(t => t.id === id)) {
                        this.toasts = this.toasts.filter(t => t.id !== id);
                    }
                }, 600);
            } else {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }
        },
        colorFor(type) {
            const map = {
                error:   { bg: 'rgba(139,0,0,0.9)',    border: '#8b0000', text: '#fca5a5' },
                success: { bg: 'rgba(20,83,45,0.9)',   border: '#16a34a', text: '#86efac' },
                warning: { bg: 'rgba(120,53,15,0.9)',  border: '#f97316', text: '#fdba74' },
                info:    { bg: 'rgba(17,24,39,0.95)',  border: '#c9a84c', text: '#e8e0d0' },
            };
            return map[type] ?? map.info;
        },
    }"
    {{-- bottom-36/md:bottom-20 (au lieu de bottom-4) : laisse la place au bouton
         toggle mute audio, positionné bottom-20/md:bottom-4 right-4 (layouts/game.blade.php) --}}
    class="fixed bottom-36 md:bottom-20 right-4 z-50 flex flex-col gap-2 w-72 pointer-events-none"
    aria-live="polite"
    aria-atomic="false"
    role="status"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div
            :id="'toast-' + toast.id"
            x-init="
                const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                if (!noMotion && typeof gsap !== 'undefined') {
                    gsap.fromTo($el,
                        { x: 60, opacity: 0 },
                        { x: 0, opacity: 1, duration: 0.3, ease: 'power2.out' }
                    );
                }
            "
            class="rounded-xl px-4 py-3 text-sm shadow-xl flex items-start gap-3 pointer-events-auto cursor-pointer"
            :style="`
                background-color: ${colorFor(toast.type).bg};
                border: 1px solid ${colorFor(toast.type).border};
                color: ${colorFor(toast.type).text};
            `"
            @click="remove(toast.id)"
            role="alert"
            :aria-label="toast.message"
        >
            <span aria-hidden="true" x-text="
                toast.type === 'error'   ? '❌' :
                toast.type === 'success' ? '✅' :
                toast.type === 'warning' ? '⚠️' : 'ℹ️'
            "></span>
            <span class="flex-1 font-body leading-snug" x-text="toast.message"></span>
            <button
                @click.stop="remove(toast.id)"
                class="flex-shrink-0 opacity-50 hover:opacity-100 focus:outline-none focus:ring-2 focus:ring-current rounded"
                aria-label="Fermer la notification"
            >×</button>
        </div>
    </template>
</div>
