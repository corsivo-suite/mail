<?php

namespace CorsivoMail\Automation;

if (!defined('ABSPATH')) {
	exit;
}

add_action('init', __NAMESPACE__ . '\\wire_overrides', 20);
