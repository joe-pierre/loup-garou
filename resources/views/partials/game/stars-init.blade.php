{{-- Script JS génération étoiles — à inclure via @push('scripts') --}}
<script>
(function() {
    const c = document.getElementById('stars-bg');
    if (!c) return;
    for (let i = 0; i < 80; i++) {
        const s = document.createElement('div');
        s.className = 'star';
        const z = Math.random() * 2 + 1;
        s.style.width        = z + 'px';
        s.style.height       = z + 'px';
        s.style.left         = Math.random() * 100 + '%';
        s.style.top          = Math.random() * 70  + '%';
        s.style.animationDelay = Math.random() * 4 + 's';
        c.appendChild(s);
    }
})();
</script>
