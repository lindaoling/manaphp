<?php
/**
 * Created by PhpStorm.
 * User: Mark
 * Date: 2015/12/12
 * Time: 17:44
 */
 
error_reporting(0);

require dirname(__DIR__) . '/ManaPHP/Loader.php';

$loader = new \ManaPHP\Loader();
$loader->registerNamespaces(['Tests' => __DIR__]);