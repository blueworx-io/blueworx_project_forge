import type { ButtonHTMLAttributes, CSSProperties, ReactNode } from 'react';
import { ChevronRight, type LucideIcon } from 'lucide-react';
import { phaseOf } from '../phases';

/*
 * The shared kit's small parts (#296): buttons, tags, stage chips, cards,
 * panels, stats, avatars, tiles, the page header and the empty state. Each is
 * a thin component over a class in kit.css — the markup carries the meaning,
 * the stylesheet carries the design, and neither holds a literal.
 */

export type ButtonVariant = 'primary' | 'secondary' | 'soft' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

export function Button( {
  variant = 'primary',
  size = 'md',
  children,
  ...rest
}: ButtonHTMLAttributes< HTMLButtonElement > & { variant?: ButtonVariant; size?: ButtonSize } ) {
  return (
    <button type="button" className="fk-btn" data-variant={ variant } data-size={ size } { ...rest }>
      { children }
    </button>
  );
}

/** A glyph-only control — always labelled, since the glyph says nothing alone. */
export function IconButton( {
  icon: Icon,
  label,
  ...rest
}: ButtonHTMLAttributes< HTMLButtonElement > & { icon: LucideIcon; label: string } ) {
  return (
    <button type="button" className="fk-icon-btn" aria-label={ label } { ...rest }>
      <Icon size={ 16 } strokeWidth={ 1.5 } aria-hidden="true" />
    </button>
  );
}

export type Tone = 'neutral' | 'ok' | 'warn' | 'danger' | 'info' | 'brand';

/**
 * A state, written out. The colour repeats what the words say; it never
 * replaces them.
 */
export function Tag( {
  tone = 'neutral',
  dot,
  dense = true,
  wrap,
  children,
}: {
  tone?: Tone;
  dot?: boolean;
  dense?: boolean;
  wrap?: boolean;
  children: ReactNode;
} ) {
  return (
    <span className="fk-tag" data-tone={ tone } data-dense={ dense ? 'true' : 'false' } data-wrap={ wrap ? 'true' : undefined }>
      { dot && <span className="fk-dot" aria-hidden="true" /> }
      { children }
    </span>
  );
}

/** The twelve stages, in workflow order, with the labels the design fixes. */
export const STAGE_LABELS: Record< string, string > = {
  'future-idea': 'Future Idea',
  triage: 'Triage',
  'bug-tracking': 'Bug Tracking',
  'documentation-period': 'Documentation Period',
  'technical-audit': 'Technical Audit',
  'design-process': 'Design Process',
  blocked: 'Blocked',
  'up-next': 'Up Next',
  'in-development': 'In Development',
  'in-review': 'In Review',
  completed: 'Completed',
  released: 'Released',
};

export const STAGE_ORDER = Object.keys( STAGE_LABELS );

/**
 * A stage, painted as the phase it belongs to. Takes the workflow's own stage
 * ids, so a chip and the board can never disagree about a colour.
 */
export function StageChip( { stage, dense, short }: { stage: string; dense?: boolean; short?: boolean } ) {
  const index = STAGE_ORDER.indexOf( stage );
  const label = STAGE_LABELS[ stage ] ?? stage;

  return (
    <span className="fk-chip" data-phase={ phaseOf( stage ) } data-dense={ dense ? 'true' : undefined } data-stage={ stage }>
      <span className="fk-dot" aria-hidden="true" />
      { index >= 0 && <span className="fk-chip-index">{ String( index + 1 ).padStart( 2, '0' ) }</span> }
      { short ? label.split( ' ' )[ 0 ] : label }
      { 'blocked' === stage && <span className="fk-chip-hatch" aria-hidden="true" /> }
    </span>
  );
}

export function Card( {
  tone = 'surface',
  pad = 24,
  children,
  style,
  className,
  testId,
}: {
  tone?: 'surface' | 'sunken' | 'tint' | 'inverse';
  pad?: number;
  children: ReactNode;
  style?: CSSProperties;
  className?: string;
  testId?: string;
} ) {
  return (
    <section className={ [ 'fk-card', className ].filter( Boolean ).join( ' ' ) } data-tone={ tone } data-testid={ testId } style={ { padding: pad, ...style } }>
      { children }
    </section>
  );
}

/** A titled region with an eyebrow head — the studio's working surface. */
export function Panel( {
  title,
  right,
  children,
  pad = 16,
  flush,
}: {
  title?: ReactNode;
  right?: ReactNode;
  children: ReactNode;
  pad?: number;
  flush?: boolean;
} ) {
  return (
    <section className="fk-panel">
      { title && (
        <header className="fk-panel-head">
          <h3 className="fk-panel-title">{ title }</h3>
          { right && <span className="fk-panel-right">{ right }</span> }
        </header>
      ) }
      <div style={ { padding: flush ? 0 : pad } }>{ children }</div>
    </section>
  );
}

export function Eyebrow( { children, tone }: { children: ReactNode; tone?: 'brand' } ) {
  return (
    <div className="fk-eyebrow" data-tone={ tone }>
      { children }
    </div>
  );
}

export function Stat( {
  label,
  value,
  sub,
  tone = 'neutral',
}: {
  label: string;
  value: ReactNode;
  sub?: ReactNode;
  tone?: 'neutral' | 'ok' | 'warn' | 'danger';
} ) {
  return (
    <div className="fk-stat" data-tone={ tone }>
      <span className="fk-eyebrow">{ label }</span>
      <span className="fk-stat-value">{ value }</span>
      { sub && <span className="fk-stat-sub">{ sub }</span> }
    </div>
  );
}

/** Initials in a neutral circle; a dashed `?` when nobody holds the seat. */
export function Avatar( { name }: { name?: string | null } ) {
  const initials = name
    ? name
        .split( ' ' )
        .map( ( part ) => part[ 0 ] )
        .slice( 0, 2 )
        .join( '' )
    : '?';

  return (
    <span className="fk-avatar" data-empty={ name ? undefined : 'true' } aria-hidden="true">
      { initials }
    </span>
  );
}

export interface RolePerson {
  name: string;
  hours?: number | null;
}

/** The three accountability roles, as the design names them. */
export function Roles( {
  owner,
  checker,
  builder,
  hours,
}: {
  owner?: RolePerson | null;
  checker?: RolePerson | null;
  builder?: RolePerson | null;
  hours?: boolean;
} ) {
  const rows: Array< [ string, RolePerson | null | undefined ] > = [
    [ 'Owner', owner ],
    [ 'Checker', checker ],
    [ 'Builder', builder ],
  ];

  return (
    <div className="fk-roles">
      { rows.map( ( [ label, person ] ) => (
        <div key={ label } className="fk-role">
          <Avatar name={ person?.name } />
          <span className="fk-role-text">
            <span className="fk-eyebrow">{ label }</span>
            <span className="fk-role-name" data-empty={ person ? undefined : 'true' }>
              { person ? person.name : 'Unassigned' }
              { hours && person && null != person.hours && (
                <span className="fk-role-hours"> · { person.hours }h</span>
              ) }
            </span>
          </span>
        </div>
      ) ) }
    </div>
  );
}

export type TileHue = 'blue' | 'violet' | 'emerald' | 'amber' | 'rose' | 'teal' | 'slate';

/**
 * A tinted square holding a glyph — the only place a saturated hue fills a
 * surface. A destination or a category, never a state.
 */
export function IconTile( {
  icon: Icon,
  hue = 'blue',
  size = 'md',
  label,
}: {
  icon: LucideIcon;
  hue?: TileHue;
  size?: 'sm' | 'md' | 'lg';
  label?: string;
} ) {
  const glyph = { sm: 14, md: 18, lg: 22 }[ size ];

  return (
    <span className="fk-tile" data-hue={ hue } data-size={ size } role={ label ? 'img' : undefined } aria-label={ label }>
      <Icon size={ glyph } strokeWidth={ 1.5 } aria-hidden="true" />
    </span>
  );
}

export interface Tab {
  id: string;
  label: string;
  count?: number;
}

/** Where am I, what is this, what can I do — on every screen. */
export function PageHeader( {
  crumbs,
  eyebrow,
  title,
  description,
  meta,
  actions,
  tabs,
  activeTab,
  onTab,
  tile,
  hue = 'blue',
}: {
  crumbs?: string[];
  eyebrow?: ReactNode;
  title: ReactNode;
  description?: ReactNode;
  meta?: ReactNode;
  actions?: ReactNode;
  tabs?: Tab[];
  activeTab?: string;
  onTab?: ( id: string ) => void;
  tile?: LucideIcon;
  hue?: TileHue;
} ) {
  return (
    <header className="fk-page-header" data-tabs={ tabs ? 'true' : undefined }>
      { crumbs && (
        <nav className="fk-crumbs" aria-label="Where this is">
          { crumbs.map( ( crumb, i ) => (
            <span key={ i } style={ { display: 'contents' } }>
              { i > 0 && <ChevronRight size={ 13 } aria-hidden="true" /> }
              <span>{ crumb }</span>
            </span>
          ) ) }
        </nav>
      ) }
      <div className="fk-page-header-row">
        { tile && <IconTile icon={ tile } hue={ hue } size="lg" /> }
        <div className="fk-page-header-text">
          { eyebrow && <Eyebrow tone="brand">{ eyebrow }</Eyebrow> }
          <h1 className="fk-page-title">{ title }</h1>
          { description && <p className="fk-page-description">{ description }</p> }
        </div>
        { actions && <span className="fk-page-actions">{ actions }</span> }
      </div>
      { meta && <div className="fk-page-meta">{ meta }</div> }
      { tabs && (
        <div className="fk-tabs" role="tablist">
          { tabs.map( ( tab ) => (
            <button
              key={ tab.id }
              type="button"
              role="tab"
              className="fk-tab"
              aria-selected={ tab.id === activeTab }
              onClick={ () => onTab?.( tab.id ) }
            >
              { tab.label }
              { null != tab.count && <span className="fk-tab-count">{ tab.count }</span> }
            </button>
          ) ) }
        </div>
      ) }
    </header>
  );
}

/** The client site's page opening: a title and its sub-line, no card behind. */
export function SectionTitle( {
  eyebrow,
  children,
  sub,
  meta,
  actions,
}: {
  eyebrow?: ReactNode;
  children: ReactNode;
  sub?: ReactNode;
  meta?: ReactNode;
  actions?: ReactNode;
} ) {
  return (
    <div className="fk-section-title">
      { eyebrow && <Eyebrow tone="brand">{ eyebrow }</Eyebrow> }
      <div className="fk-page-header-row">
        <div className="fk-page-header-text">
          <h1 className="fk-page-title">{ children }</h1>
          { sub && <p className="fk-page-description">{ sub }</p> }
        </div>
        { actions && <span className="fk-page-actions">{ actions }</span> }
      </div>
      { meta && <div className="fk-page-meta">{ meta }</div> }
    </div>
  );
}

/** Name the condition, then the action. A blank region is a bug. */
export function EmptyState( {
  icon,
  hue = 'slate',
  title,
  body,
  action,
  dense,
}: {
  icon: LucideIcon;
  hue?: TileHue;
  title: ReactNode;
  body?: ReactNode;
  action?: ReactNode;
  dense?: boolean;
} ) {
  return (
    <div className="fk-empty" data-dense={ dense ? 'true' : undefined }>
      <IconTile icon={ icon } hue={ hue } size={ dense ? 'sm' : 'md' } />
      <div className="fk-empty-title">{ title }</div>
      { body && <p className="fk-empty-body">{ body }</p> }
      { action }
    </div>
  );
}
