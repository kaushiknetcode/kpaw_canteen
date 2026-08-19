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
    $dayOfWeek = (int) $date->format('w'); // 0 = Sunday, 6 = Saturday
    if ($dayOfWeek === 0) return true;                              // Sunday: fully blocked
    if ($dayOfWeek === 6 && $meal_type !== 'breakfast') return true; // Saturday: breakfast only
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
 * Format a date as "Thu, 20-08-2026" — day name included, per the
 * project's DD-MM-YYYY display convention.
 */
function format_date_with_day(string $ymd): string
{
    return date('D, d-m-Y', strtotime($ymd));
}