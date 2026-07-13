<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class ClaudeProvider implements AIServiceInterface {
    public function get_suggestions(): array {
        // Future integration point for Anthropic Claude API
        return [];
    }
}
