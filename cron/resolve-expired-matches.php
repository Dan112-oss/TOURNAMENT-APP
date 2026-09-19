<?php
declare(strict_types=1);

/**
 * cron/resolve-expired-matches.php
 *
 * Run this hourly via a cPanel (or equivalent) cron job:
 *   php /path/to/cron/resolve-expired-matches.php
 *
 * CLI only — refuses to run over HTTP, since it has no auth layer
 * of its own and isn't meant to be web-accessible.
 *
 * Does two things, in order:
 *  1. Auto-starts any tournament whose start_date has arrived
 *     (generates fixtures, flips status to in_progress).
 *  2. Auto-resolves matches nobody finished in time, per each
 *     tournament's no_show_policy ('walkover' | 'void' | 'manual').
 *     Two sub-cases:
 *       a) Neither player submitted a result by scheduled_deadline
 *          (+ grace_period_hours) -> resolved as void (no submitter
 *          exists to award a walkover to).
 *       b) One player submitted but the opponent never
 *          confirmed/disputed, and submitted_at (+ grace_period_hours)
 *          has passed -> 'walkover' awards the submitter the win,
 *          'void' voids it, 'manual' leaves it for the host.
 *     In both sub-cases, whichever participant(s) failed to act get
 *     a missed-match strike, with auto-disqualification once
 *     max_missed_matches (if set) is reached.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script may only be run from the command line.';
    exit(1);
}

require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/../core/FixtureGenerator.php';
require_once __DIR__ . '/../core/MatchResolver.php';

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/**
 * @return array{no_show_policy: string, grace_period_hours: int, max_missed_matches: ?int}
 */
function parseRules(?string $rulesJson): array
{
    $rules = $rulesJson !== null ? json_decode($rulesJson, true) : null;
    if (!is_array($rules)) {
        $rules = [];
    }

    return [
        'no_show_policy' => in_array($rules['no_show_policy'] ?? null, ['walkover', 'void', 'manual'], true)
            ? $rules['no_show_policy']
            : 'manual',
        'grace_period_hours' => isset($rules['grace_period_hours']) ? (int) $rules['grace_period_hours'] : 24,
        'max_missed_matches' => isset($rules['max_missed_matches']) && $rules['max_missed_matches'] !== null
            ? (int) $rules['max_missed_matches']
            : null,
    ];
}

function incrementMissedMatch(PDO $db, int $participantId, ?int $maxMissed): void
{
    $update = $db->prepare('UPDATE participants SET missed_match_count = missed_match_count + 1 WHERE id = :id');
    $update->execute([':id' => $participantId]);

    if ($maxMissed === null || $maxMissed <= 0) {
        return;
    }

    $check = $db->prepare('SELECT missed_match_count FROM participants WHERE id = :id');
    $check->execute([':id' => $participantId]);
    $row = $check->fetch();

    if ($row && (int) $row['missed_match_count'] >= $maxMissed) {
        $disqualify = $db->prepare(
            "UPDATE participants SET status = 'disqualified' WHERE id = :id AND status = 'active'"
        );
        $disqualify->execute([':id' => $participantId]);
        logLine("  -> participant {$participantId} auto-disqualified (missed_match_count reached {$maxMissed}).");
    }
}

$db = Database::getConnection();
$fixtureGenerator = new FixtureGenerator($db);
$matchResolver = new MatchResolver($db);

// ------------------------------------------------------------
// 1. Auto-start tournaments whose start_date has arrived
// ------------------------------------------------------------
logLine('Checking for tournaments ready to auto-start...');

$dueStmt = $db->prepare(
    "SELECT id, name FROM tournaments
     WHERE status = 'open_for_registration' AND start_date IS NOT NULL AND start_date <= CURDATE()"
);
$dueStmt->execute();
$dueTournaments = $dueStmt->fetchAll();

foreach ($dueTournaments as $tournament) {
    $tournamentId = (int) $tournament['id'];

    try {
        $matches = $fixtureGenerator->generate($tournamentId);

        $update = $db->prepare("UPDATE tournaments SET status = 'in_progress' WHERE id = :id");
        $update->execute([':id' => $tournamentId]);

        logLine("Started tournament #{$tournamentId} ({$tournament['name']}) — " . count($matches) . ' matches created.');
    } catch (Throwable $e) {
        // Skip this tournament, keep processing the rest of the run.
        logLine("Failed to start tournament #{$tournamentId} ({$tournament['name']}): " . $e->getMessage());
    }
}

// ------------------------------------------------------------
// 2a. Neither player submitted a result before the deadline
// ------------------------------------------------------------
logLine('Checking for matches with no submission past deadline...');

$noSubmissionStmt = $db->prepare(
    "SELECT m.id, m.tournament_id, m.player1_id, m.player2_id, m.scheduled_deadline, t.rules_json
     FROM matches m
     INNER JOIN tournaments t ON t.id = m.tournament_id
     WHERE m.status = 'pending'
       AND m.scheduled_deadline IS NOT NULL
       AND m.scheduled_deadline <= NOW()
       AND m.player1_id IS NOT NULL
       AND m.player2_id IS NOT NULL"
);
$noSubmissionStmt->execute();
$noSubmissionMatches = $noSubmissionStmt->fetchAll();

foreach ($noSubmissionMatches as $match) {
    $rules = parseRules($match['rules_json']);
    $deadline = new DateTime($match['scheduled_deadline']);
    $deadline->modify("+{$rules['grace_period_hours']} hours");

    if ($deadline > new DateTime()) {
        continue; // still within grace period
    }

    if ($rules['no_show_policy'] === 'manual') {
        continue; // host handles this one manually
    }

    $matchId = (int) $match['id'];

    try {
        // No submitter exists on either side, so there's nothing to
        // award a walkover to — this always resolves as void.
        $matchResolver->applyVoid($matchId);

        incrementMissedMatch($db, (int) $match['player1_id'], $rules['max_missed_matches']);
        incrementMissedMatch($db, (int) $match['player2_id'], $rules['max_missed_matches']);

        logLine("Voided match #{$matchId} (tournament #{$match['tournament_id']}) — no submission from either player.");
    } catch (Throwable $e) {
        logLine("Failed to void match #{$matchId}: " . $e->getMessage());
    }
}

// ------------------------------------------------------------
// 2b. One player submitted, opponent never confirmed/disputed
// ------------------------------------------------------------
logLine('Checking for unconfirmed submissions past the confirmation window...');

$unconfirmedStmt = $db->prepare(
    "SELECT
        m.id AS match_id, m.tournament_id, m.player1_id, m.player2_id,
        t.rules_json,
        mr.id AS result_id, mr.submitted_by, mr.score_player1, mr.score_player2, mr.submitted_at,
        p1.user_id AS player1_user_id, p2.user_id AS player2_user_id
     FROM matches m
     INNER JOIN tournaments t ON t.id = m.tournament_id
     INNER JOIN participants p1 ON p1.id = m.player1_id
     INNER JOIN participants p2 ON p2.id = m.player2_id
     INNER JOIN match_results mr ON mr.match_id = m.id AND mr.status = 'pending_confirmation'
     WHERE m.status = 'awaiting_confirmation'"
);
$unconfirmedStmt->execute();
$unconfirmedMatches = $unconfirmedStmt->fetchAll();

foreach ($unconfirmedMatches as $row) {
    $rules = parseRules($row['rules_json']);
    $expiresAt = new DateTime($row['submitted_at']);
    $expiresAt->modify("+{$rules['grace_period_hours']} hours");

    if ($expiresAt > new DateTime()) {
        continue; // opponent still has time to confirm/dispute
    }

    if ($rules['no_show_policy'] === 'manual') {
        continue; // host handles this one manually
    }

    $matchId = (int) $row['match_id'];
    $isPlayer1Submitter = (int) $row['submitted_by'] === (int) $row['player1_user_id'];
    $submitterParticipantId = $isPlayer1Submitter ? (int) $row['player1_id'] : (int) $row['player2_id'];
    $opponentParticipantId = $isPlayer1Submitter ? (int) $row['player2_id'] : (int) $row['player1_id'];

    try {
        if ($rules['no_show_policy'] === 'walkover') {
            $matchResolver->applyWalkover($matchId, $submitterParticipantId);
            logLine("Awarded walkover on match #{$matchId} to participant {$submitterParticipantId} — opponent never confirmed.");
        } else {
            // 'void': submission existed but nobody resolved it in time.
            $matchResolver->applyVoid($matchId);
            logLine("Voided match #{$matchId} — submission was never confirmed in time.");
        }

        // The non-responding opponent gets the strike either way —
        // they're the one who failed to confirm or dispute.
        incrementMissedMatch($db, $opponentParticipantId, $rules['max_missed_matches']);

        $updateResult = $db->prepare(
            "UPDATE match_results SET status = 'host_resolved', reviewed_at = NOW() WHERE id = :id"
        );
        $updateResult->execute([':id' => (int) $row['result_id']]);
    } catch (Throwable $e) {
        logLine("Failed to auto-resolve match #{$matchId}: " . $e->getMessage());
    }
}

logLine('Cron run complete.');
