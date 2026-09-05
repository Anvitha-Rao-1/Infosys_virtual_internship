// ============================================================
// Sprout — front-end behaviour (vanilla JS, no frameworks)
// ============================================================

function showToast(msg) {
    let toast = document.querySelector('.toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.className = 'toast';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.classList.add('show');
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => toast.classList.remove('show'), 2200);
}

// Show "added" success toast if redirected with ?added=1
(function checkAddedFlag() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('added') === '1') {
        showToast('Goal added 🌱');
        params.delete('added');
        const clean = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
        window.history.replaceState({}, '', clean);
    }
})();

// Close modal when clicking the dark overlay itself
document.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('show');
    }
});

// Day-box check-in toggling (works on Dashboard's check-in pills and
// Habits' week-check grid — same endpoint, same handler either way)
document.addEventListener('click', async (e) => {
    const box = e.target.closest('.day-box');
    if (!box || box.classList.contains('future')) return;

    const goalId = box.dataset.goal;
    const date = box.dataset.date;
    box.style.pointerEvents = 'none';

    try {
        const res = await fetch('toggle_log.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `goal_id=${encodeURIComponent(goalId)}&date=${encodeURIComponent(date)}`
        });
        const data = await res.json();
        if (data.error) { showToast(data.error); box.style.pointerEvents = ''; return; }

        box.classList.toggle('done', data.done);
        showToast(data.done ? 'Marked as done ✅' : 'Check-in removed');

        if (data.newly_earned && data.newly_earned.length > 0) {
            data.newly_earned.forEach((code, i) => {
                setTimeout(() => showToast('🏆 Achievement unlocked!'), 900 + (i * 1400));
            });
        }

        // Update the ring for this goal card, if present
        const card = box.closest('.goal-card');
        if (card) {
            const ringNum = card.querySelector('.ring-num');
            const ringFg = card.querySelector('.ring-fg');
            if (ringNum) ringNum.textContent = data.week_percent + '%';
            if (ringFg) {
                const circumference = 2 * Math.PI * 24;
                const offset = circumference - (data.week_percent / 100) * circumference;
                ringFg.style.strokeDashoffset = offset;
            }
        }
    } catch (err) {
        showToast('Something went wrong. Try again.');
    } finally {
        box.style.pointerEvents = '';
    }
});

/* ============================================================
   Milestone 2 additions — motion effects + info tooltips used on
   the Forecast page (and anywhere else that opts in with the same
   class names). Kept generic/reusable rather than page-specific.
   ============================================================ */

// Progress bars / chart bars that already carry their final inline
// width from PHP: animate them growing in from 0 on page load.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.grow-in').forEach(el => {
        const target = el.style.width;
        if (!target) return;
        el.style.width = '0%';
        requestAnimationFrame(() => requestAnimationFrame(() => { el.style.width = target; }));
    });

    // Animated count-up for headline numbers: <span class="count-up"
    // data-target="78" data-suffix="%">0</span>
    document.querySelectorAll('.count-up').forEach(el => {
        const target = parseFloat(el.dataset.target || '0');
        if (Number.isNaN(target)) return;
        const prefix = el.dataset.prefix || '';
        const suffix = el.dataset.suffix || '';
        const decimals = el.dataset.decimals ? parseInt(el.dataset.decimals, 10) : 0;
        const duration = 900;
        const startTime = performance.now();
        function tick(now) {
            const t = Math.min(1, (now - startTime) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            const val = target * eased;
            el.textContent = prefix + val.toLocaleString(undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals }) + suffix;
            if (t < 1) requestAnimationFrame(tick);
        }
        requestAnimationFrame(tick);
    });
});

// "i" info dots (what-does-this-mean tooltips): click to open, click
// anywhere else to close. Keyboard-focusable via tabindex in the markup.
document.addEventListener('click', (e) => {
    document.querySelectorAll('.info-dot.open').forEach(dot => {
        if (!dot.contains(e.target)) dot.classList.remove('open');
    });
});

/* ============================================================
   IA redesign — generic tab panels (Insights hub, Finance) — no
   page reload, no framework. Markup shape:
     <div class="tab-group">
       <div class="seg-tabs">
         <button class="active" data-tab-target="overview">Overview</button>
         <button data-tab-target="trends">Trends</button>
       </div>
       <div class="tab-panel active" data-tab-panel="overview">...</div>
       <div class="tab-panel" data-tab-panel="trends">...</div>
     </div>
   Deep-links via the URL hash (e.g. finance.php#forecast) select the
   matching tab on load, and switching tabs updates the hash (via
   replaceState, so it doesn't pollute back-button history).
   ============================================================ */
function activateTab(group, target) {
    const tabs = group.querySelector(':scope > .seg-tabs');
    if (tabs) tabs.querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.tabTarget === target));
    group.querySelectorAll(':scope > .tab-panel').forEach(p => p.classList.toggle('active', p.dataset.tabPanel === target));
}

document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-tab-target]');
    if (!btn) return;
    const group = btn.closest('.tab-group');
    if (!group) return;
    activateTab(group, btn.dataset.tabTarget);
    if (group.id) history.replaceState(null, '', '#' + btn.dataset.tabTarget);
});

document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    if (!hash) return;
    document.querySelectorAll('.tab-group').forEach(group => {
        if (group.querySelector(`[data-tab-panel="${hash}"]`)) activateTab(group, hash);
    });
});
