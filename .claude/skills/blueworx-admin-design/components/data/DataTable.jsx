import React from 'react';

/**
 * columns: [{ key, label, align?: 'left'|'right', width? }]
 * rows: [{ id, ...cells }] — a cell may be a string, number or node.
 * groups: [{ id, title, rows, subtotalLabel? }] — rows fall under named group
 *   headers instead of running flat. Pass `groups` or `rows`, not both.
 * total: { label, cells } — a closing row, e.g. a project total.
 *
 * The table never sums anything. A group's subtotal and the total row are
 * figures the caller works out, because only the caller knows which rows count.
 */
export function DataTable({
  columns = [], rows = [], groups = null, total = null,
  selectable = false, selected = [], onToggle, onToggleAll, rowActions, empty, className = '',
}) {
  const flat = groups ? groups.flatMap((g) => g.rows) : rows;
  if (!flat.length && empty) return empty;

  const allChecked = selectable && flat.length > 0 && selected.length === flat.length;
  const span = columns.length + (selectable ? 1 : 0) + (rowActions ? 1 : 0);

  const renderRow = (row) => (
    <tr key={row.id}>
      {selectable ? (
        <td className="bw-table__check">
          <input type="checkbox" aria-label={`Select ${row.id}`} checked={selected.includes(row.id)} onChange={() => onToggle && onToggle(row.id)} />
        </td>
      ) : null}
      {columns.map((c) => (
        <td key={c.key} className={c.align === 'right' ? 'bw-table__num' : undefined}>{row[c.key]}</td>
      ))}
      {rowActions ? <td className="bw-table__actions">{rowActions(row)}</td> : null}
    </tr>
  );

  return (
    <table className={`bw-table ${className}`}>
      <thead>
        <tr>
          {selectable ? (
            <th className="bw-table__check">
              <input type="checkbox" aria-label="Select all" checked={allChecked} onChange={() => onToggleAll && onToggleAll(!allChecked)} />
            </th>
          ) : null}
          {columns.map((c) => (
            <th key={c.key} style={{ width: c.width, textAlign: c.align === 'right' ? 'right' : undefined }}>{c.label}</th>
          ))}
          {rowActions ? <th aria-label="Actions" /> : null}
        </tr>
      </thead>
      <tbody>
        {groups
          ? groups.map((group) => (
              <React.Fragment key={group.id}>
                <tr className="bw-table__group">
                  <td colSpan={span}>
                    <span className="bw-table__group-title">{group.title}</span>
                    {group.subtotalLabel ? <span className="bw-table__group-total">{group.subtotalLabel}</span> : null}
                  </td>
                </tr>
                {group.rows.map(renderRow)}
              </React.Fragment>
            ))
          : rows.map(renderRow)}
        {total ? (
          <tr className="bw-table__total">
            <td colSpan={span}>
              <span className="bw-table__total-label">{total.label}</span>
              {total.value ? <span className="bw-table__group-total">{total.value}</span> : null}
            </td>
          </tr>
        ) : null}
      </tbody>
    </table>
  );
}
