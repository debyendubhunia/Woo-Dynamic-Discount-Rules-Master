<?php
namespace WooDynamicDiscountRulesMaster\AI;

if ( ! defined( 'ABSPATH' ) ) exit;

class AIService implements AIServiceInterface {

    /**
     * @var AIServiceInterface
     */
    private $active_provider;

    public function __construct() {
        $provider_type = get_option( 'wddrm_ai_provider', 'local' );
        
        switch ( $provider_type ) {
            case 'openai':
                $api_key = get_option( 'wddrm_openai_api_key' );
                if ( ! empty( $api_key ) ) {
                    $this->active_provider = new OpenAIProvider();
                    break;
                }
                // Else fall through to local
            case 'claude':
                $api_key = get_option( 'wddrm_claude_api_key' );
                if ( ! empty( $api_key ) ) {
                    $this->active_provider = new ClaudeProvider();
                    break;
                }
                // Else fall through to local
            default:
                $this->active_provider = new LocalRecommendationEngine();
                break;
        }
    }

    public function get_suggestions(): array {
        return $this->active_provider->get_suggestions();
    }
}
