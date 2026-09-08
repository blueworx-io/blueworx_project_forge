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
	display: flex;
	flex-direction: column;
	gap: 0.6rem;
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

.bwx-empty { color: var(--text-muted, #646970); font-style: italic; margin: 0; }

/* Two things the design system's Gantt has no pattern for, both ours to keep:
   the end tick made to read as a right-hand edge rather than a left-aligned
   label (the component's ruler is built for many evenly-spaced ticks, not a
   from/to pair), and a "today" marker, which the system does not have at all. */
.bwx-timeline-tick--end { text-align: right; }
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

.bwx-lede { margin: 0 0 0.25rem; font-size: var(--text-heading, 1.15rem); color: var(--text-primary, #1d2327); }
.bwx-undated { margin-top: 1.5rem; }

/* What you asked for (#130). An exchange, not a table: the request, then the
   reply set in under it. Each entry is a bw-card; this only adds the gap
   between them, since bw-card carries no margin of its own. */
.bwx-asked { max-width: var(--content-max-width, 60rem); margin-top: 1rem; }
.bwx-asked > .bw-card { margin: 0 0 0.75rem; }
.bwx-asked > .bw-card:last-child { margin-bottom: 0; }

.bwx-asked-meta { margin: 0.15rem 0 0; color: var(--text-muted, #646970); font-size: var(--text-small, 0.8rem); }
.bwx-asked-words { margin: 0.6rem 0 0; }
.bwx-asked-words p { margin: 0 0 0.4rem; }
.bwx-asked-words p:last-child { margin-bottom: 0; }

/*
 * The conversation on one item (#133). The client's own entries are indented
 * and ruled, the studio's are not — a letter and its reply. Both are a
 * bw-card now; this only adds the asymmetry between the two parties.
 */
.bwx-thread-entry[data-bwx-from="client"] {
	margin-left: 1.25rem;
	border-left: 3px solid var(--bw-brand);
}

/* An outstanding question is the one thing on this page somebody has to act
   on, so it is the one thing that is coloured. */
.bwx-question {
	border-left: 3px solid var(--bw-danger);
}

[data-testid="bwx-questions"] form { margin-top: 0.6rem; }
.wrap.bw-wrap { margin: 0; }
body[class*="blueworx-forge-client"] #wpcontent { padding-left: 0; }
body[class*="blueworx-forge-client"] #wpbody-content { padding-bottom: 0; }
body[class*="blueworx-forge-client"] #wpfooter { display: none; }
CSS;
	}
}
