/* Shared primitives for the Primary Site kit — cosmetic stand-ins for the
   published components in /components. Values are copied, not reinvented. */
const T = {
  ink900: 'var(--ink-900)', ink700: 'var(--ink-700)', ink500: 'var(--ink-500)', ink300: 'var(--ink-300)',
  hair: '1px solid var(--border-hairline)', strong: '1px solid var(--border-strong)',
  card: 'var(--surface-card)', sunk: 'var(--surface-sunken)', page: 'var(--surface-page)',
  tint: 'var(--accent-signal-wash)', brand: 'var(--accent-signal)', wash: 'var(--surface-wash)',
  mono: 'var(--font-mono)', disp: 'var(--font-display)'
};

const STAGES = [
  { key: 'future-idea', ix: '01', label: 'Future Idea', t: 'future-idea' },
  { key: 'triage', ix: '02', label: 'Triage', t: 'triage' },
  { key: 'bug', ix: '03', label: 'Bug Tracking', t: 'bug' },
  { key: 'documentation', ix: '04', label: 'Documentation Period', t: 'documentation' },
  { key: 'audit', ix: '05', label: 'Technical Audit', t: 'audit' },
  { key: 'design', ix: '06', label: 'Design Process', t: 'design' },
  { key: 'blocked', ix: '07', label: 'Blocked', t: 'blocked' },
  { key: 'up-next', ix: '08', label: 'Up Next', t: 'up-next' },
  { key: 'development', ix: '09', label: 'In Development', t: 'development' },
  { key: 'review', ix: '10', label: 'In Review', t: 'review' },
  { key: 'completed', ix: '11', label: 'Completed', t: 'completed' },
  { key: 'released', ix: '12', label: 'Released', t: 'released' }
];
const stage = k => STAGES.find(s => s.key === k) || STAGES[0];
const sv = k => { const t = stage(k).t; return { ink: 'var(--stage-' + t + '-ink)', wash: 'var(--stage-' + t + '-wash)', edge: 'var(--stage-' + t + '-edge)' }; };

function Chip({ s, dense, short }) {
  const st = stage(s), v = sv(s);
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, whiteSpace: 'nowrap',
      padding: dense ? '2px 7px 2px 5px' : '3px 10px 3px 6px', borderRadius: 6,
      background: v.wash, color: v.ink, border: '1px solid ' + v.edge,
      fontSize: dense ? 11 : 12, fontWeight: 500 }}>
      <span style={{ width: 4, height: 4, borderRadius: '50%', background: 'currentColor', flexShrink: 0 }} />
      <span style={{ fontFamily: T.mono, fontSize: 11, opacity: .75 }}>{st.ix}</span>
      {short ? st.label.split(' ')[0] : st.label}
      {s === 'blocked' && <span style={{ width: 12, height: 6, borderRadius: 2, background: 'var(--blocked-hatch)' }} />}
    </span>
  );
}

const TONE = {
  neutral: ['var(--paper-sunken)', 'var(--ink-700)', 'var(--border-hairline)'],
  ok: ['var(--state-ok-wash)', 'var(--state-ok)', 'var(--state-ok-edge)'],
  warn: ['var(--state-warn-wash)', 'var(--state-warn)', 'var(--state-warn-edge)'],
  danger: ['var(--state-danger-wash)', 'var(--state-danger)', 'var(--state-danger-edge)'],
  info: ['var(--state-info-wash)', 'var(--state-info)', 'var(--state-info-edge)'],
  mint: ['var(--state-ok-wash)', 'var(--state-ok)', 'var(--state-ok-edge)'],
  butter: ['var(--state-warn-wash)', 'var(--state-warn)', 'var(--state-warn-edge)'],
  ink: ['var(--surface-action-soft)', 'var(--brand-700)', 'var(--border-tint)']
};
function Tag({ tone = 'neutral', dot, dense = true, children }) {
  const [bg, fg, bd] = TONE[tone];
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, background: bg, color: fg,
      border: '1px solid ' + bd, borderRadius: 999, padding: dense ? '2px 8px' : '4px 12px',
      fontSize: dense ? 11 : 12, fontWeight: 500, whiteSpace: 'nowrap' }}>
      {dot && <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'currentColor' }} />}
      {children}
    </span>
  );
}

function Btn({ variant = 'primary', size = 'sm', disabled, children, ...rest }) {
  const v = {
    primary: { background: 'var(--surface-action)', color: '#fff', border: '1px solid transparent', boxShadow: 'var(--shadow-action)' },
    secondary: { background: '#fff', color: T.ink900, border: T.hair, boxShadow: 'var(--shadow-xs)' },
    soft: { background: 'var(--surface-action-soft)', color: 'var(--brand-700)', border: '1px solid var(--border-tint)' },
    ghost: { background: 'transparent', color: T.ink700, border: '1px solid transparent' },
    danger: { background: '#fff', color: 'var(--state-danger)', border: '1px solid var(--color-danger-border)', boxShadow: 'var(--shadow-xs)' }
  }[variant];
  const s = { sm: { height: 32, padding: '0 14px', fontSize: 13 }, md: { height: 40, padding: '0 20px', fontSize: 15 } }[size];
  return <button type="button" disabled={disabled} data-forge-btn={variant} style={{ borderRadius: 999, cursor: disabled ? 'not-allowed' : 'pointer',
    fontFamily: 'var(--font-ui)', fontWeight: 500, opacity: disabled ? .45 : 1, display: 'inline-flex',
    alignItems: 'center', gap: 6, ...s, ...v }} {...rest}>{children}</button>;
}

function Av({ name }) {
  const un = !name;
  return <span style={{ width: 24, height: 24, borderRadius: '50%', flex: '0 0 auto', display: 'inline-flex',
    alignItems: 'center', justifyContent: 'center', fontSize: 11, fontWeight: 500,
    background: un ? 'transparent' : 'var(--surface-fill)', color: un ? T.ink300 : T.ink500,
    border: un ? T.strong.replace('solid', 'dashed') : T.hair }}>
    {un ? '?' : name.split(' ').map(p => p[0]).slice(0, 2).join('')}</span>;
}

function Roles({ p, r, d, hours }) {
  const rows = [['Owner', p], ['Checker', r], ['Builder', d]];
  return <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3,1fr)', gap: 12 }}>
    {rows.map(([label, person]) => (
      <div key={label} style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <Av name={person && person.name} />
        <span style={{ display: 'flex', flexDirection: 'column', minWidth: 0 }}>
          <span style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', color: T.ink500 }}>{label}</span>
          <span style={{ fontSize: 13, color: person ? T.ink900 : 'var(--state-danger)', whiteSpace: 'nowrap' }}>
            {person ? person.name : 'Unassigned'}
            {hours && person && person.h != null && <span style={{ fontFamily: T.mono, color: T.ink500 }}> · {person.h}h</span>}
          </span>
        </span>
      </div>
    ))}
  </div>;
}

function Panel({ title, right, children, pad = 16, flush }) {
  return (
    <section style={{ background: T.card, border: T.hair, borderRadius: 16, overflow: 'hidden', flex: '0 0 auto', boxShadow: 'var(--shadow-card)' }}>
      {title && (
        <header style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px',
          background: T.sunk, borderBottom: T.hair }}>
          <h3 style={{ margin: 0, fontSize: 11, fontWeight: 500, textTransform: 'uppercase',
            letterSpacing: '.06em', color: T.ink700 }}>{title}</h3>
          <span style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>{right}</span>
        </header>
      )}
      <div style={{ padding: flush ? 0 : pad }}>{children}</div>
    </section>
  );
}

/* v3 DataView: saved views with live counts, search, value-bearing filter pills,
   selectable rows, a navy bulk-action bar and a result footer. Every piece of chrome
   only renders when a screen passes the matching prop, so a bare <Table cols rows />
   still works inside a Panel. Sorting is carried over from v2. */
function Check({ checked, indeterminate, label, onChange }) {
  const on = checked || indeterminate;
  return (
    <button type="button" role="checkbox" aria-checked={indeterminate ? 'mixed' : checked} aria-label={label}
      onClick={e => { e.stopPropagation(); onChange && onChange(); }}
      style={{ width: 18, height: 18, flexShrink: 0, borderRadius: 6, padding: 0, cursor: 'pointer',
        display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
        border: '1px solid ' + (on ? 'var(--brand-600)' : 'var(--border-strong)'),
        background: on ? 'var(--brand-600)' : '#fff', color: '#fff',
        transition: 'background 150ms var(--ease-out), border-color 150ms var(--ease-out)' }}>
      {indeterminate ? <span style={{ width: 8, height: 2, borderRadius: 1, background: 'currentColor' }} />
        : checked ? <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3.5"
            strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg> : null}
    </button>
  );
}

function ViewPill({ label, count, active, onClick, dashed }) {
  return (
    <button type="button" onClick={onClick}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 30, padding: '0 12px', cursor: 'pointer',
        borderRadius: 999, whiteSpace: 'nowrap', flexShrink: 0, fontFamily: 'var(--font-ui)', fontSize: 13,
        fontWeight: active ? 500 : 400,
        background: active ? T.tint : 'transparent',
        border: dashed ? '1px dashed var(--border-strong)' : '1px solid ' + (active ? 'var(--accent-signal-edge)' : 'transparent'),
        color: active ? 'var(--brand-700)' : T.ink500, transition: 'background 150ms var(--ease-out)' }}>
      {label}
      {count != null && <span style={{ fontFamily: T.mono, fontSize: 11, fontVariantNumeric: 'tabular-nums',
        color: active ? 'var(--brand-700)' : T.ink300 }}>{count}</span>}
    </button>
  );
}

function FilterPill({ f, onPick }) {
  const [open, setOpen] = React.useState(false);
  const on = !!f.value;
  return (
    <span style={{ position: 'relative', flexShrink: 0 }}>
      <button type="button" onClick={() => setOpen(!open)}
        style={{ display: 'inline-flex', alignItems: 'center', gap: 6, height: 36, padding: '0 12px', cursor: 'pointer',
          borderRadius: 999, whiteSpace: 'nowrap', fontFamily: 'var(--font-ui)', fontSize: 13,
          background: on ? T.tint : '#fff',
          border: '1px solid ' + (on ? 'var(--accent-signal-edge)' : 'var(--border-hairline)'),
          boxShadow: on ? 'none' : 'var(--shadow-xs)', color: on ? 'var(--brand-700)' : T.ink700 }}>
        <span style={{ color: on ? 'var(--brand-600)' : T.ink300 }}>{f.label}</span>
        {on && <span style={{ fontWeight: 500 }}>{f.value}</span>}
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
          strokeLinecap="round" strokeLinejoin="round" style={{ opacity: .5 }}><polyline points="6 9 12 15 18 9" /></svg>
      </button>
      {open && (
        <span onClick={() => setOpen(false)} style={{ position: 'fixed', inset: 0, zIndex: 44, display: 'block' }} />
      )}
      {open && (
        <span style={{ position: 'absolute', top: 40, left: 0, zIndex: 45, minWidth: 210, background: '#fff',
          border: T.hair, borderRadius: 12, boxShadow: 'var(--shadow-panel)', padding: 6, display: 'block' }}>
          {f.options.map(o => {
            const picked = (f.value || f.options[0]) === o;
            return (
              <button key={o} type="button" onClick={() => { onPick(f.id, o); setOpen(false); }}
                style={{ width: '100%', textAlign: 'left', display: 'block', padding: '8px 10px', borderRadius: 8,
                  border: 'none', cursor: 'pointer', fontFamily: 'var(--font-ui)', fontSize: 14,
                  background: picked ? T.tint : 'transparent', color: picked ? 'var(--brand-700)' : T.ink700 }}>{o}</button>
            );
          })}
        </span>
      )}
    </span>
  );
}

function BulkBtn({ children, onClick }) {
  return (
    <button type="button" onClick={onClick}
      style={{ height: 32, padding: '0 14px', borderRadius: 999, cursor: 'pointer', whiteSpace: 'nowrap',
        fontFamily: 'var(--font-ui)', fontSize: 13, fontWeight: 500, color: '#fff',
        background: 'rgba(255,255,255,0.12)', border: '1px solid rgba(255,255,255,0.18)' }}>{children}</button>
  );
}

function sortVal(row, col) {
  const raw = col.sortBy ? col.sortBy(row) : row[col.key];
  if (typeof raw === 'number') return raw;
  const text = typeof raw === 'string' ? raw
    : (raw && raw.props && typeof raw.props.children === 'string' ? raw.props.children : '');
  if (col.mono || col.align === 'right') {
    const n = parseFloat(String(text).replace(/[^0-9.-]/g, ''));
    if (!isNaN(n)) return n;
  }
  return String(text).toLowerCase();
}

function Table({ cols, rows, empty = 'Nothing to show', sortable = true, defaultSort, maxHeight,
  selectedId, onRowClick, stickyFirst, footer, fixed, stickyOffset = 64, dense = true, bare = false,
  title, titleRight, views, activeView, onViewChange, onSaveView,
  search, searchPlaceholder = 'Search', onSearch, filters, onFilter, onClearFilters, toolbar,
  selectable, selection = [], onSelectionChange, bulkNoun = 'item', bulkActions }) {
  const [sort, setSort] = React.useState(defaultSort || null);
  const [searchFocus, setSearchFocus] = React.useState(false);
  const sorted = React.useMemo(() => {
    if (!sort) return rows;
    const col = cols.find(c => c.key === sort.key);
    if (!col) return rows;
    const out = rows.slice().sort((a, b) => {
      const av = sortVal(a, col), bv = sortVal(b, col);
      return av < bv ? -1 : av > bv ? 1 : 0;
    });
    return sort.dir === 'desc' ? out.reverse() : out;
  }, [rows, sort, cols]);

  const toggle = col => {
    if (!sortable || col.sortable === false || !col.label) return;
    setSort(s => (!s || s.key !== col.key) ? { key: col.key, dir: 'asc' }
      : s.dir === 'asc' ? { key: col.key, dir: 'desc' } : null);
  };

  const chrome = !bare;
  const rowH = dense ? 44 : 52;
  const pad = dense ? '10px 16px' : '14px 16px';
  const headBgDefault = T.card;
  const allOn = rows.length > 0 && selection.length === rows.length;
  const someOn = selection.length > 0 && !allOn;
  const activeFilters = (filters || []).filter(x => !!x.value).length;
  const pick = i => {
    if (!onSelectionChange) return;
    onSelectionChange(selection.indexOf(i) === -1 ? selection.concat([i]) : selection.filter(x => x !== i));
  };

  return (
    <div style={{ position: 'relative', background: chrome ? T.card : 'transparent',
      border: chrome ? T.hair : undefined, borderRadius: chrome ? 16 : undefined,
      boxShadow: chrome ? 'var(--shadow-card)' : undefined }}>

      {title && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 16px',
          borderBottom: '1px solid var(--border-subtle)' }}>
          <h3 style={{ margin: 0, fontSize: 15, fontWeight: 500, letterSpacing: '-0.011em', color: T.ink900 }}>{title}</h3>
          {titleRight && <span style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>{titleRight}</span>}
        </div>
      )}
      {views && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 4, flexWrap: 'wrap',
          padding: '10px 16px', borderBottom: '1px solid var(--border-subtle)' }}>
          {views.map(v => (
            <ViewPill key={v.id} label={v.label} count={v.count} active={v.id === activeView}
              onClick={() => onViewChange && onViewChange(v.id)} />
          ))}
          {onSaveView && <ViewPill label="Save this view" dashed onClick={onSaveView} />}
        </div>
      )}

      {(onSearch || filters || toolbar) && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap',
          padding: '12px 16px', borderBottom: T.hair, background: T.wash }}>
          {onSearch && (
            <span style={{ position: 'relative', display: 'block', flex: '1 1 200px', minWidth: 180, maxWidth: 300 }}>
              <span style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)',
                display: 'inline-flex', color: searchFocus ? 'var(--brand-600)' : T.ink300, pointerEvents: 'none' }}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75"
                  strokeLinecap="round" strokeLinejoin="round"><circle cx="11" cy="11" r="8" /><path d="m21 21-4.3-4.3" /></svg>
              </span>
              <input value={search || ''} placeholder={searchPlaceholder}
                onFocus={() => setSearchFocus(true)} onBlur={() => setSearchFocus(false)}
                onChange={e => onSearch(e.target.value)}
                style={{ height: 36, width: '100%', boxSizing: 'border-box', paddingLeft: 36, paddingRight: 12,
                  borderRadius: 999, border: '1px solid ' + (searchFocus ? 'var(--brand-600)' : 'var(--border-hairline)'),
                  background: '#fff', boxShadow: searchFocus ? 'var(--focus-ring)' : 'var(--shadow-xs)', outline: 'none',
                  fontFamily: 'var(--font-ui)', fontSize: 14, color: T.ink900 }} />
            </span>
          )}
          {(filters || []).map(f => <FilterPill key={f.id} f={f} onPick={onFilter} />)}
          {activeFilters > 0 && onClearFilters && (
            <button type="button" onClick={onClearFilters}
              style={{ height: 36, padding: '0 8px', background: 'transparent', border: 'none', cursor: 'pointer',
                color: T.ink500, fontFamily: 'var(--font-ui)', fontSize: 13, textDecoration: 'underline',
                textUnderlineOffset: 3, whiteSpace: 'nowrap' }}>
              Clear {activeFilters} filter{activeFilters === 1 ? '' : 's'}
            </button>
          )}
          {toolbar && <span style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>{toolbar}</span>}
        </div>
      )}

      <div style={{ maxHeight, overflowX: 'auto', overflowY: maxHeight ? 'auto' : 'visible' }}>
        <table style={{ width: 'max-content', minWidth: '100%', borderCollapse: 'separate', borderSpacing: 0,
          tableLayout: fixed ? 'fixed' : 'auto', fontVariantNumeric: 'tabular-nums' }}>
          <thead>
            <tr>
              {selectable && (
                <th style={{ width: 44, padding: pad, background: headBgDefault, textAlign: 'left', lineHeight: 0,
                  borderBottom: T.hair, position: maxHeight ? 'sticky' : 'static', top: maxHeight ? 0 : undefined, zIndex: 2 }}>
                  <Check checked={allOn} indeterminate={someOn} label="Select all rows"
                    onChange={() => onSelectionChange && onSelectionChange(allOn ? [] : rows.map((_, i) => i))} />
                </th>
              )}
              {cols.map((c, ci) => {
                const active = sort && sort.key === c.key;
                const canSort = sortable && c.sortable !== false && !!c.label;
                return (
                  <th key={c.key} onClick={() => toggle(c)}
                    aria-sort={active ? (sort.dir === 'asc' ? 'ascending' : 'descending') : 'none'}
                    style={{ textAlign: c.align || 'left', padding: pad, fontSize: 11, fontWeight: 500,
                      textTransform: 'uppercase', letterSpacing: '.06em', width: c.width,
                      background: c.headBg || headBgDefault,
                      color: c.headColor || (active ? T.ink900 : T.ink500), borderBottom: T.hair,
                      borderLeft: c.mark ? '1px solid var(--accent-signal)' : undefined,
                      borderRight: c.mark ? '1px solid var(--accent-signal)' : undefined,
                      whiteSpace: 'nowrap', cursor: canSort ? 'pointer' : 'default',
                      position: maxHeight ? 'sticky' : 'static', top: maxHeight ? 0 : undefined,
                      userSelect: 'none', zIndex: stickyFirst && ci === 0 ? 3 : 2,
                      left: stickyFirst && ci === 0 ? 0 : undefined, transition: 'color 150ms var(--ease-out)' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6,
                      flexDirection: c.align === 'right' ? 'row-reverse' : 'row' }}>
                      {c.label}
                      {canSort && active && <span aria-hidden="true" style={{ fontSize: 9, lineHeight: 1,
                        color: 'var(--accent-signal)' }}>{sort.dir === 'asc' ? '▲' : '▼'}</span>}
                    </span>
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {sorted.length === 0 && (
              <tr><td colSpan={cols.length + (selectable ? 1 : 0)} style={{ padding: 32, textAlign: 'center' }}>
                <span style={{ fontSize: 15, color: T.ink500 }}>{empty}</span>
              </td></tr>
            )}
            {sorted.map((r, i) => {
              const selected = (selectedId != null && r.id === selectedId) || selection.indexOf(i) !== -1;
              const clickable = !!onRowClick;
              const last = i === sorted.length - 1;
              const bg = selected ? T.tint : 'transparent';
              return (
                <tr key={r.id || i} tabIndex={clickable ? 0 : undefined}
                  onClick={clickable ? () => onRowClick(r) : undefined}
                  onKeyDown={clickable ? e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onRowClick(r); } } : undefined}
                  onMouseEnter={clickable ? e => { if (!selected) e.currentTarget.style.background = T.wash; } : undefined}
                  onMouseLeave={clickable ? e => { e.currentTarget.style.background = bg; } : undefined}
                  style={{ background: bg, cursor: clickable ? 'pointer' : 'default',
                    transition: 'background 150ms var(--ease-out)' }}>
                  {selectable && (
                    <td style={{ width: 44, padding: pad, lineHeight: 0, verticalAlign: 'middle',
                      borderBottom: last ? 'none' : '1px solid var(--border-subtle)' }}>
                      <Check checked={selection.indexOf(i) !== -1} label={'Select row ' + (i + 1)} onChange={() => pick(i)} />
                    </td>
                  )}
                  {cols.map((c, ci) => (
                    <td key={c.key} style={{ textAlign: c.align || 'left', padding: pad, height: rowH,
                      fontSize: 14, verticalAlign: 'middle',
                      borderBottom: last ? 'none' : '1px solid var(--border-subtle)',
                      fontFamily: c.mono ? T.mono : 'var(--font-ui)',
                      color: ci === 0 ? T.ink900 : T.ink700, fontWeight: ci === 0 && !c.mono ? 500 : 400,
                      whiteSpace: c.wrap ? 'normal' : 'nowrap',
                      overflow: fixed ? 'hidden' : undefined,
                      textOverflow: fixed && !c.wrap ? 'ellipsis' : undefined,
                      borderLeft: c.mark ? '1px solid var(--accent-signal)' : undefined,
                      borderRight: c.mark ? '1px solid var(--accent-signal)' : undefined,
                      position: stickyFirst && ci === 0 ? 'sticky' : undefined,
                      left: stickyFirst && ci === 0 ? 0 : undefined,
                      background: c.bg || (stickyFirst && ci === 0 ? (selected ? T.tint : T.card) : undefined),
                      zIndex: stickyFirst && ci === 0 ? 1 : undefined }}>
                      {c.clamp
                        ? <span title={typeof r[c.key] === 'string' ? r[c.key] : undefined}
                            style={{ display: '-webkit-box', WebkitLineClamp: c.clamp, WebkitBoxOrient: 'vertical',
                              overflow: 'hidden', lineHeight: 1.45 }}>{r[c.key]}</span>
                        : r[c.key]}
                    </td>
                  ))}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {footer && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 16px', borderTop: T.hair,
          background: T.wash, fontSize: 13, color: T.ink500,
          borderRadius: chrome ? '0 0 16px 16px' : undefined }}>{footer}</div>
      )}

      {selectable && selection.length > 0 && (
        <div style={{ position: 'absolute', left: '50%', bottom: 20, transform: 'translateX(-50%)', zIndex: 30,
          display: 'flex', alignItems: 'center', gap: 16, background: 'var(--surface-inverse)', color: '#fff',
          borderRadius: 999, padding: '8px 8px 8px 20px', boxShadow: 'var(--shadow-panel)' }}>
          <span style={{ fontSize: 13, whiteSpace: 'nowrap' }}>
            <span style={{ fontFamily: T.mono, fontWeight: 500 }}>{selection.length}</span>
            {' ' + bulkNoun + (selection.length === 1 ? '' : 's') + ' selected'}
          </span>
          <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>{bulkActions}</span>
          <button type="button" onClick={() => onSelectionChange && onSelectionChange([])} aria-label="Clear selection"
            style={{ width: 32, height: 32, borderRadius: 999, border: 'none', cursor: 'pointer',
              background: 'rgba(255,255,255,0.12)', color: '#fff', display: 'inline-flex', alignItems: 'center',
              justifyContent: 'center', fontSize: 16, lineHeight: 1 }}>&times;</button>
        </div>
      )}
    </div>
  );
}

function Stat({ label, value, sub, tone = 'neutral' }) {
  const c = { neutral: T.ink900, ok: 'var(--state-ok)', warn: 'var(--state-warn)', danger: 'var(--state-danger)' }[tone];
  return <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
    <span style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em', whiteSpace: 'nowrap',
      color: T.ink500 }}>{label}</span>
    <span style={{ fontFamily: T.mono, fontSize: 26, lineHeight: 1.1, letterSpacing: '-0.02em',
      fontVariantNumeric: 'tabular-nums', color: c }}>{value}</span>
    {sub && <span style={{ fontSize: 11, color: T.ink500 }}>{sub}</span>}
  </div>;
}

const ITEMS = [
  { id: 'FRG-2841', client: 'Northgate', parent: 'Milestone · Launch v1', title: 'Rebuild member area authentication',
    stage: 'development', gate: 'partial', hours: 14, age: '4d', due: '-2d', pr: 'P1',
    roles: { p: { name: 'M. Okonkwo', h: 12 }, r: { name: 'R. Ilesanmi', h: 2 }, d: null } },
  { id: 'FRG-2790', client: 'Halden Cove', parent: 'Feature · Booking flow', title: 'Reserve deposit hours against booking items',
    stage: 'up-next', gate: 'ready', hours: 8, age: '1d', due: '21 Aug', pr: 'P2',
    roles: { p: { name: 'S. Devlin', h: 6 }, r: { name: 'M. Okonkwo', h: 1 }, d: { name: 'A. Whitfield', h: 1 } } },
  { id: 'FRG-2712', client: 'Northgate', parent: 'Project · Site rebuild', title: 'DNS delegation for staging subdomain',
    stage: 'development', gate: 'failed', hours: 3, age: '9d', due: '-5d', pr: 'P1', blocked: true,
    reason: 'Blocked 9d · target 19 Aug', roles: { p: { name: 'A. Whitfield', h: 3 }, r: { name: 'S. Devlin' }, d: null } },
  { id: 'FRG-2688', client: 'Verity Health', parent: 'Feature · Patient portal', title: 'Consent copy review with compliance',
    stage: 'review', gate: 'ready', hours: 6, age: '2d', due: '18 Aug', pr: 'P2',
    roles: { p: { name: 'S. Devlin', h: 4 }, r: { name: 'L. Marsh', h: 2 }, d: { name: 'A. Whitfield' } } },
  { id: 'FRG-2650', client: 'Halden Cove', parent: 'Project · Site rebuild', title: 'Migrate legacy redirect inventory',
    stage: 'completed', gate: 'ready', hours: 5, age: '1d', due: '15 Aug', pr: 'P3',
    roles: { p: { name: 'M. Okonkwo', h: 4 }, r: { name: 'R. Ilesanmi', h: 1 }, d: { name: 'A. Whitfield', h: 1 } } },
  { id: 'FRG-2601', client: 'Verity Health', parent: 'Milestone · Q3 compliance', title: 'Accessibility audit remediation pass',
    stage: 'audit', gate: 'partial', hours: 10, age: '6d', due: '26 Aug', pr: 'P2',
    roles: { p: { name: 'L. Marsh', h: 8 }, r: null, d: null } },
  { id: 'FRG-2588', client: 'Northgate', parent: 'Feature · Member area', title: 'Two-factor enrolment email copy',
    stage: 'documentation', gate: 'partial', hours: 4, age: '3d', due: '27 Aug', pr: 'P3',
    roles: { p: { name: 'R. Ilesanmi', h: 3 }, r: null, d: null } },
  { id: 'FRG-2555', client: 'Halden Cove', parent: 'Project · Site rebuild', title: 'Booking confirmation email fails on mobile Safari',
    stage: 'bug', gate: 'partial', hours: 2, age: '2d', due: '20 Aug', pr: 'P1',
    roles: { p: { name: 'S. Devlin', h: 2 }, r: null, d: null } },
  { id: 'FRG-2540', client: 'Verity Health', parent: 'Feature · Patient portal', title: 'Single sign-on with clinical directory',
    stage: 'triage', gate: 'partial', hours: null, age: '1d', due: '—', pr: 'P2', roles: { p: null, r: null, d: null } },
  { id: 'FRG-2498', client: 'Northgate', parent: 'Milestone · Launch v1', title: 'Release member area to production',
    stage: 'released', gate: 'ready', hours: 2, age: '12d', due: '02 Aug', pr: 'P1',
    roles: { p: { name: 'M. Okonkwo', h: 1 }, r: { name: 'R. Ilesanmi' }, d: { name: 'A. Whitfield', h: 1 } } }
];


/* ---- Shared app plumbing: toasts, cross-screen navigation, modals ---- */
const STAMP = { primary: '14 Aug 10:42 BST · R. Ilesanmi · Primary Site' };
let __setToast = null, __openItem = null, __refresh = null;
function toast(msg, tone) { if (__setToast) __setToast({ msg: msg, tone: tone || 'ok', at: STAMP.primary }); }
function openItem(id) { if (__openItem) __openItem(id); }
function refresh() { if (__refresh) __refresh(function (n) { return n + 1; }); }

function ToastHost() {
  const [t, setT] = React.useState(null);
  React.useEffect(function () { __setToast = setT; }, []);
  React.useEffect(function () {
    if (!t) return;
    const h = setTimeout(function () { setT(null); }, 7000);
    return function () { clearTimeout(h); };
  }, [t]);
  if (!t) return null;
  const dot = { ok: 'var(--state-ok)', warn: 'var(--state-warn)', danger: 'var(--state-danger)' }[t.tone] || 'var(--state-ok)';
  return (
    <div style={{ position: 'fixed', right: 20, bottom: 20, zIndex: 80, display: 'flex', alignItems: 'flex-start', gap: 10,
      width: 400, padding: '12px 16px', background: '#fff', border: T.hair, borderRadius: 12, boxShadow: 'var(--shadow-overlay)' }}>
      <span style={{ width: 6, height: 6, borderRadius: '50%', background: dot, marginTop: 6, flex: '0 0 auto' }} />
      <span style={{ flex: 1 }}>
        <span style={{ fontSize: 13, color: T.ink900 }}>{t.msg}</span>
        <span style={{ display: 'block', fontFamily: T.mono, fontSize: 11, color: T.ink500, marginTop: 4 }}>{t.at}</span>
      </span>
      <button onClick={function () { setT(null); }} aria-label="Dismiss" style={{ background: 'none', border: 'none',
        cursor: 'pointer', fontSize: 16, lineHeight: 1, color: T.ink500 }}>&times;</button>
    </div>
  );
}

function Modal({ title, description, width = 480, children, footer, onClose }) {
  return (
    <div onClick={onClose} style={{ position: 'fixed', inset: 0, zIndex: 60, background: 'var(--surface-scrim)',
      display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 24 }}>
      <div onClick={function (e) { e.stopPropagation(); }} style={{ width: width, maxWidth: '100%', maxHeight: '100%',
        overflow: 'auto', background: '#fff', border: T.hair, borderRadius: 16, boxShadow: 'var(--shadow-overlay)' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12, padding: '20px 20px 12px' }}>
          <div style={{ flex: 1 }}>
            <h2 style={{ margin: 0, fontSize: 26, fontWeight: 500, letterSpacing: '-0.022em', lineHeight: 1.3 }}>{title}</h2>
            {description && <p style={{ margin: '4px 0 0', fontSize: 13, color: T.ink500, lineHeight: 1.5 }}>{description}</p>}
          </div>
          <button onClick={onClose} aria-label="Close" style={{ background: 'none', border: 'none', cursor: 'pointer',
            padding: 0, lineHeight: 1, fontSize: 20, color: T.ink500 }}>&times;</button>
        </div>
        {children && <div style={{ padding: '0 20px 20px', display: 'flex', flexDirection: 'column', gap: 14 }}>{children}</div>}
        {footer && <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, padding: '12px 20px',
          borderTop: T.hair, background: T.sunk }}>{footer}</div>}
      </div>
    </div>
  );
}

function Fld({ label, required, help, children }) {
  return (
    <label style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <span style={{ fontSize: 12, fontWeight: 500, color: T.ink700 }}>
        {label}{required && <span style={{ color: 'var(--accent-coral)' }}> *</span>}
      </span>
      {children}
      {help && <span style={{ fontSize: 11, color: T.ink500 }}>{help}</span>}
    </label>
  );
}
const inputStyle = { width: '100%', boxSizing: 'border-box', fontFamily: 'var(--font-ui)', fontSize: 15,
  padding: '10px 12px', border: T.hair, borderRadius: 10, background: '#fff', color: T.ink900,
  boxShadow: 'var(--shadow-xs)' };
function TextIn(props) { return <input {...props} style={{ ...inputStyle, ...(props.style || {}) }} />; }
function TextArea(props) { return <textarea {...props} style={{ ...inputStyle, resize: 'none', ...(props.style || {}) }} />; }
function Sel({ options, ...rest }) {
  return <select {...rest} style={{ ...inputStyle, ...(rest.style || {}) }}>
    {options.map(function (o) { return <option key={o} value={o}>{o}</option>; })}</select>;
}

/* Reason-gated action: a primary button that stays disabled until a reason is typed. */
function ReasonAction({ label, placeholder, onSubmit, variant = 'primary' }) {
  const [v, setV] = React.useState('');
  return (
    <React.Fragment>
      <Fld label="Reason" required help="Recorded verbatim in the changelog with your name and the time.">
        <TextArea rows={3} value={v} placeholder={placeholder} onChange={function (e) { setV(e.target.value); }} />
      </Fld>
      <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <Btn size="md" variant={variant} disabled={!v.trim()} onClick={function () { onSubmit(v.trim()); }}>{label}</Btn>
      </div>
    </React.Fragment>
  );
}


/* Status label for fixed-width table cells — same visual as Tag, but allowed to
   wrap so the fixed domain vocabulary is never truncated. */
function WrapTag({ tone = 'neutral', children }) {
  const [bg, fg, bd] = TONE[tone];
  return (
    <span style={{ display: 'inline-block', background: bg, color: fg, border: '1px solid ' + bd,
      borderRadius: 8, padding: '2px 6px', fontSize: 11, fontWeight: 500, lineHeight: 1.35,
      whiteSpace: 'normal' }}>{children}</span>
  );
}


/* Lucide 0.417.0 glyph subset, inlined so the kits carry no CDN dependency.
   Monochrome currentColor, 1.5px stroke — never filled, never duotone. */
const ICO = {
  'layout-dashboard': '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
  'columns-3': '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18M15 3v18"/>',
  'list': '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
  'gantt-chart': '<path d="M8 6h10M6 12h9M11 18h7"/>',
  'calendar': '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
  'users': '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
  'gauge': '<path d="m12 14 4-4"/><path d="M3.34 19a10 10 0 1 1 17.32 0"/>',
  'clock': '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
  'file-check-2': '<path d="M4 22h14a2 2 0 0 0 2-2V8l-6-6H6a2 2 0 0 0-2 2v4"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="m3 15 2 2 4-4"/>',
  'inbox': '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
  'receipt': '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/>',
  'refresh-cw': '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
  'alert-triangle': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  'ban': '<circle cx="12" cy="12" r="10"/><path d="m4.9 4.9 14.2 14.2"/>',
  'search': '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  'filter': '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
  'download': '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><path d="M12 15V3"/>',
  'arrow-up-right': '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
  'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
  'check': '<polyline points="20 6 9 17 4 12"/>'
};
function Ico({ name, size = 16, style }) {
  const d = ICO[name] || ICO['chevron-right'];
  return <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5"
    strokeLinecap="round" strokeLinejoin="round" aria-hidden="true"
    style={{ flexShrink: 0, display: 'block', ...(style || {}) }}
    dangerouslySetInnerHTML={{ __html: d }} />;
}

/* A tinted square holding a monochrome glyph — the only place a saturated hue fills a
   surface. A destination or a category, never a state and never an area. */
function IconTile({ hue = 'blue', size = 'md', name, label }) {
  const s = { sm: [28, 14, 8], md: [36, 18, 12], lg: [48, 22, 12] }[size] || [36, 18, 12];
  return (
    <span role={label ? 'img' : undefined} aria-label={label}
      style={{ width: s[0], height: s[0], flexShrink: 0, display: 'inline-flex', alignItems: 'center',
        justifyContent: 'center', borderRadius: s[2], color: 'var(--tile-' + hue + '-fg)',
        background: 'var(--tile-' + hue + '-bg)', border: '1px solid var(--tile-' + hue + '-border)' }}>
      <Ico name={name} size={s[1]} />
    </span>
  );
}

/* Where am I, what is this, what can I do — on every screen. */
function PageHeader({ crumb, eyebrow, title, description, meta, actions, tabs, activeTab, onTab, tile, hue = 'blue', bleed }) {
  return (
    <header style={{ background: T.card, borderBottom: T.hair, padding: tabs ? '20px 24px 0' : '20px 24px 22px',
      margin: bleed ? '-20px -20px 6px' : undefined }}>
      {crumb && (
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', fontSize: 13, color: T.ink500, marginBottom: 12 }}>
          {crumb.map((c, i) => (
            <React.Fragment key={i}>
              {i > 0 && <Ico name="chevron-right" size={13} style={{ opacity: .5 }} />}
              <span style={{ color: i === crumb.length - 1 ? T.ink700 : T.ink500, whiteSpace: 'nowrap' }}>{c}</span>
            </React.Fragment>
          ))}
        </div>
      )}
      <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
        {tile && <IconTile hue={hue} size="lg" name={tile} />}
        <div style={{ flex: 1, minWidth: 280 }}>
          {eyebrow && <div style={{ fontSize: 11, textTransform: 'uppercase', letterSpacing: '.06em',
            color: 'var(--brand-600)', fontWeight: 500 }}>{eyebrow}</div>}
          <h1 style={{ margin: eyebrow ? '6px 0 0' : 0, fontSize: 34, lineHeight: 1.2, letterSpacing: '-0.026em',
            fontWeight: 500, color: T.ink900 }}>{title}</h1>
          {description && <p style={{ margin: '10px 0 0', fontSize: 15, lineHeight: 1.5, color: T.ink700,
            maxWidth: 720 }}>{description}</p>}
        </div>
        {actions && <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>{actions}</span>}
      </div>
      {meta && (
        <div style={{ display: 'flex', gap: '8px 24px', flexWrap: 'wrap', marginTop: 16, paddingTop: 14,
          borderTop: '1px solid var(--border-subtle)', fontSize: 13, color: T.ink500 }}>{meta}</div>
      )}
      {tabs && (
        <div style={{ display: 'flex', gap: 20, marginTop: 18 }}>
          {tabs.map(t => {
            const on = t.id === activeTab;
            return (
              <button key={t.id} type="button" onClick={() => onTab && onTab(t.id)}
                style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '0 0 12px',
                  fontFamily: 'var(--font-ui)', fontSize: 15, fontWeight: on ? 500 : 400,
                  color: on ? 'var(--brand-700)' : T.ink500, borderBottom: '2px solid ' + (on ? 'var(--brand-600)' : 'transparent'),
                  marginBottom: -1, display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                {t.label}
                {t.count != null && <span style={{ fontFamily: T.mono, fontSize: 12 }}>{t.count}</span>}
              </button>
            );
          })}
        </div>
      )}
    </header>
  );
}

/* Name the condition, then the action. A blank region is a bug. */
function EmptyState({ name = 'inbox', hue = 'slate', title, body, action, dense }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: dense ? 8 : 12,
      textAlign: 'center', padding: dense ? '18px 12px' : '36px 24px' }}>
      <IconTile hue={hue} size={dense ? 'sm' : 'md'} name={name} />
      <div style={{ fontSize: dense ? 13 : 17, fontWeight: 500, color: T.ink900 }}>{title}</div>
      {body && <p style={{ margin: 0, maxWidth: 380, fontSize: dense ? 12 : 14, lineHeight: 1.5, color: T.ink500 }}>{body}</p>}
      {action}
    </div>
  );
}
