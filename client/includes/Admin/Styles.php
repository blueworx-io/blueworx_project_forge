<?php
/**
 * How the client's work views are dressed.
 *
 * @package Blueworx\Forge\Client
 */

declare( strict_types = 1 );

namespace Blueworx\Forge\Client\Admin;

/**
 * The styling for the three work views (#128), as an inline stylesheet.
 *
 * Inline rather than a file for a reason worth writing down: what the client
 * artifact may contain is a closed list in bin/artifacts.json, and adding a
 * stylesheet would mean widening that list. The rule that a client site cannot
 * physically hold studio code is worth more than the tidiness of a .css file,
 * so this rides along on the token stylesheet the artifact already ships.
 *
 * Everything here is drawn from the token layer. Nothing invents a colour: the
 * client's views are the same design system as the studio's, which is the point
 * of shipping tokens rather than a compiled sheet (#85).
 */
final class Styles {

	/**
	 * The stylesheet.
	 *
	 * @return string
	 */
	public static function css(): string {
		return <<<'CSS'
.bwx-work { margin-top: 1rem; }

.bwx-columns {
	display: flex;
	gap: 1rem;
	align-items: flex-start;
	overflow-x: auto;
	padding-bottom: 1rem;
}

.bwx-column {
	flex: 0 0 var(--board-column-width, 17rem);
	background: var(--surface-muted, #f6f7f7);
	border: 1px solid var(--border-subtle, #dcdcde);
	border-radius: var(--radius-cards, 8px);
	padding: 0.75rem;
}

.bwx-column-head {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	gap: 0.5rem;
	margin: 0 0 0.75rem;
	font-size: var(--text-heading-sm, 0.9rem);
	color: var(--text-primary, #1d2327);
}

.bwx-column-count { color: var(--text-muted, #646970); font-variant-numeric: tabular-nums; }

.bwx-card {
	background: var(--surface-card, #fff);
	border: 1px solid var(--border-subtle, #dcdcde);
	border-radius: var(--radius-cards, 8px);
	box-shadow: var(--shadow-xs, 0 1px 2px rgba(0, 0, 0, 0.06));
	padding: var(--card-padding, 0.75rem);
	margin-bottom: 0.6rem;
}

.bwx-card:last-child { margin-bottom: 0; }
.bwx-card-title { margin: 0 0 0.25rem; font-size: var(--text-body, 0.95rem); line-height: 1.35; }
.bwx-card-stage { margin: 0 0 0.4rem; color: var(--text-muted, #646970); font-size: var(--text-small, 0.8rem); }

.bwx-card-dates,
.bwx-card-people { margin: 0.35rem 0 0; padding: 0; list-style: none; font-size: var(--text-small, 0.8rem); }
.bwx-card-dates li,
.bwx-card-people li { display: flex; gap: 0.4rem; justify-content: space-between; }
.bwx-card-key { color: var(--text-muted, #646970); }
.bwx-card-value { color: var(--text-primary, #1d2327); text-align: right; }

.bwx-empty { color: var(--text-muted, #646970); font-style: italic; margin: 0; }

.bwx-timeline { border: 1px solid var(--border-subtle, #dcdcde); border-radius: var(--radius-cards, 8px); background: var(--surface-card, #fff); padding: 0.75rem; }
.bwx-timeline-scale { display: flex; justify-content: space-between; color: var(--text-muted, #646970); font-size: var(--text-small, 0.8rem); margin-bottom: 0.5rem; }
.bwx-timeline-row { display: grid; grid-template-columns: minmax(8rem, 18rem) 1fr; gap: 0.75rem; align-items: center; padding: 0.3rem 0; }
.bwx-timeline-label { font-size: var(--text-small, 0.85rem); }
.bwx-timeline-track { position: relative; height: 1.5rem; background: var(--surface-muted, #f6f7f7); border-radius: var(--radius-pills, 999px); }
.bwx-timeline-bar { position: absolute; top: 0.25rem; height: 1rem; min-width: 0.35rem; background: var(--surface-action, #2271b1); border-radius: var(--radius-pills, 999px); }
.bwx-timeline-today { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--color-coral, #d63638); }

.bwx-calendar { width: 100%; border-collapse: collapse; table-layout: fixed; background: var(--surface-card, #fff); }
.bwx-calendar th, .bwx-calendar td { border: 1px solid var(--border-subtle, #dcdcde); vertical-align: top; padding: 0.4rem; }
.bwx-calendar th { background: var(--surface-muted, #f6f7f7); font-size: var(--text-small, 0.8rem); text-align: left; }
.bwx-calendar td { height: 6rem; }
.bwx-calendar-outside { background: var(--surface-muted, #f6f7f7); }
.bwx-calendar-daynum { display: block; color: var(--text-muted, #646970); font-size: var(--text-small, 0.75rem); margin-bottom: 0.25rem; }
.bwx-calendar-entry { display: block; font-size: var(--text-small, 0.75rem); line-height: 1.3; margin-bottom: 0.2rem; }
.bwx-calendar-kind { color: var(--text-muted, #646970); }

.bwx-months { display: flex; gap: 0.5rem; align-items: center; margin: 0 0 0.75rem; }
.bwx-undated { margin-top: 1.5rem; }
CSS;
	}
}
