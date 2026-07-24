<?php
require_once __DIR__ . '/inc/bootstrap.inc.php';

logout_organizer();
flash(__('logout.done'), 'info');
redirect('index.php');
