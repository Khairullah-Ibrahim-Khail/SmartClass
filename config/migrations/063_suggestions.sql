-- Teacher dashboard "Suggestions": a teacher can dismiss a suggestion for 14 days. The suggestions themselves are computed live from the class data (no AI needed).
CREATE TABLE suggestion_dismissals (
  user_id INT UNSIGNED NOT NULL,
  suggestion_key VARCHAR(120) NOT NULL,
  dismissed_until DATETIME NOT NULL,
  PRIMARY KEY (user_id, suggestion_key),
  CONSTRAINT fk_sugg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
