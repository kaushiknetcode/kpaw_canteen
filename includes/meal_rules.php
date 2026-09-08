<?php
/**
 * Rolling-window booking rules (Phase 3 spec, docs/MASTER-PLAN.md §2).
 * Requires bootstrap.php (timezone + $pdo) already included.
 */

function get_meal_timing_rules(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $stmt = $pdo->query("SELECT * FROM meal_timing_rules");
        $cache = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['meal_type']] = $row;
        }
    }
    return $cache;
}

/**
 * The modular weekly pattern — 7 days x 3 meals, each independently
 * on/off. Replaces what used to be hardcoded "Sunday blocked,
 * Saturday breakfast only" logic. Any admin, via a direct DB edit for
 * now (no UI yet), can turn any single day+meal combination on or
 * off — e.g. opening a specific Saturday to all 3 meals, or cutting a
 * normal weekday down to breakfast-only.
 */
function get_weekly_availability(PDO $pdo): array
{
    static $cache = null;
    if ($cache === null) {
        $stmt = $pdo->query("SELECT * FROM weekly_availability");
        $cache = [];
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['day_of_week']][$row['meal_type']] = (bool) $row['is_available'];
        }
    }
    return $cache;
}

function bookings_are_stopped(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key = 'bookings_stopped'");
    return $stmt->fetchColumn() === '1';
}

function is_holiday(PDO $pdo, string $date): bool
{
    $stmt = $pdo->prepare("SELECT blocks_all_meals FROM holidays WHERE holiday_date = :d");
    $stmt->execute([':d' => $date]);
    $row = $stmt->fetch();
    return $row && (int) $row['blocks_all_meals'] === 1;
}

function is_day_blocked_for_meal(PDO $pdo, DateTime $date, string $meal_type): bool
{
    $dayAbbr = strtolower($date->format('D')); // 'sun', 'mon', ... matches the ENUM values
    $availability = get_weekly_availability($pdo);
    // Missing row = fail CLOSED (treated as blocked), not open — safer
    // default if a day/meal combination is ever missing from the table.
    $isAvailable = $availability[$dayAbbr][$meal_type] ?? false;
    if (!$isAvailable) return true;
    if (is_holiday($pdo, $date->format('Y-m-d'))) return true;
    return false;
}

/**
 * Returns every meal slot bookable right now. Rolls FORWARD past any
 * number of consecutive blocked days (weekends, holidays) rather than
 * giving up after checking just one day ahead — e.g. Friday after the
 * lunch cutoff correctly offers Monday's lunch, not nothing for 48 hours.
 */
function get_available_meal_slots(PDO $pdo): array
{
    if (bookings_are_stopped($pdo)) {
        return [];
    }

    $rules = get_meal_timing_rules($pdo);
    $now = new DateTime('now');
    $slots = [];

    foreach ($rules as $type => $rule) {
        $closesToday = DateTime::createFromFormat('Y-m-d H:i:s', $now->format('Y-m-d') . ' ' . $rule['closes']);
        $target = clone $now;
        if ($now >= $closesToday) {
            $target->modify('+1 day');
        }

        // Roll forward (capped, as a safety valve) until we land on a day
        // this meal type is actually bookable on.
        $attempts = 0;
        while (is_day_blocked_for_meal($pdo, $target, $type) && $attempts < 14) {
            $target->modify('+1 day');
            $attempts++;
        }
        if ($attempts >= 14) continue; // shouldn't happen — guards against bad holiday data

        $targetDateStr = $target->format('Y-m-d');
        $daysAhead = (int) $now->diff($target)->format('%r%a');
        $prefix = match (true) {
            $daysAhead <= 0 => "Today's ",
            $daysAhead === 1 => "Tomorrow's ",
            default          => $target->format('l') . "'s ",
        };
        $closesAt = DateTime::createFromFormat('Y-m-d H:i:s', $targetDateStr . ' ' . $rule['closes']);

        $slots[] = [
            'meal_type'   => $type,
            'target_date' => $targetDateStr,
            'label'       => $prefix . $rule['label'],
            'serve_start' => $rule['serve_start'],
            'serve_end'   => $rule['serve_end'],
            'closes_at'   => $closesAt->format(DateTime::ATOM),
        ];
    }

    return $slots;
}

function find_slot(PDO $pdo, string $meal_type, string $target_date): ?array
{
    foreach (get_available_meal_slots($pdo) as $slot) {
        if ($slot['meal_type'] === $meal_type && $slot['target_date'] === $target_date) {
            return $slot;
        }
    }
    return null;
}

function is_slot_still_valid(PDO $pdo, string $meal_type, string $target_date): bool
{
    return find_slot($pdo, $meal_type, $target_date) !== null;
}

/**
 * NEW — the combined-booking version of the availability logic above.
 * Groups by DAY instead of by meal type: returns up to 2 day-groups
 * (always exactly 2 once there's more than one day's worth of meals
 * left to show), each listing every meal still bookable that day.
 * Rolls forward exactly like get_available_meal_slots() does, and
 * reuses the same is_day_blocked_for_meal()/weekly_availability
 * checks — this is a new VIEW over the same underlying rules, not a
 * different rule set.
 *
 * If today has zero bookable meals left (all closed or blocked), it's
 * skipped entirely rather than shown as an empty group — so the two
 * cards shown are always the next two days that actually have
 * something bookable, matching the agreed "always exactly 2 cards,
 * rolling forward together" behavior.
 */
function get_bookable_day_groups(PDO $pdo): array
{
    if (bookings_are_stopped($pdo)) {
        return [];
    }

    $rules = get_meal_timing_rules($pdo);
    $now = new DateTime('now');
    $today = $now->format('Y-m-d');
    $groups = [];
    $cursor = clone $now;
    $daysChecked = 0;

    while (count($groups) < 2 && $daysChecked < 21) { // 21-day safety cap, same spirit as the 14-day cap elsewhere
        $dateStr = $cursor->format('Y-m-d');
        $mealsForDay = [];

        foreach ($rules as $type => $rule) {
            if (is_day_blocked_for_meal($pdo, $cursor, $type)) {
                continue;
            }
            // Only today's cutoff can have already passed — a future
            // day's cutoff, by definition, hasn't happened yet.
            if ($dateStr === $today) {
                $closesToday = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $rule['closes']);
                if ($now >= $closesToday) {
                    continue;
                }
            }
            $closesAt = DateTime::createFromFormat('Y-m-d H:i:s', $dateStr . ' ' . $rule['closes']);
            $mealsForDay[] = [
                'meal_type'   => $type,
                'serve_start' => $rule['serve_start'],
                'serve_end'   => $rule['serve_end'],
                'closes_at'   => $closesAt->format(DateTime::ATOM),
                'label'       => $rule['label'],
            ];
        }

        if (!empty($mealsForDay)) {
            $daysAhead = (int) $now->diff($cursor)->format('%r%a');
            $dayLabel = match (true) {
                $daysAhead <= 0  => 'Today',
                $daysAhead === 1 => 'Tomorrow',
                default          => $cursor->format('l'),
            };
            $groups[] = ['date' => $dateStr, 'label' => $dayLabel, 'meals' => $mealsForDay];
        }

        $cursor->modify('+1 day');
        $daysChecked++;
    }

    return $groups;
}

/**
 * Re-validation at submission time, for the combined-booking flow —
 * the same purpose is_slot_still_valid() serves for the old flow.
 */
function is_meal_bookable_on(PDO $pdo, string $meal_type, string $date): bool
{
    foreach (get_bookable_day_groups($pdo) as $group) {
        if ($group['date'] === $date) {
            foreach ($group['meals'] as $m) {
                if ($m['meal_type'] === $meal_type) {
                    return true;
                }
            }
        }
    }
    return false;
}

/**
 * Format a date as "Thu, 20-08-2026" — day name included, per the
 * project's DD-MM-YYYY display convention.
 */
function format_date_with_day(string $ymd): string
{
    return date('D, d-m-Y', strtotime($ymd));
}
