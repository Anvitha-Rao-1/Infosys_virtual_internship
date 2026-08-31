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

// Day-box check-in toggling (works on dashboard, category pages, profile)
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
