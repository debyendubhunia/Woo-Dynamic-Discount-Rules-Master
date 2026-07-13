<?php
namespace WooDynamicDiscountRulesMaster\Traits;
trait Singleton {
    private static $instance = null;
    final public static function get_instance() {
        if (is_null(static::$instance)) {
            static::$instance = new static();
        }
        return static::$instance;
    }
    private function __construct() {}
    private function __clone() {}
    public function __wakeup() {}
}