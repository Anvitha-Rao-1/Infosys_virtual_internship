    </main>
</div>

<script>
/* ------------------------------------------------------------------
   Shell behaviour. Deliberately small: an account menu, one scroll
   reveal, and count-ups. Motion here answers something the person did,
   or runs once on arrival — never a permanent loop.
   ------------------------------------------------------------------ */
(function () {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---- account menu ---- */
    const btn = document.getElementById('meBtn');
    const menu = document.getElementById('meMenu');
    if (btn && menu) {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const open = menu.classList.toggle('open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', e => {
            if (!menu.contains(e.target) && !btn.contains(e.target)) {
                menu.classList.remove('open');
                btn.setAttribute('aria-expanded', 'false');
            }
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { menu.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    /* ---- chapters enter once, then stop being animated ----
       Reserved for section-level arrivals rather than every card, so the
       page settles instead of twitching all the way down.               */
    const targets = document.querySelectorAll('.enter');
    if (reduced || !('IntersectionObserver' in window)) {
        targets.forEach(el => el.classList.add('in'));
    } else {
        const io = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                const el = entry.target;
                el.style.animationDelay = (el.dataset.delay || 0) + 'ms';
                el.classList.add('in');
                obs.unobserve(el);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
        targets.forEach(el => io.observe(el));
    }

    /* ---- numbers count up as they arrive ---- */
    document.querySelectorAll('[data-count]').forEach(el => {
        const end = parseFloat(el.dataset.count);
        if (Number.isNaN(end)) return;
        const dp = parseInt(el.dataset.dp || '0', 10);
        const pre = el.dataset.pre || '', post = el.dataset.post || '';
        const show = v => el.textContent = pre + v.toLocaleString(undefined,
            { minimumFractionDigits: dp, maximumFractionDigits: dp }) + post;

        if (reduced) { show(end); return; }
        show(0);
        const run = () => {
            const t0 = performance.now(), dur = 1100;
            const step = now => {
                const p = Math.min(1, (now - t0) / dur);
                show(end * (1 - Math.pow(1 - p, 3)));
                if (p < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        };
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver((es, obs) => {
                es.forEach(e => { if (e.isIntersecting) { run(); obs.unobserve(e.target); } });
            }, { threshold: 0.5 });
            io.observe(el);
        } else { run(); }
    });

    /* ---- the sprout grows when you reach it ---- */
    document.querySelectorAll('[data-grow]').forEach(el => {
        if (reduced) { el.classList.add('grown'); return; }
        if (!('IntersectionObserver' in window)) { el.classList.add('grown'); return; }
        const io = new IntersectionObserver((es, obs) => {
            es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('grown'); obs.unobserve(e.target); } });
        }, { threshold: 0.35 });
        io.observe(el);
    });

    /* ---- progress rings fill on arrival ---- */
    document.querySelectorAll('.ring .fg').forEach(c => {
        const to = c.dataset.offset;
        if (to === undefined) return;
        const len = c.getAttribute('stroke-dasharray');
        c.style.strokeDashoffset = reduced ? to : len;
        if (reduced) return;
        const io = new IntersectionObserver((es, obs) => {
            es.forEach(e => {
                if (!e.isIntersecting) return;
                setTimeout(() => { c.style.strokeDashoffset = to; }, 180);
                obs.unobserve(e.target);
            });
        }, { threshold: 0.4 });
        io.observe(c.closest('.ring'));
    });
})();
</script>
<script src="js/app.js"></script>
</body>
</html>
