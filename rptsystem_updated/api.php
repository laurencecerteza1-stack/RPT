<?php
// ============================================
// api.php — passthrough entry point
// The real logic now lives in api/router.php, split into
// per-module files under api/. This file exists only so
// existing frontend calls to "api.php" keep working unchanged.
// ============================================
require __DIR__ . '/api/router.php';
