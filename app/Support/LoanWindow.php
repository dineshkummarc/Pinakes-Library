<?php

declare(strict_types=1);

namespace App\Support;

use DateTime;

/**
 * The longest loan still possible from a start day when a scheduled loan or
 * a reservation on every copy begins before the wanted return date.
 *
 * One authority for every surface that turns a capacity refusal into words:
 * the public calendar endpoint, the user-area loan and reservation forms,
 * the Mobile API and the admin loan form all ran the same "no free copy on
 * every requested day" gate but answered with a bare "no copies available",
 * which hid that a shorter loan was possible and never said how much
 * shorter. Here the first blocked day bounds the window: the return date is
 * the day before it, and the message names the maximum duration, that
 * return date and the day from which the book is already booked. A loan
 * that would start on a blocked day cannot be shortened, so no cap is
 * reported and the caller keeps its generic message.
 */
final class LoanWindow
{
    /**
     * @param string[] $conflictDates Y-m-d days inside [startDate, endDate]
     *                                without a free copy (CapacityService::unavailableDatesInRange()).
     * @return array{first_conflict_date: string, max_end_date: string, max_duration_days: int}|null
     *         null when nothing bounds the window, when the start day itself is blocked,
     *         or when the next day is (no loan of at least one day is possible).
     */
    public static function cap(string $startDate, array $conflictDates): ?array
    {
        $future = array_filter($conflictDates, static fn (string $d): bool => $d > $startDate);
        if ($future === []) {
            return null;
        }
        $firstConflict = min($future);
        if (in_array($startDate, $conflictDates, true)) {
            return null;
        }
        $maxEnd = (new DateTime($firstConflict))->modify('-1 day')->format('Y-m-d');
        $maxDays = (int) (new DateTime($startDate))->diff(new DateTime($maxEnd))->days;
        // The very next day is blocked: no loan of at least one day exists
        // from this start, so there is nothing to propose and the caller
        // keeps its generic refusal (the calendars say so before sending).
        if ($maxDays < 1) {
            return null;
        }

        return [
            'first_conflict_date' => $firstConflict,
            'max_end_date' => $maxEnd,
            'max_duration_days' => $maxDays,
        ];
    }

    /**
     * The explanation for a capped window, in the current locale, with the
     * dates laid out as the public calendar shows them.
     */
    public static function explain(int $maxDays, string $maxEndDate, string $firstConflictDate): string
    {
        if ($maxDays === 1) {
            return __(
                'Durata massima del prestito: 1 giorno (restituzione entro il %1$s). Dal %2$s il libro è già prenotato.',
                self::displayDate($maxEndDate),
                self::displayDate($firstConflictDate)
            );
        }
        return __(
            'Durata massima del prestito: %1$d giorni (restituzione entro il %2$s). Dal %3$s il libro è già prenotato.',
            $maxDays,
            self::displayDate($maxEndDate),
            self::displayDate($firstConflictDate)
        );
    }

    /**
     * Redirect parameters that carry a cap through a 302 (user-area forms,
     * Mobile API delegation). Empty when nothing bounds the window.
     *
     * @param string[] $conflictDates
     * @return array{max_loan_days?: int, max_end_date?: string, blocked_from?: string}
     */
    public static function redirectParams(string $startDate, array $conflictDates): array
    {
        $cap = self::cap($startDate, $conflictDates);
        if ($cap === null) {
            return [];
        }
        return [
            'max_loan_days' => $cap['max_duration_days'],
            'max_end_date' => $cap['max_end_date'],
            'blocked_from' => $cap['first_conflict_date'],
        ];
    }

    /**
     * The explanation rebuilt from redirect parameters, or null when they are
     * absent or not of the shape redirectParams() emits (query strings are
     * user-editable: anything else is ignored, never echoed).
     *
     * @param array<mixed> $params
     */
    public static function explainFromParams(array $params): ?string
    {
        $days = $params['max_loan_days'] ?? null;
        $end = $params['max_end_date'] ?? null;
        $from = $params['blocked_from'] ?? null;
        if (!is_scalar($days) || !is_string($end) || !is_string($from)) {
            return null;
        }
        if (preg_match('/^\d{1,4}$/', (string) $days) !== 1
            || !DateHelper::isISODateFormat($end)
            || !DateHelper::isISODateFormat($from)) {
            return null;
        }
        return self::explain((int) $days, $end, $from);
    }

    private static function displayDate(string $ymd): string
    {
        $format = str_starts_with(I18n::getLocale(), 'en') ? 'm-d-Y' : 'd-m-Y';
        return (new DateTime($ymd))->format($format);
    }
}
