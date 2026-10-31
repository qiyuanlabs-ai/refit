<?php
/**
 * Plugin Name: Legacy Sample Plugin
 * Version: 1.2.0
 * Requires PHP: 7.2
 * Tested up to: 6.1
 */

require __DIR__ . '/includes/class-exporter.php';

class Legacy_Settings
{
    public $options;

    public function __construct()
    {
        $this->options = array();
        $this->page_title = 'Settings';
        $this->tabs[] = 'general';
    }

    public function render($label = '', $id)
    {
        $filter = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_STRING);
        $name = 'World';
        echo "Hello ${name} {$filter}";
        return create_function('$a', 'return $a * 2;');
    }

    public function save(array $data = null)
    {
        $first = $data{0};
        return implode($data, ',');
    }
}

class Legacy_Widget extends WP_Widget
{
    public function __construct()
    {
        $this->widget_title = 'Legacy';
    }
}

function legacy_next_date($when)
{
    $date = new DateTime();
    if (false === $date->modify($when)) {
        return null;
    }
    return $date;
}

function legacy_safe_date($when)
{
    try {
        return (new DateTimeImmutable())->modify($when);
    } catch (Exception $e) {
        return null;
    }
}
