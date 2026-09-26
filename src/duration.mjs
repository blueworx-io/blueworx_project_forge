/**
 * A length of time, said the way tasks say it (#387).
 *
 * Hours are stored to two decimal places, so ten minutes is 0.17 and reads
 * like a mismatch beside a task that says "10 min". This turns any stored
 * figure back into words: "10 min" under an hour, "2h" on the hour, "1h 30m"
 * otherwise, to the nearest five minutes — the smallest step work is planned
 * in (see HOUR_OPTIONS in hours.tsx).
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone.
 *
 * @param {number} hours Hours, possibly negative (a shortfall).
 * @returns {string}
 */
export function durationLabel(hours) {
  const minutes = Math.round((Math.abs(Number(hours) || 0) * 60) / 5) * 5;
  const sign = hours < 0 && minutes > 0 ? '-' : '';

  if (0 === minutes) {
    return '0h';
  }

  if (minutes < 60) {
    return `${sign}${minutes} min`;
  }

  const whole = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return 0 === rest ? `${sign}${whole}h` : `${sign}${whole}h ${rest}m`;
}
