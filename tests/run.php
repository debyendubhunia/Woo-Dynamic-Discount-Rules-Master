<?php
/**
 * Standalone Test Runner for Woo Dynamic Discount Rules Master.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestConditionEvaluator.php';
require_once __DIR__ . '/TestDiscountEngine.php';

$testClasses = [
    'TestConditionEvaluator',
    'TestDiscountEngine'
];

$passedCount = 0;
$failedCount = 0;
$failures = [];

echo "==================================================\n";
echo "Running Woo Dynamic Discount Rules Master Tests...\n";
echo "==================================================\n\n";

foreach ($testClasses as $className) {
    echo "Class: $className\n";
    $instance = new $className();
    $methods = get_class_methods($className);
    
    foreach ($methods as $method) {
        if (strpos($method, 'test') === 0) {
            echo "  Running $method... ";
            
            // Call setup if it exists
            if (method_exists($instance, 'setup')) {
                $instance->setup();
            }
            
            try {
                $instance->$method();
                echo "\033[32mPASS\033[0m\n";
                $passedCount++;
            } catch (Exception $e) {
                echo "\033[31mFAIL\033[0m\n";
                echo "    Error: " . $e->getMessage() . "\n";
                $failedCount++;
                $failures[] = "$className::$method - " . $e->getMessage();
            }
        }
    }
    echo "\n";
}

echo "==================================================\n";
echo "Test Results:\n";
echo "Passed: $passedCount\n";
echo "Failed: $failedCount\n";
echo "==================================================\n";

if ($failedCount > 0) {
    echo "\nFailures details:\n";
    foreach ($failures as $f) {
        echo "- $f\n";
    }
    exit(1);
} else {
    echo "\nAll tests passed successfully!\n";
    exit(0);
}
