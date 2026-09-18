<?php
declare(strict_types=1);

/**
 * StandingsEngine
 *
 * Applies an approved match result to the `standings` table for
 * round-robin tournaments. Each call is additive — it increments
 * existing totals rather than recomputing from scratch, so it must
 * only ever be called once per approved result.
 */
class StandingsEngine
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Apply a completed match's score to both participants' standings rows.
     */
    public function applyResult(int $tournamentId, int $player1Id, int $player2Id, int $score1, int $score2): void
    {
        $this->ensureRow($tournamentId, $player1Id);
        $this->ensureRow($tournamentId, $player2Id);

        if ($score1 === $score2) {
            $this->increment($tournamentId, $player1Id, wins: 0, draws: 1, losses: 0, goalsFor: $score1, goalsAgainst: $score2, points: 1);
            $this->increment($tournamentId, $player2Id, wins: 0, draws: 1, losses: 0, goalsFor: $score2, goalsAgainst: $score1, points: 1);
        } elseif ($score1 > $score2) {
            $this->increment($tournamentId, $player1Id, wins: 1, draws: 0, losses: 0, goalsFor: $score1, goalsAgainst: $score2, points: 3);
            $this->increment($tournamentId, $player2Id, wins: 0, draws: 0, losses: 1, goalsFor: $score2, goalsAgainst: $score1, points: 0);
        } else {
            $this->increment($tournamentId, $player2Id, wins: 1, draws: 0, losses: 0, goalsFor: $score2, goalsAgainst: $score1, points: 3);
            $this->increment($tournamentId, $player1Id, wins: 0, draws: 0, losses: 1, goalsFor: $score1, goalsAgainst: $score2, points: 0);
        }
    }

    /**
     * Make sure a standings row exists for this participant before
     * incrementing it. No-op if it's already there.
     */
    private function ensureRow(int $tournamentId, int $participantId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO standings (tournament_id, participant_id)
             VALUES (:tournament_id, :participant_id)
             ON DUPLICATE KEY UPDATE participant_id = participant_id"
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':participant_id' => $participantId,
        ]);
    }

    private function increment(
        int $tournamentId,
        int $participantId,
        int $wins,
        int $draws,
        int $losses,
        int $goalsFor,
        int $goalsAgainst,
        int $points
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE standings SET
                played = played + 1,
                wins = wins + :wins,
                draws = draws + :draws,
                losses = losses + :losses,
                goals_for = goals_for + :goals_for,
                goals_against = goals_against + :goals_against,
                points = points + :points
             WHERE tournament_id = :tournament_id AND participant_id = :participant_id"
        );
        $stmt->execute([
            ':wins' => $wins,
            ':draws' => $draws,
            ':losses' => $losses,
            ':goals_for' => $goalsFor,
            ':goals_against' => $goalsAgainst,
            ':points' => $points,
            ':tournament_id' => $tournamentId,
            ':participant_id' => $participantId,
        ]);
    }
}
