/**
 * How many working days something has waited (#496).
 *
 * Saturdays and Sundays never count, and something that arrives at the
 * weekend starts counting from Monday: sent Friday and still open on Monday
 * is one day, not three. Counted in the studio's own time, which is the
 * browser's here. Bank holidays and closures are not known, so they count.
 *
 * Plain JavaScript with no DOM, so it can be tested with node alone.
 *
 * @param {number} from  Unix seconds it arrived.
 * @param {number} until Unix seconds it was answered, or now.
 * @returns {number}
 */
export function workingDaysBetween(from, until) {
  const day = new Date(from * 1000);
  const end = new Date(until * 1000);
  let days = 0;

  day.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);

  // Arriving at the weekend is arriving on Monday.
  while (isWeekend(day) && day < end) {
    day.setDate(day.getDate() + 1);
  }

  while (day < end) {
    day.setDate(day.getDate() + 1);

    if (!isWeekend(day)) {
      days += 1;
    }
  }

  return days;
}

/**
 * @param {Date} day
 * @returns {boolean}
 */
function isWeekend(day) {
  return 0 === day.getDay() || 6 === day.getDay();
}
