<?php
declare(strict_types=1);

/**
 * FixtureGenerator
 *
 * Generates match fixtures for a tournament and inserts them into
 * the `matches` table.
 *
 * - round_robin: uses the "circle method" to pair every participant
 *   against every other participant. Legs (1 or 2) come from the
 *   tournament's rules_json — the host sets this per tournament
 *   (single round-robin, or double/home-and-away).
 * - knockout: requires a power-of-2 participant count (enforced —
 *   no byes). Round 1 pairings are randomly seeded; later rounds
 *   are created as empty "TBD" placeholder matches that get filled
 *   in as winners are decided.
 */
class FixtureGenerator
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate and persist fixtures for the given tournament.
     *
     * @return array List of created match records (id, round, bracket_position, player1_id, player2_id)
     * @throws InvalidArgumentException if the tournament doesn't exist
     * @throws RuntimeException on invalid participant counts or other generation failures
     */
    public function generate(int $tournamentId): array
    {
        $tournament = $this->getTournament($tournamentId);
        if ($tournament === null) {
            throw new InvalidArgumentException('Tournament not found.');
        }

        $participants = $this->getActiveParticipants($tournamentId);
        $count = count($participants);

        if ($count < 2) {
            throw new RuntimeException('At least 2 active participants are required to generate fixtures.');
        }

        $this->db->beginTransaction();

        try {
            if ($tournament['format'] === 'knockout') {
                $this->validatePowerOfTwo($count);
                $matches = $this->generateKnockout($tournamentId, $participants);
            } else {
                $legs = $this->resolveLegs($tournament['rules_json']);
                $matches = $this->generateRoundRobin($tournamentId, $participants, $legs);
            }

            $this->db->commit();
            return $matches;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------
    // Round-robin (circle method)
    // ------------------------------------------------------------

    /**
     * @param array<int, array{id:int}> $participants
     */
    private function generateRoundRobin(int $tournamentId, array $participants, int $legs): array
    {
        $ids = array_column($participants, 'id');
        $n = count($ids);

        // Odd number of participants: add a "bye" slot (null) so the
        // circle method still works. Any match paired against the bye
        // slot is simply skipped, not inserted.
        if ($n % 2 !== 0) {
            $ids[] = null;
            $n++;
        }

        $roundsPerLeg = $n - 1;
        $half = intdiv($n, 2);
        $rotation = $ids;
        $insertedMatches = [];

        for ($round = 1; $round <= $roundsPerLeg; $round++) {
            for ($i = 0; $i < $half; $i++) {
                $home = $rotation[$i];
                $away = $rotation[$n - 1 - $i];

                if ($home === null || $away === null) {
                    continue; // one side has the bye this round
                }

                $insertedMatches[] = $this->insertMatch($tournamentId, $round, null, $home, $away);

                if ($legs === 2) {
                    // Second leg: reverse home/away, offset into the
                    // second half of the schedule.
                    $insertedMatches[] = $this->insertMatch(
                        $tournamentId,
                        $round + $roundsPerLeg,
                        null,
                        $away,
                        $home
                    );
                }
            }

            // Rotate all but the first fixed element.
            $fixed = $rotation[0];
            $rest = array_slice($rotation, 1);
            array_unshift($rest, array_pop($rest));
            $rotation = array_merge([$fixed], $rest);
        }

        return $insertedMatches;
    }

    /**
     * Reads the host-configured number of legs from rules_json.
     * Defaults to 1 (single round-robin) if not set.
     */
    private function resolveLegs(?string $rulesJson): int
    {
        if (empty($rulesJson)) {
            return 1;
        }

        $rules = json_decode($rulesJson, true);
        if (!is_array($rules)) {
            return 1;
        }

        return (isset($rules['legs']) && (int) $rules['legs'] === 2) ? 2 : 1;
    }

    // ------------------------------------------------------------
    // Knockout bracket
    // ------------------------------------------------------------

    /**
     * @param array<int, array{id:int}> $participants
     */
    private function generateKnockout(int $tournamentId, array $participants): array
    {
        $ids = array_column($participants, 'id');
        shuffle($ids); // random seeding for round 1

        $insertedMatches = [];
        $round = 1;
        $position = 1;

        // Round 1: pair up the randomly seeded participants.
        for ($i = 0; $i < count($ids); $i += 2) {
            $insertedMatches[] = $this->insertMatch(
                $tournamentId,
                $round,
                $position,
                $ids[$i],
                $ids[$i + 1]
            );
            $position++;
        }

        // Subsequent rounds: empty "TBD" placeholder matches, filled
        // in later as winners advance. Stop once we've created the final.
        $matchesInRound = count($ids) / 2;
        $round++;

        while ($matchesInRound > 1) {
            $matchesInRound = intdiv($matchesInRound, 2);
            $position = 1;

            for ($i = 0; $i < $matchesInRound; $i++) {
                $insertedMatches[] = $this->insertMatch($tournamentId, $round, $position, null, null);
                $position++;
            }

            $round++;
        }

        return $insertedMatches;
    }

    private function validatePowerOfTwo(int $n): void
    {
        if ($n < 2 || ($n & ($n - 1)) !== 0) {
            throw new RuntimeException(
                "Knockout tournaments require a power-of-2 number of participants " .
                "(2, 4, 8, 16, 32...). Current active participant count: {$n}."
            );
        }
    }

    // ------------------------------------------------------------
    // Data access helpers
    // ------------------------------------------------------------

    private function getTournament(int $tournamentId): ?array
    {
        $stmt = $this->db->prepare('SELECT id, format, rules_json FROM tournaments WHERE id = :id');
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch();

        return $tournament ?: null;
    }

    private function getActiveParticipants(int $tournamentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM participants
             WHERE tournament_id = :tournament_id AND status = 'active'
             ORDER BY joined_at ASC"
        );
        $stmt->execute([':tournament_id' => $tournamentId]);

        return $stmt->fetchAll();
    }

    private function insertMatch(
        int $tournamentId,
        int $round,
        ?int $bracketPosition,
        ?int $player1Id,
        ?int $player2Id
    ): array {
        $stmt = $this->db->prepare(
            "INSERT INTO matches (tournament_id, round, bracket_position, player1_id, player2_id, status)
             VALUES (:tournament_id, :round, :bracket_position, :player1_id, :player2_id, 'pending')"
        );
        $stmt->execute([
            ':tournament_id' => $tournamentId,
            ':round' => $round,
            ':bracket_position' => $bracketPosition,
            ':player1_id' => $player1Id,
            ':player2_id' => $player2Id,
        ]);

        return [
            'id' => (int) $this->db->lastInsertId(),
            'round' => $round,
            'bracket_position' => $bracketPosition,
            'player1_id' => $player1Id,
            'player2_id' => $player2Id,
        ];
    }
}
