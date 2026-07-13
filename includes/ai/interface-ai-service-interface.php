<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

interface AIServiceInterface {
    /**
     * Generate discount engine recommendation suggestions.
     * Returns an array of recommendation arrays.
     */
    public function get_suggestions(): array;
}
