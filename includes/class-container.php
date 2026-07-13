<?php
namespace WooDynamicDiscountRulesMaster;
use WooDynamicDiscountRulesMaster\Traits\Singleton;
class Container {
    use Singleton;
    private $services = [];
    private $instances = [];
    private function __construct() {
        $this->services = [
            'database'        => Database::class,
            'admin'           => \WooDynamicDiscountRulesMaster\Admin\Admin::class,
            'frontend'        => \WooDynamicDiscountRulesMaster\Frontend\Frontend::class,
            'rule_repository' => \WooDynamicDiscountRulesMaster\Repository\RuleRepository::class,
            'discount_engine' => \WooDynamicDiscountRulesMaster\DiscountEngine\DiscountEngine::class,
            
            // Subsystem Controllers & Services
            'dashboard_controller'    => \WooDynamicDiscountRulesMaster\Dashboard\DashboardController::class,
            'analytics_repository'    => \WooDynamicDiscountRulesMaster\Dashboard\AnalyticsRepository::class,
            'segmentation_controller' => \WooDynamicDiscountRulesMaster\Segmentation\SegmentationController::class,
            'segment_repository'      => \WooDynamicDiscountRulesMaster\Segmentation\SegmentRepository::class,
            'segmentation_engine'     => \WooDynamicDiscountRulesMaster\Segmentation\SegmentationEngine::class,
            'ai_controller'           => \WooDynamicDiscountRulesMaster\AI\AIController::class,
            'ai_service'              => \WooDynamicDiscountRulesMaster\AI\AIService::class,
            'import_export_controller'=> \WooDynamicDiscountRulesMaster\ImportExport\ImportExportController::class,
        ];
    }
    public function get($id) {
        if (!isset($this->services[$id])) return null;
        if (isset($this->instances[$id])) return $this->instances[$id];
        $class = $this->services[$id];
        // Support both Singleton classes and plain instantiable classes
        $instance = (method_exists($class, 'get_instance') && is_callable([$class, 'get_instance']))
            ? $class::get_instance()
            : new $class();
        if (method_exists($instance, 'set_container')) {
            $instance->set_container($this);
        }
        $this->instances[$id] = $instance;
        return $instance;
    }
}
