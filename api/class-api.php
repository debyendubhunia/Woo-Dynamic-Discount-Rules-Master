<?php
namespace WooDynamicDiscountRulesMaster\API;
class API {
    public function init() {
        register_rest_route('wddrm/v1', '/rules', [
            'methods'             => 'GET',
            'callback'            => [$this, 'get_rules'],
            'permission_callback' => '__return_true',
        ]);
    }
    public function get_rules(\WP_REST_Request $request) {
        return new \WP_REST_Response(['message' => 'API ready'], 200);
    }
}
