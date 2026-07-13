<?php
namespace WooDynamicDiscountRulesMaster;
class Deactivator {
    public static function deactivate() {
        flush_rewrite_rules();
    }
}