<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/narrative.php';
require_once __DIR__ . '/includes/coach_ai.php';
require_login();
$user = current_user();
$uid = (int)$user['id'];
$page_title = 'Ask Sprout';
$nav = 'overview';

// ============================================================
// ASK SPROUT — the question box over your own data.
//
// The engine underneath is UNCHANGED: coach_chat.php still gathers about
// 45 real figures server-side and hands them to the same writer, which
// may only repeat numbers it was given. This file is the presentation
// layer around that, moved onto the new design.
//
// The rule-based summary that used to be the whole page is still here,
// below the conversation — it needs no AI and is always available.
// ============================================================

$chat_history = coach_history($pdo, $uid, 60);
$ai_on = hf_configured();

$today   = nar_today($pdo, $uid);
$changes = nar_changes($pdo, $uid, 3);
$first   = htmlspecialchars(explode(' ', trim($user['full_name']))[0]);

// The suggested questions are only offered when the data behind them
// actually exists — an empty answer is a worse first impression than no
// suggestion at all.
$suggestions = ['How am I doing this week?'];
$fin = get_forecast($pdo, $uid, 'finance');
if ($fin && ($fin['status'] ?? '') === 'ok') {
    $suggestions[] = 'Why did my spending change this month?';
    $suggestions[] = 'Can I reach my savings goal?';
}
if (nar_growth($pdo, $uid)['checkins'] > 0) $suggestions[] = 'How are my streaks?';
$suggestions[] = "What's affecting my productivity?";
$suggestions[] = 'What should I change?';

require_once __DIR__ . '/includes/shell.php';
?>

<header class="hero">
    <div class="hero-in">
        <div>
            <p class="hi">Ask Sprout</p>
            <h1>Ask me anything about <em>your</em> data.</h1>
            <p class="hero-sub">
                I can see what you've logged — habits, sleep, focus time and money — and nothing else.
                If I don't have something, I'll say so rather than make it up.
            </p>
        </div>
    </div>
</header>

<div class="wrap">

<section class="ch enter" style="padding-top:44px;">
    <div class="ask">
        <div class="ask-log" id="log">
            <?php if (empty($chat_history)): ?>
            <div class="ask-empty" id="empty">
                <h3>What would you like to know, <?= $first ?>?</h3>
                <p>Pick one below, or ask in your own words.</p>
            </div>
            <?php else: foreach ($chat_history as $m): ?>
            <div class="msg <?= $m['role'] === 'user' ? 'mine' : 'theirs' ?>">
                <?php if ($m['role'] === 'assistant'): ?><div class="msg-av">✦</div><?php endif; ?>
                <div class="bub">
                    <?= nl2br(htmlspecialchars($m['content'])) ?>
                    <?php if ($m['role'] === 'assistant' && $m['source'] === 'fallback'): ?>
                        <span class="bub-tag">written by Sprout</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="ask-sugg" id="sugg">
            <?php foreach ($suggestions as $q): ?>
            <button type="button"><?= htmlspecialchars($q) ?></button>
            <?php endforeach; ?>
        </div>

        <form class="ask-row" id="form" autocomplete="off">
            <input type="text" id="q" maxlength="<?= COACH_MAX_QUESTION ?>"
                   placeholder="Ask about your habits, sleep, focus or money…"
                   aria-label="Ask Sprout a question">
            <button type="submit" class="btn btn-go" id="send">Ask</button>
        </form>

        <div class="ask-foot">
            <span><?= $ai_on
                ? 'Answers are written from your already-calculated numbers — nothing is invented.'
                : 'No AI key set up, so Sprout writes these itself. Still your real numbers, just plainer wording.' ?></span>
            <button id="clear" style="<?= empty($chat_history) ? 'display:none;' : '' ?>">Clear this conversation</button>
        </div>
    </div>
</section>

<!-- ---------- The always-on summary ---------- -->
<?php if ($changes || $today['at_risk']): ?>
<section class="ch enter">
    <div class="ch-head"><h2>While you're here</h2></div>
    <p class="ch-lead">A few things worth knowing, worked out from your own data. No question needed.</p>

    <div class="grid g-2">
        <?php foreach ($changes as $i => $c): ?>
        <article class="ins <?= $c['tone'] ?> enter" data-delay="<?= $i * 70 ?>">
            <p class="ins-k"><?= htmlspecialchars($c['kicker']) ?></p>
            <h3 class="ins-h"><?= htmlspecialchars($c['headline']) ?></h3>
            <p class="ins-b"><?= htmlspecialchars($c['body']) ?></p>
        </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

</div><!-- /wrap -->

<script>
/* ------------------------------------------------------------------
   The browser only ever sends the question text. Every number in the
   answer is gathered server-side by coach_chat.php, so nothing here can
   feed Sprout figures that aren't real.
   ------------------------------------------------------------------ */
(function () {
    const log  = document.getElementById('log');
    const form = document.getElementById('form');
    const q    = document.getElementById('q');
    const send = document.getElementById('send');
    const sugg = document.getElementById('sugg');
    const clear = document.getElementById('clear');
    if (!form) return;

    const esc = s => String(s).replace(/[&<>"']/g, c =>
        ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const down = () => { log.scrollTop = log.scrollHeight; };

    function add(role, text, tag) {
        document.getElementById('empty')?.remove();
        const el = document.createElement('div');
        el.className = 'msg ' + (role === 'user' ? 'mine' : 'theirs');
        el.innerHTML = (role === 'user' ? '' : '<div class="msg-av">✦</div>') +
            '<div class="bub">' + esc(text).replace(/\n/g, '<br>') +
            (tag ? '<span class="bub-tag">' + esc(tag) + '</span>' : '') + '</div>';
        log.appendChild(el); down(); return el;
    }

    function thinking() {
        const el = document.createElement('div');
        el.className = 'msg theirs';
        el.innerHTML = '<div class="msg-av">✦</div><div class="bub dots"><span></span><span></span><span></span></div>';
        log.appendChild(el); down(); return el;
    }

    async function ask(text) {
        add('user', text);
        q.value = '';
        q.disabled = send.disabled = true;
        send.textContent = '…';
        const wait = thinking();

        try {
            const res = await fetch('coach_chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ message: text }).toString()
            });
            const d = await res.json();
            wait.remove();
            if (!d.ok) {
                add('assistant', d.error || 'That did not work. Try asking again.');
            } else {
                add('assistant', d.text, d.source === 'fallback' ? 'written by Sprout' : null);
                if (clear) clear.style.display = '';
            }
        } catch (e) {
            wait.remove();
            add('assistant', "I couldn't reach the server just then. Everything you've logged is safe — try again in a moment.");
        } finally {
            q.disabled = send.disabled = false;
            send.textContent = 'Ask';
            q.focus();
        }
    }

    form.addEventListener('submit', e => {
        e.preventDefault();
        const t = q.value.trim();
        if (t) ask(t);
    });

    sugg?.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (b && !q.disabled) ask(b.textContent.trim());
    });

    clear?.addEventListener('click', async () => {
        if (!confirm('Clear this conversation? Nothing you have logged is affected.')) return;
        await fetch('coach_chat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'clear=1'
        });
        log.innerHTML = '<div class="ask-empty" id="empty"><h3>Cleared.</h3>' +
                        '<p>Ask me something new whenever you like.</p></div>';
        clear.style.display = 'none';
    });

    // Arriving from a question asked elsewhere (the Overview's Ask box
    // links here with ?q=...), so the answer appears without the person
    // having to type it a second time.
    const params = new URLSearchParams(location.search);
    const incoming = (params.get('q') || '').trim();
    if (incoming) {
        history.replaceState({}, '', 'coach.php');
        ask(incoming);
    }

    down();
})();
</script>

<?php require_once __DIR__ . '/includes/shell_end.php'; ?>
