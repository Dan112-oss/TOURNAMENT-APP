<?php
declare(strict_types=1);

require_once __DIR__ . '/StandingsEngine.php';

/**
 * MatchResolver
 *
 * The single place where a match result actually gets "applied":
 * marks the match completed/forfeited/void, and either updates
 * round-robin standings or advances the winner into the next
 * knockout bracket slot.
 */
class MatchResolver
{
    private PDO $db;
    private StandingsEngine $standings;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->standings = new StandingsEngine($db);
    }

    /**
     * Apply a normal (played) result — used both for opponent-confirmed
     * results and for a host manually entering/correcting a score.
     */
    public function applyNormalResult(int $matchId, int $score1, int $score2, string $resolutionType = 'normal'): void
    {
        $match = $this->getMatch($matchId);
        if ($match === null) {
            throw new InvalidArgumentException('Match not found.');
        }
        if ($match['player1_id'] === null || $match['player2_id'] === null) {
            throw new RuntimeException('Both players must be set on this match before a result can be applied.');
        }

        $tournament = $this->getTournamentByMatch($matchId);

        if ($score1 === $score2 && $tournament['format'] === 'knockout') {
            throw new RuntimeException('Knockout matches cannot end in a draw — a winner is required.');
        }

        $winnerId = null;
        if ($score1 > $score2) {
            $winnerId = (int) $match['player1_id'];
        } elseif ($score2 > $score1) {
            $winnerId = (int) $match['player2_id'];
        }

        $this->db->beginTransaction();
        try {
            $this->updateMatchStatus($matchId, 'completed', $winnerId, $resolutionType);

            if ($tournament['format'] === 'round_robin') {
                $this->standings->applyResult($tournament['id'], (int) $match['player1_id'], (int) $match['player2_id'], $score1, $score2);
            } else {
                $this->advanceWinner((int) $tournament['id'], $match, $winnerId);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Award a walkover to one participant (the other didn't show / didn't submit).
     * Round-robin uses a standard 3-0 scoreline for the standings; knockout
     * simply advances the winner.
     */
    public function applyWalkover(int $matchId, int $winnerParticipantId): void
    {
        $match = $this->getMatch($matchId);
        if ($match === null) {
            throw new InvalidArgumentException('Match not found.');
        }

        $player1Id = (int) $match['player1_id'];
        $player2Id = (int) $match['player2_id'];

        if ($winnerParticipantId !== $player1Id && $winnerParticipantId !== $player2Id) {
            throw new InvalidArgumentException('Winner must be one of the two match participants.');
        }

        $tournament = $this->getTournamentByMatch($matchId);

        $this->db->beginTransaction();
        try {
            $this->updateMatchStatus($matchId, 'forfeited', $winnerParticipantId, 'walkover');

            if ($tournament['format'] === 'round_robin') {
                if ($winnerParticipantId === $player1Id) {
                    $this->standings->applyResult($tournament['id'], $player1Id, $player2Id, 3, 0);
                } else {
                    $this->standings->applyResult($tournament['id'], $player1Id, $player2Id, 0, 3);
                }
            } else {
                $this->advanceWinner((int) $tournament['id'], $match, $winnerParticipantId);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Void a match — neither side gets points, no bracket advancement.
     * Used when both players fail to submit/show up.
     */
    public function applyVoid(int $matchId): void
    {
        $match = $this->getMatch($matchId);
        if ($match === null) {
            throw new InvalidArgumentException('Match not found.');
        }

        $this->updateMatchStatus($matchId, 'void', null, 'no_show_both');
    }

    // ------------------------------------------------------------
    // Knockout bracket advancement
    // ------------------------------------------------------------

    private function advanceWinner(int $tournamentId, array $match, ?int $winnerId): void
    {
        if ($winnerId === null || $match['bracket_position'] === null) {
            return; // defensive guard — shouldn't happen for a valid knockout match
        }

        $currentPosition = (int) $match['bracket_position'];
        $nextRound = (int) $match['round'] + 1;
        $nextPosition = (int) ceil($currentPosition / 2);

        $stmt = $this->db->prepare(
            "SELECT id FROM matches
             WHERE tournament_id = :tournament_id AND round = :round AND bracket_position = :position
             LIMIT 1"
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':round' => $nextRound,
            ':position' => $nextPosition,
        ]);
        $nextMatch = $stmt->fetch();

        if (!$nextMatch) {
            return; // this was the final — nothing further to advance into
        }

        // Odd positions feed the player1 slot of the next match, even
        // positions feed player2 — matches the pairing order used when
        // FixtureGenerator created round 1.
        $slot = ($currentPosition % 2 !== 0) ? 'player1_id' : 'player2_id';

        $update = $this->db->prepare("UPDATE matches SET {$slot} = :winner_id WHERE id = :id");
        $update->execute([
            ':winner_id' => $winnerId,
            ':id' => $nextMatch['id'],
        ]);
    }

    // ------------------------------------------------------------
    // Data access helpers
    // ------------------------------------------------------------

    private function updateMatchStatus(int $matchId, string $status, ?int $winnerId, string $resolutionType): void
    {
        $stmt = $this->db->prepare(
            "UPDATE matches SET status = :status, winner_id = :winner_id, resolution_type = :resolution_type
             WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $status,
            ':winner_id' => $winnerId,
            ':resolution_type' => $resolutionType,
            ':id' => $matchId,
        ]);
    }

    private function getMatch(int $matchId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM matches WHERE id = :id');
        $stmt->execute([':id' => $matchId]);
        $match = $stmt->fetch();

        return $match ?: null;
    }

    private function getTournamentByMatch(int $matchId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.id, t.format FROM tournaments t
             INNER JOIN matches m ON m.tournament_id = t.id
             WHERE m.id = :match_id"
        );
        $stmt->execute([':match_id' => $matchId]);

        return $stmt->fetch();
    }
}
