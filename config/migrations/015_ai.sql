-- SmartClass migration 015 — AI providers + usage log (Settings › AI Settings)
-- ai_providers: one row per configured provider. The API key is stored encrypted
--   (encryptSecret, like the SMTP password) and never shown again - key_hint keeps
--   its last 4 characters so teachers can tell keys apart.
-- ai_usage: every AI call (also failed ones) - feature, provider, model, tokens,
--   estimated cost, time - for the usage table and the monthly limits.
-- On/off, default / fallback / per-feature provider and limits live in app_settings.
-- Run ONCE.
--
--   mysql -u USER -p smartclass_db < config/migrations/015_ai.sql

CREATE TABLE ai_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL COMMENT 'registry key in includes/ai.php - openai, anthropic, groq',
    api_key TEXT DEFAULT NULL COMMENT 'encrypted - empty for providers without a key (Ollama)',
    key_hint VARCHAR(8) DEFAULT NULL,
    base_url VARCHAR(255) DEFAULT NULL COMMENT 'NULL = the provider default',
    model VARCHAR(150) DEFAULT NULL COMMENT 'default model for this provider',
    models_cache TEXT DEFAULT NULL COMMENT 'JSON list loaded from the provider models endpoint',
    price_in DECIMAL(10,4) DEFAULT NULL COMMENT 'USD per 1M input tokens - optional, for cost estimates',
    price_out DECIMAL(10,4) DEFAULT NULL COMMENT 'USD per 1M output tokens',
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_usage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED DEFAULT NULL,
    feature VARCHAR(40) NOT NULL,
    provider VARCHAR(30) NOT NULL,
    model VARCHAR(150) DEFAULT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    cost DECIMAL(12,6) DEFAULT NULL COMMENT 'USD estimate - NULL when no prices are set',
    status ENUM('ok', 'error') NOT NULL DEFAULT 'ok',
    error VARCHAR(500) DEFAULT NULL,
    duration_ms INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_month (user_id, created_at),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
