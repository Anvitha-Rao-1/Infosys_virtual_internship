<?php
/**
 * includes/chart_card.php
 * ------------------------------------------------------------------
 * ONE reusable "chart card" pattern (UX/IA redesign, Phase 2): a title
 * row with an optional info-dot, an optional one-line plain-English
 * note, a chart body of consistent size, and a consistent empty-state
 * — used for every chart on every page, regardless of which
 * svg_*_chart() helper in includes/helpers.php produced the markup
 * passed in as $opts['content'].
 *
 * This file only wraps and styles pre-rendered chart HTML. It never
 * runs a query or computes a number — every svg_*_chart() call and
 * every bit of math still lives entirely in includes/helpers.php,
 * untouched. chart_card() just replaces the hand-rolled
 * "<div class="bento-cell ..."><h4>...<span class="info-dot">...
 * <?php if (empty(...)): ?><div class="empty-state">...<?php else: ?>
 * <?= svg_x_chart(...) ?><?php endif; ?></div>" block that used to be
 * copy-pasted, slightly differently, on every page.
 *
 * Usage:
 *   <?= chart_card([
 *       'title'   => 'Habit heatmap · last 12 weeks',
 *       'info'    => 'Each square is one day...',   // optional
 *       'span'    => 4,                              // bento-grid span, 1-4
 *       'note'    => 'Solid = actual...',            // optional .chart-note line
 *       'content' => $has_data ? $heatmap_html : null,
 *       'empty_icon'    => '📊',                      // optional, defaults to 📊
 *       'empty_message' => 'Check in on a goal to see this.',
 *       'footer'  => '<a href="...">Full forecast →</a>', // optional trailing row
 *       'chart_width'   => 1100, // optional — pass the SAME width you gave
 *                                // svg_line_chart()/svg_scatter_chart()/
 *                                // svg_grouped_bar_chart() for THIS content.
 *                                // Below the phone breakpoint the chart is
 *                                // held at that natural size and the card
 *                                // scrolls horizontally instead of letting
 *                                // width:100% squash the SVG down to where
 *                                // its (fixed, viewBox-relative) text
 *                                // becomes unreadably small. Omit for
 *                                // charts that don't need it (heatmap: a
 *                                // CSS grid, not an SVG; donut/radar: fixed
 *                                // aspect ratio, already safe).
 *   ]) ?>
 * ------------------------------------------------------------------
 */

function chart_card(array $opts): string {
    $title         = $opts['title'] ?? '';
    $info          = $opts['info'] ?? null;
    $span          = (int)($opts['span'] ?? 2);
    $tall          = !empty($opts['tall']);
    $note          = $opts['note'] ?? null;
    $content       = $opts['content'] ?? null;
    $empty_icon    = $opts['empty_icon'] ?? '📊';
    $empty_message = $opts['empty_message'] ?? 'Not enough data yet.';
    $footer        = $opts['footer'] ?? null;
    $anim          = $opts['anim'] ?? true;
    $extra_class   = $opts['class'] ?? '';
    $chart_width   = $opts['chart_width'] ?? null;

    $classes = ['bento-cell', 'chart-card'];
    if ($span > 1) $classes[] = 'span-' . $span;
    if ($tall) $classes[] = 'tall';
    if ($anim) $classes[] = 'anim-in';
    if ($extra_class !== '') $classes[] = $extra_class;

    $html = '<div class="' . implode(' ', $classes) . '">';

    if ($title !== '') {
        $html .= '<h4>' . $title;
        if ($info) {
            $html .= ' <span class="info-dot" tabindex="0" onclick="this.classList.toggle(\'open\')">i<span class="tip">' . $info . '</span></span>';
        }
        $html .= '</h4>';
    }
    if ($note) {
        $html .= '<p class="chart-note">' . $note . '</p>';
    }

    $is_empty = ($content === null || $content === false || $content === '');
    $html .= '<div class="chart-card-body' . ($is_empty ? ' is-empty' : '') . '">';
    if ($is_empty) {
        $html .= '<div class="empty-state chart-empty"><div class="em-ico">' . $empty_icon . '</div><p>' . $empty_message . '</p></div>';
    } elseif ($chart_width) {
        $html .= '<div class="chart-scroll" style="--chart-min-w:' . (int)$chart_width . 'px;">' . $content . '</div>';
    } else {
        $html .= $content;
    }
    $html .= '</div>';

    if ($footer) {
        $html .= '<div class="chart-card-footer">' . $footer . '</div>';
    }

    $html .= '</div>';
    return $html;
}
