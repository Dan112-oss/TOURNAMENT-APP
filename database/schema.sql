-- ============================================================
-- Tournament Bracket App — Full MySQL Schema
-- Engine: InnoDB (required for foreign key constraints)
-- Charset: utf8mb4 (full emoji/unicode support for names, usernames)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Table: users
-- All accounts (hosts and players share this table, distinguished
-- by `role`). A user can be a host of one tournament and a player
-- in another at the same time.
-- ------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50)     NOT NULL,
    email           VARCHAR(100)    NOT NULL,
    password_hash   VARCHAR(255)    NOT NULL,
    role            ENUM('host', 'player', 'both') NOT NULL DEFAULT 'player',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                     ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Table: tournaments
-- One row per tournament. Supports both round_robin and knockout
-- formats — the host picks the format at creation time.
-- rules_json holds host-configurable policy:
--   { "no_show_policy": "walkover" | "void" | "manual",
--     "grace_period_hours": 24,
--     "max_missed_matches": 2 }
-- ------------------------------------------------------------
CREATE TABLE tournaments (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    host_id             INT UNSIGNED    NOT NULL,
    name                VARCHAR(150)    NOT NULL,
    game_type           ENUM('FC Mobile', 'eFootball') NOT NULL,
    format              ENUM('round_robin', 'knockout') NOT NULL,
    description         TEXT            NULL,
    rules_json          JSON            NULL,
    join_code           VARCHAR(10)     NOT NULL,
    max_participants    INT UNSIGNED    NULL,
    start_date          DATE            NULL,
    status              ENUM('draft', 'open_for_registration', 'in_progress', 'completed', 'cancelled')
                                         NOT NULL DEFAULT 'draft',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tournaments_join_code (join_code),
    KEY idx_tournaments_host_id (host_id),
    KEY idx_tournaments_status (status),
    CONSTRAINT fk_tournaments_host
        FOREIGN KEY (host_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Table: participants
-- Join table linking a user to a specific tournament. A single
-- user can appear here multiple times across different tournaments,
-- but only once per tournament (enforced by the unique key).
-- ------------------------------------------------------------
CREATE TABLE participants (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id       INT UNSIGNED    NOT NULL,
    user_id             INT UNSIGNED    NOT NULL,
    joined_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status              ENUM('active', 'disqualified', 'withdrawn') NOT NULL DEFAULT 'active',
    missed_match_count  INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_participants_tournament_user (tournament_id, user_id),
    KEY idx_participants_tournament_id (tournament_id),
    KEY idx_participants_user_id (user_id),
    CONSTRAINT fk_participants_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_participants_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Table: matches
-- One row per fixture. `round` is used by both formats;
-- `bracket_position` is only populated for knockout tournaments
-- (used to determine progression to the next round).
-- player1_id / player2_id are nullable to support byes or
-- "TBD" slots in a knockout bracket before the previous round
-- is decided.
-- ------------------------------------------------------------
CREATE TABLE matches (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id       INT UNSIGNED    NOT NULL,
    round               INT UNSIGNED    NOT NULL,
    bracket_position    INT UNSIGNED    NULL,
    player1_id          INT UNSIGNED    NULL,
    player2_id          INT UNSIGNED    NULL,
    scheduled_deadline  DATETIME        NULL,
    status              ENUM('pending', 'awaiting_confirmation', 'disputed', 'completed', 'forfeited', 'void')
                                         NOT NULL DEFAULT 'pending',
    winner_id           INT UNSIGNED    NULL,
    resolution_type     ENUM('normal', 'walkover', 'no_show_both', 'host_override') NULL,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_matches_tournament_id (tournament_id),
    KEY idx_matches_status (status),
    KEY idx_matches_player1_id (player1_id),
    KEY idx_matches_player2_id (player2_id),
    CONSTRAINT fk_matches_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_matches_player1
        FOREIGN KEY (player1_id) REFERENCES participants(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_matches_player2
        FOREIGN KEY (player2_id) REFERENCES participants(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_matches_winner
        FOREIGN KEY (winner_id) REFERENCES participants(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Table: match_results
-- Submission records for a match. Either player can submit first;
-- the opponent then confirms or disputes. A dispute or unresolved
-- confirmation routes to the host for manual resolution.
-- ------------------------------------------------------------
CREATE TABLE match_results (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    match_id            INT UNSIGNED    NOT NULL,
    submitted_by        INT UNSIGNED    NOT NULL,
    score_player1       INT UNSIGNED    NOT NULL,
    score_player2       INT UNSIGNED    NOT NULL,
    proof_image_path    VARCHAR(255)    NULL,
    status              ENUM('pending_confirmation', 'confirmed', 'disputed', 'host_resolved')
                                         NOT NULL DEFAULT 'pending_confirmation',
    confirmed_by        INT UNSIGNED    NULL,
    reviewed_by         INT UNSIGNED    NULL,
    reviewed_at         TIMESTAMP       NULL,
    submitted_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_match_results_match_id (match_id),
    KEY idx_match_results_status (status),
    CONSTRAINT fk_match_results_match
        FOREIGN KEY (match_id) REFERENCES matches(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_match_results_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_match_results_confirmed_by
        FOREIGN KEY (confirmed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_match_results_reviewed_by
        FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ------------------------------------------------------------
-- Table: standings
-- Cached/computed league table per tournament. Recalculated
-- whenever a match_result is approved. Primarily relevant for
-- round_robin tournaments (knockout progresses via elimination,
-- but a row is still kept here for consistency/history).
-- ------------------------------------------------------------
CREATE TABLE standings (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tournament_id       INT UNSIGNED    NOT NULL,
    participant_id      INT UNSIGNED    NOT NULL,
    played              INT UNSIGNED    NOT NULL DEFAULT 0,
    wins                INT UNSIGNED    NOT NULL DEFAULT 0,
    draws               INT UNSIGNED    NOT NULL DEFAULT 0,
    losses              INT UNSIGNED    NOT NULL DEFAULT 0,
    goals_for           INT UNSIGNED    NOT NULL DEFAULT 0,
    goals_against       INT UNSIGNED    NOT NULL DEFAULT 0,
    goal_difference     INT             GENERATED ALWAYS AS (goals_for - goals_against) STORED,
    points              INT UNSIGNED    NOT NULL DEFAULT 0,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_standings_tournament_participant (tournament_id, participant_id),
    KEY idx_standings_tournament_id (tournament_id),
    CONSTRAINT fk_standings_tournament
        FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_standings_participant
        FOREIGN KEY (participant_id) REFERENCES participants(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
