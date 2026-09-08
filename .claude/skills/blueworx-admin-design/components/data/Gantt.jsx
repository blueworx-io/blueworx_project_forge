import React from 'react';

const BAR_KIND = {
  pre: 'bw-gantt__bar--pre',
  launch: 'bw-gantt__bar--launch',
  post: 'bw-gantt__bar--post',
};

/** A bar narrower than this reads as a smudge rather than a duration. */
const MIN_BAR_PERCENT = 3.5;

/**
 * Phases positioned against a scale. `span` is the scale's full width in the
 * same unit the phases are numbered in — weeks, usually.
 *
 * A bar's left and width are set inline because they are data, not styling:
 * they come from the phase's own start and end. Everything else about a bar
 * comes from its kind.
 */
export function Gantt({ phases = [], span = 1, ticks = [], legend = true, actions, className = '' }) {
  const scale = Math.max(span, 1);
  return (
    <div className={`bw-gantt ${className}`}>
      {ticks.length > 0 ? (
        <div className="bw-gantt__ruler">
          {ticks.map((tick) => <span className="bw-gantt__tick" key={tick}>{tick}</span>)}
        </div>
      ) : null}

      <div className="bw-gantt__rows">
        {phases.map((phase, index) => (
          <div className="bw-gantt__row" key={phase.id}>
            <span className="bw-gantt__label">
              <span className="bw-gantt__title">
                <span className="bw-gantt__n">{String(index + 1).padStart(2, '0')}</span>
                {phase.title}
              </span>
              {phase.range ? <span className="bw-gantt__range">{phase.range}</span> : null}
            </span>
            <span className="bw-gantt__track">
              <span
                className={[
                  'bw-gantt__bar',
                  BAR_KIND[phase.kind] || BAR_KIND.pre,
                  phase.visible === false ? 'is-hidden' : '',
                ].filter(Boolean).join(' ')}
                style={{
                  left: `${((phase.start - 1) / scale) * 100}%`,
                  width: `${Math.max(MIN_BAR_PERCENT, ((phase.end - phase.start + 1) / scale) * 100)}%`,
                }}
              >
                {phase.label}
              </span>
            </span>
            {actions ? <span className="bw-gantt__actions">{actions(phase, index)}</span> : null}
          </div>
        ))}
      </div>

      {legend ? (
        <div className="bw-gantt__legend">
          <span className="bw-gantt__key">Pre-launch</span>
          <span className="bw-gantt__key bw-gantt__key--launch">Launch milestone</span>
          <span className="bw-gantt__key bw-gantt__key--post">Post-launch</span>
        </div>
      ) : null}
    </div>
  );
}
