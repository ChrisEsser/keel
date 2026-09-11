<?php

declare(strict_types=1);

namespace Framework\Http;

/**
 * The reporting window a page is showing, read off `?from=` and `?to=`.
 *
 * One parser, shared by every screen with a date range on it. Not tidiness -- correctness. A
 * second endpoint parsing the same window its own way is a second chance for the tiles and the
 * chart on one screen to disagree about which range they are describing, and the disagreement is
 * invisible: both render perfectly, with different numbers.
 *
 * UTC throughout, because Framework\Database::connect() pins the MySQL session to it. A window
 * built in local time slices the data on a different boundary than the rows were stamped with, so
 * a report is short or long by up to a day at each end and nothing says so.
 */
final class DateWindow
{
    /**
     * The window to report on, as a pair of UTC datetimes.
     *
     * A backwards range is a typo rather than a request for nothing, so it is swapped rather than
     * honoured -- otherwise the screen answers an empty result to a question the user did ask.
     *
     * @return array{0:string,1:string}
     */
    public static function fromRequest(Request $request, int $defaultDays): array
    {
        $from = self::dayStart(trim((string) $request->query('from', '')))
            ?? gmdate('Y-m-d 00:00:00', strtotime('-' . ($defaultDays - 1) . ' days'));
        $to = self::dayEnd(trim((string) $request->query('to', ''))) ?? gmdate('Y-m-d 23:59:59');

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    /** How many days the window spans, inclusive. For copy that has to name the range. */
    public static function days(string $from, string $to): int
    {
        $a = strtotime(substr($from, 0, 10) . ' UTC');
        $b = strtotime(substr($to, 0, 10) . ' UTC');

        return $a === false || $b === false ? 0 : (int) floor(($b - $a) / 86400) + 1;
    }

    /** Null for anything that isn't a bare YYYY-MM-DD, so a junk query string falls to the default. */
    public static function dayStart(string $date): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date . ' 00:00:00' : null;
    }

    public static function dayEnd(string $date): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date . ' 23:59:59' : null;
    }
}
