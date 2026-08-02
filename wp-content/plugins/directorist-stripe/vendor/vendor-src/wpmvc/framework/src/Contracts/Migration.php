<?php

namespace DirectoristStripe\WpMVC\Contracts;

\defined('ABSPATH') || exit;
interface Migration
{
    public function more_than_version();
    public function execute() : bool;
}
