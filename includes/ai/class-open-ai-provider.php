<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAIProvider implements AIServiceInterface {
    public function get_suggestions(): array {
        // Future integration point for OpenAI API
        return [];
    }
}
