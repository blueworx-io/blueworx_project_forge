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

/* A card title that opens the item is still a heading, so it is not dressed
   as body copy with a rule under it. The underline comes back on hover, where
   it says the thing is clickable at the moment somebody is about to click. */
.bw-card__title a { text-decoration: none; }
.bw-card__title a:hover,
.bw-card__title a:focus { text-decoration: underline; }

/* A panel's one call to action, clear of whatever it follows. Left-aligned
   rather than right, unlike a form's buttons below: this is the start of
   something rather than the end of it, so it sits under the text that led to
   it instead of across the panel from it. */
.bwx-panelaction { margin: 1rem 0 0; }

/* A form's buttons sit clear of the last field's rule, and to the right of it
   — where the design system puts a card's own actions. */
.bwx-formactions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin: 1.25rem 0 0;
}

/* Three things the design system's Gantt has no pattern for, all ours to keep:
   the end tick made to read as a right-hand edge rather than a left-aligned
   label (the component's ruler is built for many evenly-spaced ticks, not a
   from/to pair); a "today" marker, which the system does not have at all; and
   a floor under a bar's width. The component enforces its own floor in
   JavaScript (MIN_BAR_PERCENT in Gantt.jsx) — this is a PHP port with no such
   step, so a one-day item on a long axis needs the same floor here, or it
   renders as a sliver too thin to see (Layout::place() computes width with no
   minimum of its own, by design — see its own class comment). Scoped to this
   screen's bar only, so it cannot widen a bar drawn by any other bw-gantt use. */
.bwx-timeline-tick--end { text-align: right; }
[data-testid="bwx-timeline"] .bw-gantt__bar { min-width: 0.35rem; }
.bwx-timeline-today { position: absolute; top: 0; bottom: 0; width: 2px; background: var(--color-coral, #d63638); }

/* The ruler's dates are the only labelling the timeline has, and the system
   sets them in the faintest ink at the smallest size — which on the card
   behind them does not reach the contrast a person needs to read them. The
   muted ink says the same thing about their importance and can actually be
   read. Scoped to this screen, so no other bw-gantt loses the lighter ruler. */
[data-testid="bwx-timeline"] .bw-gantt__ruler,
[data-testid="bwx-timeline"] .bw-gantt__tick { color: var(--bw-text-muted); }

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
/* Work with no dates on it (#120), listed as cards. Separate pieces of work
   have to read as separate cards, so they need space between them (#289) —
   bw-card carries no margin of its own, the same reason .bwx-asked sets one. */
.bwx-undated { margin-top: 1.5rem; }
.bwx-undated > .bw-card { margin: 0 0 0.75rem; }
.bwx-undated > .bw-card:last-child { margin-bottom: 0; }

/* What you asked for (#130). An exchange, not a table: the request, then the
   reply set in under it. Each entry is a bw-card; this only adds the gap
   between them, since bw-card carries no margin of its own. */
.bwx-asked { max-width: var(--content-max-width, 60rem); margin-top: 1rem; }
.bwx-asked > .bw-card { margin: 0 0 0.75rem; }
.bwx-asked > .bw-card:last-child { margin-bottom: 0; }

/* What kind of work a card is, and how big (#287). Sits above the dates, so
   the first thing under a title says what the work is rather than when it is.
   The level is deliberately quieter than the type badge beside it. */
.bwx-card-class {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	margin: 0 0 0.5rem;
	color: var(--bw-text-muted, #646970);
	font-size: var(--bw-size-sm, 0.8rem);
}

/* The kind of thing this was reads as a badge, so the line it sits on has to
   align to it rather than to a run of text. */
.bwx-asked-meta {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 0.5rem;
	margin: 0.15rem 0 0;
	color: var(--text-muted, #646970);
	font-size: var(--text-small, 0.8rem);
}

/* The request itself, now a labelled record rather than a run of paragraphs
   (#287): three answers with their questions taken away were unreadable. Only
   the gap above it is ours; the labels and their spacing are bw-dl--stack.

   Written as dl.bwx-asked-words rather than as the class alone, because the
   design system's own .bw-dl{margin:0} loads after these rules and would
   otherwise win on source order and close the gap back up. */
dl.bwx-asked-words { margin: 0.9rem 0 0; }

/* A reply is the studio talking, so it is ruled off from the client's own
   words above it. The system's labelled divider carries the rule and the
   label; this only stops it collapsing against the paragraph it introduces. */
.bwx-asked-words + .bw-divider__labelled { margin-top: var(--bw-space-8, 1rem); }

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
