<?php
// SPDX-License-Identifier: GPL-2.0-or-later
declare(strict_types=1);
require __DIR__ . '/_boot.php';
Auth::logout();
redirect('login.php');
