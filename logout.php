<?php require 'config.php'; if (current_user()) audit('logout', 'users', (int)current_user()['id']); $_SESSION=[]; session_destroy(); redirect('login.php');
