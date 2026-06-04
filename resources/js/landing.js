/**
 * landing.js — Animations GSAP ScrollTrigger pour la landing page.
 * GSAP + ScrollTrigger sont chargés via CDN dans le template.
 */

document.addEventListener('DOMContentLoaded', () => {
    const noMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (noMotion || typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') {
        // Rendre tous les éléments visibles immédiatement sans animation
        document.querySelectorAll('.anim-hidden').forEach(el => {
            el.style.opacity = '1';
            el.style.transform = 'none';
        });
        return;
    }

    gsap.registerPlugin(ScrollTrigger);

    // ── HERO : entrée initiale ──────────────────────────────────────────────
    const tl = gsap.timeline({ delay: 0.2 });
    tl.from('#hero-eyebrow', { opacity: 0, y: -20, duration: 0.5 })
      .from('#hero-title',   { opacity: 0, y: 30,  duration: 0.7, ease: 'power2.out' }, '-=0.2')
      .from('#hero-sub',     { opacity: 0, y: 20,  duration: 0.5 }, '-=0.3')
      .from('#hero-cta',     { opacity: 0, scale: 0.9, duration: 0.4, ease: 'back.out(1.6)' }, '-=0.2');

    // ── HERO : parallaxe au scroll ──────────────────────────────────────────
    gsap.to('#hero-content', {
        y: -100,
        ease: 'none',
        scrollTrigger: {
            trigger: '#hero',
            start:   'top top',
            end:     'bottom top',
            scrub:   true,
        },
    });

    // Léger zoom out sur le fond hero
    gsap.to('#hero-bg', {
        scale: 1.08,
        ease: 'none',
        scrollTrigger: {
            trigger: '#hero',
            start:   'top top',
            end:     'bottom top',
            scrub:   true,
        },
    });

    // ── SECTION EXPLICATION : fade in stagger ──────────────────────────────
    gsap.from('.explain-block', {
        opacity:  0,
        y:        40,
        duration: 0.6,
        stagger:  0.15,
        ease:     'power2.out',
        scrollTrigger: {
            trigger: '#section-explain',
            start:   'top 80%',
        },
    });

    // ── SECTION RÔLES : slide up stagger ───────────────────────────────────
    gsap.from('.role-card', {
        opacity:  0,
        y:        60,
        duration: 0.55,
        stagger:  0.12,
        ease:     'power2.out',
        scrollTrigger: {
            trigger: '#section-roles',
            start:   'top 75%',
        },
    });

    // ── CTA FINALE ──────────────────────────────────────────────────────────
    gsap.from('#section-cta > *', {
        opacity:  0,
        y:        30,
        duration: 0.6,
        stagger:  0.1,
        ease:     'power2.out',
        scrollTrigger: {
            trigger: '#section-cta',
            start:   'top 85%',
        },
    });
});
