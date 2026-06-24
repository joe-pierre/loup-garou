<div
    x-data="announcementOverlay()"
    x-show="active"
    x-cloak
    class="fixed inset-0 z-60 flex items-center justify-center bg-black/90 backdrop-blur-sm"
    style="pointer-events: all;"
>
    <div class="text-center text-[#e8e0d0] px-6">
        <p x-text="message" class="announcement-text text-2xl md:text-4xl font-cinzel tracking-widest"></p>
    </div>
</div>

<script>
function announcementOverlay() {
    return {
        active: false,
        message: '',
        _timer: null,

        init() {
            window.addEventListener('show-announcement', (e) => {
                const { message, durationMs } = e.detail;
                this.message = message;
                this.active = true;

                if (this._timer) clearTimeout(this._timer);

                if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                    gsap.fromTo('.announcement-text',
                        { opacity: 0, y: 20 },
                        { opacity: 1, y: 0, duration: 0.3, ease: 'power2.out' }
                    );
                }

                this._timer = setTimeout(() => {
                    if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                        gsap.to('.announcement-text', {
                            opacity: 0, duration: 0.25, ease: 'power2.in',
                            onComplete: () => {
                                this.active = false;
                                window.dispatchEvent(new CustomEvent('announcement-done'));
                            }
                        });
                    } else {
                        this.active = false;
                        window.dispatchEvent(new CustomEvent('announcement-done'));
                    }
                }, durationMs);
            });
        }
    };
}
</script>
